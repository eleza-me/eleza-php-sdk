<?php

declare(strict_types=1);

namespace Eleza\Bot\Keyboard;

/**
 * Buttons under a message.
 *
 *     InlineKeyboard::make()
 *         ->row(Button::callback('تهران', 'city:thr'), Button::callback('شیراز', 'city:syz'))
 *         ->row(Button::url('سایت', 'https://example.com'));
 *
 * Up to 25 rows, 8 buttons per row, 100 buttons in total.
 */
final class InlineKeyboard implements \JsonSerializable
{
    /** @var list<list<Button|array<string,string>>> */
    private array $rows = [];

    public static function make(): self
    {
        return new self();
    }

    /** For editMessage(): takes the inline keyboard of a message away. */
    public static function none(): self
    {
        return new self();
    }

    /** @param Button|array<string,string> ...$buttons */
    public function row(Button|array ...$buttons): self
    {
        if ($buttons !== []) {
            $this->rows[] = array_values($buttons);
        }

        return $this;
    }

    /**
     * Adds buttons, $perRow to a row.
     *
     * @param iterable<Button|array<string,string>> $buttons
     */
    public function grid(iterable $buttons, int $perRow = 2): self
    {
        $row = [];
        foreach ($buttons as $button) {
            $row[] = $button;
            if (count($row) === max(1, $perRow)) {
                $this->rows[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $this->rows[] = $row;
        }

        return $this;
    }

    /** @return array{inline_keyboard:list<list<mixed>>} */
    public function jsonSerialize(): array
    {
        return ['inline_keyboard' => array_map(
            static fn (array $row): array => array_map(static fn ($b) => $b instanceof Button ? $b->jsonSerialize() : $b, $row),
            $this->rows,
        )];
    }
}

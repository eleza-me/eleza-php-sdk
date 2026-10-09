<?php

declare(strict_types=1);

namespace Eleza\Bot\Keyboard;

/**
 * Buttons shown instead of the person's keyboard. Pressing one sends its
 * text as an ordinary message from the person.
 *
 *     ReplyKeyboard::make()->row('امروز', 'این هفته')->row('تنظیمات')->resize();
 *
 * It stays until a later message of the bot replaces it or sends
 * ReplyKeyboard::remove().
 */
final class ReplyKeyboard implements \JsonSerializable
{
    /** @var list<list<array{text:string}>> */
    private array $rows = [];
    /** @var array<string,bool|string> */
    private array $options = [];
    private bool $remove = false;

    public static function make(): self
    {
        return new self();
    }

    /** Takes a reply keyboard shown earlier away. */
    public static function remove(): self
    {
        $k = new self();
        $k->remove = true;

        return $k;
    }

    public function row(string ...$texts): self
    {
        if ($texts !== []) {
            $this->rows[] = array_map(static fn (string $t): array => ['text' => $t], array_values($texts));
        }

        return $this;
    }

    /** Fit the keyboard's height to its rows. */
    public function resize(bool $on = true): self
    {
        return $this->option('resize_keyboard', $on);
    }

    /** Hide after one press. */
    public function oneTime(bool $on = true): self
    {
        return $this->option('one_time_keyboard', $on);
    }

    /** Keep it shown when the system keyboard would hide it. */
    public function persistent(bool $on = true): self
    {
        return $this->option('is_persistent', $on);
    }

    /** Shown in the empty input field. */
    public function placeholder(string $text): self
    {
        return $this->option('input_field_placeholder', $text);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        if ($this->remove) {
            return ['remove_keyboard' => true];
        }

        return ['keyboard' => $this->rows] + $this->options;
    }

    private function option(string $name, bool|string $value): self
    {
        if ($value === false || $value === '') {
            unset($this->options[$name]);
        } else {
            $this->options[$name] = $value;
        }

        return $this;
    }
}

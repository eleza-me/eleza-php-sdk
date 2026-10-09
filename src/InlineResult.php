<?php

declare(strict_types=1);

namespace Eleza\Bot;

use Eleza\Bot\Keyboard\InlineKeyboard;

/**
 * One result of an inline query answer. When the person picks it, its text
 * is sent as their message, marked "via @your_bot".
 */
final class InlineResult implements \JsonSerializable
{
    /** @param array<string,mixed> $fields */
    private function __construct(private array $fields)
    {
    }

    /**
     * @param string $id unique within the answer, 1–64 bytes
     * @param string $title the row in the results list
     * @param string|Text $text what gets sent
     */
    public static function article(string $id, string $title, string|Text $text): self
    {
        $r = new self(['type' => 'article', 'id' => $id, 'title' => $title, 'text' => (string) $text]);
        if ($text instanceof Text && $text->entities() !== []) {
            $r->fields['entities'] = $text->entities();
        }

        return $r;
    }

    /** Second line of the row. */
    public function description(string $description): self
    {
        return $this->with('description', $description);
    }

    /** An https image shown in the results list. */
    public function thumbnail(string $httpsUrl): self
    {
        return $this->with('thumb_url', $httpsUrl);
    }

    /** The URL to show a preview of under the sent message. */
    public function linkPreview(string $url): self
    {
        return $this->with('link_preview', $url);
    }

    /** URL and switch-inline buttons only: nobody could answer a callback button here. */
    public function keyboard(InlineKeyboard $keyboard): self
    {
        return $this->with('reply_markup', $keyboard->jsonSerialize());
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->fields;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->fields;
    }

    private function with(string $key, mixed $value): self
    {
        $this->fields[$key] = $value;

        return $this;
    }
}

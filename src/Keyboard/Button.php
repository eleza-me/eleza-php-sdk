<?php

declare(strict_types=1);

namespace Eleza\Bot\Keyboard;

use Eleza\Bot\Exception\ElezaException;

/** One button of an inline keyboard. It does exactly one thing. */
final class Button implements \JsonSerializable
{
    public const MAX_CALLBACK_DATA_BYTES = 64;

    /** @param array<string,string> $fields */
    private function __construct(private readonly array $fields)
    {
    }

    /**
     * Pressing it sends you a callback_query update with $data.
     *
     * The data never reaches the apps: the server tells you which button was
     * pressed and by whom, so you can trust what you receive.
     *
     * @param string $data 1–64 bytes
     */
    public static function callback(string $text, string $data): self
    {
        if ($data === '' || strlen($data) > self::MAX_CALLBACK_DATA_BYTES) {
            throw new ElezaException('callback_data must be 1 to ' . self::MAX_CALLBACK_DATA_BYTES . ' bytes');
        }

        return new self(['text' => $text, 'callback_data' => $data]);
    }

    /** Opens an http(s) link (after the app asks the person). */
    public static function url(string $text, string $url): self
    {
        return new self(['text' => $text, 'url' => $url]);
    }

    /** Lets the person pick a chat and starts "@your_bot $query" there. */
    public static function switchInline(string $text, string $query = ''): self
    {
        return new self(['text' => $text, 'switch_inline_query' => $query]);
    }

    /** Starts "@your_bot $query" in the chat the button is in. */
    public static function switchInlineCurrentChat(string $text, string $query = ''): self
    {
        return new self(['text' => $text, 'switch_inline_query_current_chat' => $query]);
    }

    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return $this->fields;
    }
}

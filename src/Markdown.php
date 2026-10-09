<?php

declare(strict_types=1);

namespace Eleza\Bot;

/**
 * Helpers for parse_mode "markdown":
 *
 *     *bold*  _italic_  __underline__  ~strikethrough~  ||spoiler||
 *     `code`  ```lang\ncode block```  [text](https://example.com)
 *
 * A backslash makes the next character literal. An unclosed marker is refused
 * by the API (400 bot.parse_error), so escape everything you did not write
 * yourself — or build the message with {@see Text}, which needs no escaping.
 */
final class Markdown
{
    public const PARSE_MODE = 'markdown';

    /** Makes $text literal: no character of it starts formatting. */
    public static function escape(string $text): string
    {
        return addcslashes($text, '\\*_~|`[');
    }

    public static function bold(string $text): string
    {
        return '*' . self::escape($text) . '*';
    }

    public static function italic(string $text): string
    {
        return '_' . self::escape($text) . '_';
    }

    public static function underline(string $text): string
    {
        return '__' . self::escape($text) . '__';
    }

    public static function strikethrough(string $text): string
    {
        return '~' . self::escape($text) . '~';
    }

    public static function spoiler(string $text): string
    {
        return '||' . self::escape($text) . '||';
    }

    public static function link(string $text, string $url): string
    {
        // The link target ends at the first ")".
        return '[' . addcslashes($text, '\\]') . '](' . str_replace([')', ' '], ['%29', '%20'], $url) . ')';
    }
}

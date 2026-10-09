<?php

declare(strict_types=1);

namespace Eleza\Bot;

/**
 * Builds formatted text from pieces, without any escaping to get wrong:
 *
 *     $text = Text::make()->plain('سلام ')->bold($name)->plain("\n")->code($orderId);
 *     $api->sendMessage($chatId, $text);
 *
 * Use it whenever a piece comes from a person or from another system.
 */
final class Text implements \Stringable
{
    private string $text = '';
    private int $length = 0; // in UTF-16 units, as entity offsets are counted
    /** @var list<array<string,mixed>> */
    private array $entities = [];

    public static function make(string $plain = ''): self
    {
        return (new self())->plain($plain);
    }

    public function plain(string $text): self
    {
        $this->text .= $text;
        $this->length += self::utf16Length($text);

        return $this;
    }

    public function bold(string $text): self
    {
        return $this->span('bold', $text);
    }

    public function italic(string $text): self
    {
        return $this->span('italic', $text);
    }

    public function underline(string $text): self
    {
        return $this->span('underline', $text);
    }

    public function strikethrough(string $text): self
    {
        return $this->span('strikethrough', $text);
    }

    /** Hidden until tapped. */
    public function spoiler(string $text): self
    {
        return $this->span('spoiler', $text);
    }

    /** Monospace; a tap copies it. */
    public function code(string $text): self
    {
        return $this->span('code', $text);
    }

    /** A block of code. */
    public function pre(string $text, string $language = ''): self
    {
        return $this->span('pre', $text, $language !== '' ? ['language' => $language] : []);
    }

    /** @param string $url http or https */
    public function link(string $text, string $url): self
    {
        return $this->span('text_link', $text, ['url' => $url]);
    }

    public function newline(int $count = 1): self
    {
        return $this->plain(str_repeat("\n", $count));
    }

    public function text(): string
    {
        return $this->text;
    }

    /** @return list<array{type:string,offset:int,length:int,url?:string,language?:string}> */
    public function entities(): array
    {
        return $this->entities;
    }

    public function __toString(): string
    {
        return $this->text;
    }

    /** Length of $text in UTF-16 code units (how entity offsets are counted). */
    public static function utf16Length(string $text): int
    {
        $n = 0;
        for ($i = 0, $len = strlen($text); $i < $len; $i++) {
            $byte = ord($text[$i]);
            if (($byte & 0xC0) !== 0x80) {
                $n += $byte >= 0xF0 ? 2 : 1; // characters outside the BMP take a surrogate pair
            }
        }

        return $n;
    }

    /** @param array<string,string> $extra */
    private function span(string $type, string $text, array $extra = []): self
    {
        if ($text === '') {
            return $this;
        }
        $offset = $this->length;
        $this->plain($text);
        $this->entities[] = ['type' => $type, 'offset' => $offset, 'length' => $this->length - $offset] + $extra;

        return $this;
    }
}

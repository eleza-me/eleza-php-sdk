<?php

declare(strict_types=1);

namespace Eleza\Bot;

use Eleza\Bot\Exception\ElezaException;

/**
 * A file to upload: from disk or from memory.
 *
 * Always built explicitly, never guessed from a string, so text that comes
 * from a person can never be mistaken for a path on your server.
 */
final class InputFile
{
    private const MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'wav' => 'audio/wav',
        'pdf' => 'application/pdf', 'zip' => 'application/zip', 'txt' => 'text/plain', 'json' => 'application/json',
    ];

    private function __construct(
        private readonly ?string $path,
        private readonly ?string $contents,
        public readonly string $filename,
        public readonly string $mime,
        public readonly int $size,
    ) {
    }

    public static function fromPath(string $path, ?string $filename = null, ?string $mime = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ElezaException('File is not readable: ' . $path);
        }
        $size = filesize($path);
        if ($size === false || $size === 0) {
            throw new ElezaException('File is empty: ' . $path);
        }
        $filename ??= basename($path);
        if ($mime === null && function_exists('mime_content_type')) {
            $detected = @mime_content_type($path);
            $mime = is_string($detected) && $detected !== '' ? $detected : null;
        }

        return new self($path, null, $filename, $mime ?? self::mimeOf($filename), $size);
    }

    /** @param string $contents the raw bytes */
    public static function fromString(string $contents, string $filename, ?string $mime = null): self
    {
        if ($contents === '') {
            throw new ElezaException('File contents are empty');
        }

        return new self(null, $contents, $filename, $mime ?? self::mimeOf($filename), strlen($contents));
    }

    /** Bytes [$offset, $offset + $length) of the file. */
    public function read(int $offset, int $length): string
    {
        if ($this->contents !== null) {
            return substr($this->contents, $offset, $length);
        }
        $data = @file_get_contents((string) $this->path, false, null, $offset, $length);
        if ($data === false) {
            throw new ElezaException('Could not read ' . $this->path);
        }

        return $data;
    }

    private static function mimeOf(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::MIME_BY_EXTENSION[$ext] ?? 'application/octet-stream';
    }
}

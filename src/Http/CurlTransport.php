<?php

declare(strict_types=1);

namespace Eleza\Bot\Http;

use Eleza\Bot\Exception\TransportException;

/** The default transport: one reused cURL handle (keep-alive between calls). */
final class CurlTransport implements Transport
{
    private ?\CurlHandle $handle = null;

    /**
     * @param float $connectTimeout seconds to establish the connection
     * @param string|null $proxy e.g. "http://127.0.0.1:8080" or "socks5h://127.0.0.1:1080"
     */
    public function __construct(
        private readonly float $connectTimeout = 10.0,
        private readonly ?string $proxy = null,
    ) {
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 30.0): Response
    {
        $ch = $this->handle ??= curl_init();
        curl_reset($ch);

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $lines[] = 'Expect:'; // no "100-continue" round trip on uploads

        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($this->proxy !== null) {
            $options[CURLOPT_PROXY] = $this->proxy;
        }
        curl_setopt_array($ch, $options);

        $result = curl_exec($ch);
        if ($result === false) {
            $message = curl_error($ch);
            $code = curl_errno($ch);
            // A handle that failed may hold a dead connection: start clean next time.
            $this->handle = null;
            throw new TransportException(sprintf('%s %s failed: %s', $method, self::withoutQuery($url), $message), $code);
        }

        return new Response((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $result, $responseHeaders);
    }

    /** Upload URLs are signed in their query string: keep that out of error messages. */
    private static function withoutQuery(string $url): string
    {
        $q = strpos($url, '?');

        return $q === false ? $url : substr($url, 0, $q);
    }
}

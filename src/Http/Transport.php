<?php

declare(strict_types=1);

namespace Eleza\Bot\Http;

use Eleza\Bot\Exception\TransportException;

/** Sends one HTTP request. Replace it to use your own HTTP client, or in tests. */
interface Transport
{
    /**
     * @param array<string,string> $headers
     * @param float $timeout seconds for the whole request
     *
     * @throws TransportException when no HTTP answer was received
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 30.0): Response;
}

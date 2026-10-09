<?php

declare(strict_types=1);

namespace Eleza\Bot\Tests;

use Eleza\Bot\Exception\TransportException;
use Eleza\Bot\Http\Response;
use Eleza\Bot\Http\Transport;

/** Answers from a queue and records what was sent. */
final class FakeTransport implements Transport
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string,timeout:float}> */
    public array $requests = [];
    /** @var list<Response|\Throwable> */
    private array $queue = [];

    public function json(int $status, array $body = [], array $headers = []): self
    {
        $this->queue[] = new Response($status, $body === [] && $status === 204 ? '' : json_encode($body, JSON_UNESCAPED_UNICODE), $headers);

        return $this;
    }

    public function problem(int $status, string $code, bool $retryable = false, int $retryAfter = 0): self
    {
        return $this->json($status, ['type' => 'https://errors.eleza.app/' . $code, 'code' => $code, 'title' => 'x', 'status' => $status,
            'request_id' => 'req-1', 'retryable' => $retryable] + ($retryAfter ? ['retry_after' => $retryAfter] : []));
    }

    public function fail(string $message = 'connection reset'): self
    {
        $this->queue[] = new TransportException($message);

        return $this;
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 30.0): Response
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'timeout');
        $next = array_shift($this->queue) ?? throw new \LogicException("No answer queued for $method $url");
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /** @return array<string,mixed> */
    public function sent(int $index = -1): array
    {
        $r = $index < 0 ? $this->requests[count($this->requests) + $index] : $this->requests[$index];

        return $r + ['json' => $r['body'] !== null && $r['body'] !== '' ? json_decode($r['body'], true) : null];
    }
}

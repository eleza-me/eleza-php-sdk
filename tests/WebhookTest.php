<?php

declare(strict_types=1);

namespace Eleza\Bot\Tests;

use Eleza\Bot\Exception\WebhookException;
use Eleza\Bot\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_0123456789abcdef';
    private const BODY = '{"update_id":1791000000123,"type":"message.created","occurred_at":"2026-10-03T08:00:00.000Z","bot_id":"u1","v":1,"data":{"chat_id":"c1"}}';

    public function testSignatureMatchesTheDocumentedScheme(): void
    {
        // HMAC-SHA256(secret, "<timestamp>.<delivery id>.<raw body>"), hex, prefixed with "v1=".
        $want = 'v1=' . hash_hmac('sha256', '1791000000.1791000000123.' . self::BODY, self::SECRET);
        self::assertSame($want, Webhook::sign(self::SECRET, 1791000000, '1791000000123', self::BODY));
    }

    public function testVerify(): void
    {
        $now = 1791000100;
        $sig = Webhook::sign(self::SECRET, $now, '1791000000123', self::BODY);

        self::assertTrue(Webhook::verify(self::SECRET, $sig, (string) $now, '1791000000123', self::BODY, now: $now + 299));
        self::assertFalse(Webhook::verify(self::SECRET, $sig, (string) $now, '1791000000123', self::BODY, now: $now + 301), 'too old');
        self::assertFalse(Webhook::verify(self::SECRET, $sig, (string) $now, '1791000000123', self::BODY, now: $now - 301), 'from the future');
        self::assertFalse(Webhook::verify(self::SECRET, $sig, (string) $now, '1791000000124', self::BODY, now: $now), 'another delivery');
        self::assertFalse(Webhook::verify(self::SECRET, $sig, (string) $now, '1791000000123', self::BODY . ' ', now: $now), 'body changed');
        self::assertFalse(Webhook::verify('another-secret-0123', $sig, (string) $now, '1791000000123', self::BODY, now: $now));
        self::assertFalse(Webhook::verify(self::SECRET, $sig, 'abc', '1791000000123', self::BODY, now: $now));
        self::assertFalse(Webhook::verify(self::SECRET, '', (string) $now, '1791000000123', self::BODY, now: $now));
    }

    public function testParse(): void
    {
        $ts = (string) time();
        $headers = [
            'X-ELEZA-SIGNATURE' => Webhook::sign(self::SECRET, $ts, '1791000000123', self::BODY),
            'x-eleza-timestamp' => $ts,
            'X-Eleza-Delivery-Id' => ['1791000000123'],
        ];
        $update = Webhook::parse(self::SECRET, self::BODY, $headers);
        self::assertSame(1791000000123, $update->id);
        self::assertSame('c1', $update->chatId());

        $this->expectException(WebhookException::class);
        Webhook::parse(self::SECRET, self::BODY, ['X-Eleza-Timestamp' => $ts]);
    }

    public function testHeadersFromGlobals(): void
    {
        $_SERVER['HTTP_X_ELEZA_SIGNATURE'] = 'v1=abc';
        self::assertSame('v1=abc', Webhook::headersFromGlobals()['x-eleza-signature']);
        unset($_SERVER['HTTP_X_ELEZA_SIGNATURE']);
    }
}

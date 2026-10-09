<?php

declare(strict_types=1);

namespace Eleza\Bot;

use Eleza\Bot\Exception\WebhookException;

/**
 * Checks that a webhook delivery really comes from Eleza.
 *
 * Every delivery is signed: HMAC-SHA256 over "<timestamp>.<delivery id>.<raw body>"
 * with the webhook secret. The timestamp is part of what is signed, so an old
 * delivery cannot be replayed.
 */
final class Webhook
{
    public const HEADER_SIGNATURE = 'X-Eleza-Signature';
    public const HEADER_TIMESTAMP = 'X-Eleza-Timestamp';
    /** The update id; the same on every retry of a delivery. */
    public const HEADER_DELIVERY_ID = 'X-Eleza-Delivery-Id';
    public const HEADER_ATTEMPT = 'X-Eleza-Delivery-Attempt';
    public const HEADER_BOT_ID = 'X-Eleza-Bot-Id';

    /** Deliveries older (or newer) than this many seconds are refused. */
    public const DEFAULT_TOLERANCE = 300;

    public static function sign(#[\SensitiveParameter] string $secret, int|string $timestamp, string $deliveryId, string $rawBody): string
    {
        return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $deliveryId . '.' . $rawBody, $secret);
    }

    /**
     * @param string $rawBody the request body exactly as received (not re-encoded)
     * @param int|null $now for tests
     */
    public static function verify(
        #[\SensitiveParameter] string $secret,
        string $signature,
        string $timestamp,
        string $deliveryId,
        string $rawBody,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): bool {
        if ($secret === '' || $signature === '' || $deliveryId === '' || !ctype_digit($timestamp)) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals(self::sign($secret, $timestamp, $deliveryId, $rawBody), $signature);
    }

    /**
     * Verifies a delivery and returns its update.
     *
     * @param array<string,string|list<string>> $headers the request headers, in any letter case
     *
     * @throws WebhookException when the delivery is not authentic or not an update
     */
    public static function parse(#[\SensitiveParameter] string $secret, string $rawBody, array $headers, int $tolerance = self::DEFAULT_TOLERANCE): Update
    {
        $h = [];
        foreach ($headers as $name => $value) {
            $h[strtolower((string) $name)] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }
        $ok = self::verify(
            $secret,
            $h[strtolower(self::HEADER_SIGNATURE)] ?? '',
            $h[strtolower(self::HEADER_TIMESTAMP)] ?? '',
            $h[strtolower(self::HEADER_DELIVERY_ID)] ?? '',
            $rawBody,
            $tolerance,
        );
        if (!$ok) {
            throw new WebhookException('The webhook signature is missing, wrong or too old');
        }
        try {
            $update = Update::fromJson($rawBody);
        } catch (\Throwable $e) {
            throw new WebhookException('The webhook body is not an update', 0, $e);
        }
        if ((string) $update->id !== $h[strtolower(self::HEADER_DELIVERY_ID)]) {
            throw new WebhookException('The delivery id does not match the update');
        }

        return $update;
    }

    /**
     * The headers of the current request, from $_SERVER.
     *
     * @return array<string,string>
     */
    public static function headersFromGlobals(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[str_replace('_', '-', strtolower(substr($key, 5)))] = $value;
            }
        }

        return $headers;
    }
}

<?php

declare(strict_types=1);

namespace Eleza\Bot\Exception;

/**
 * The API answered with an error (application/problem+json).
 *
 * Branch on {@see ApiException::$errorCode}: codes are stable, titles are for people.
 */
class ApiException extends ElezaException
{
    public const UNAUTHORIZED = 'bot.unauthorized';
    public const SUSPENDED = 'bot.suspended';
    public const SWITCHED_OFF = 'bot.switched_off';
    public const DISABLED = 'bot.disabled';
    public const SCOPE_MISSING = 'bot.scope_missing';
    public const CHAT_NOT_FOUND = 'chat.not_found';
    public const USER_NOT_STARTED = 'bot.user_not_started';
    public const CHAT_BLOCKED = 'chat.blocked';
    public const POLL_CONFLICT = 'bot.poll_conflict';
    public const WEBHOOK_ACTIVE = 'bot.webhook_active';
    public const RATE_LIMITED = 'rate_limited';
    public const PARSE_ERROR = 'bot.parse_error';
    public const MARKUP_INVALID = 'bot.markup_invalid';
    public const MEDIA_NOT_READY = 'media.not_ready';
    public const CALLBACK_EXPIRED = 'bot.callback_query_expired';
    public const INLINE_QUERY_EXPIRED = 'bot.inline_query_expired';
    public const POST_IN_PROGRESS = 'bot.post_in_progress';
    public const POSTS_DISABLED = 'bot.posts_disabled';

    public function __construct(
        /** Stable machine code, e.g. "bot.user_not_started". */
        public readonly string $errorCode,
        /** Human text, in the language asked for with the "language" option. */
        public readonly string $title,
        /** HTTP status. */
        public readonly int $status,
        /** The same request may succeed later. */
        public readonly bool $retryable = false,
        /** Seconds to wait before trying again (rate limiting), 0 when not given. */
        public readonly int $retryAfter = 0,
        /** Quote this when reporting a problem. */
        public readonly string $requestId = '',
    ) {
        parent::__construct(sprintf('%s (%d): %s', $errorCode, $status, $title), $status);
    }

    public function is(string ...$codes): bool
    {
        return in_array($this->errorCode, $codes, true);
    }

    /** The person has not started the bot, or has blocked it: nothing to send to for now. */
    public function isCannotWrite(): bool
    {
        return $this->is(self::USER_NOT_STARTED, self::CHAT_BLOCKED, self::CHAT_NOT_FOUND);
    }
}

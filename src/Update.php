<?php

declare(strict_types=1);

namespace Eleza\Bot;

use Eleza\Bot\Exception\ElezaException;

/**
 * One update, as long polling returns it and a webhook receives it.
 *
 * Delivery is at least once: the same id may arrive twice, so handle updates
 * idempotently (or remember the ids you have seen). Updates of one chat
 * arrive in order; there is no order across chats.
 */
final class Update
{
    /**
     * @param int $id increasing per bot
     * @param string $type one of UpdateType
     * @param string $occurredAt RFC 3339, UTC
     * @param string $botId the bot that receives it
     * @param array<string,mixed> $data the payload, by type
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $occurredAt,
        public readonly string $botId,
        public readonly array $data,
        public readonly int $version = 1,
    ) {
    }

    /** @param array<string,mixed> $u the envelope */
    public static function fromArray(array $u): self
    {
        if (!isset($u['update_id'], $u['type'])) {
            throw new ElezaException('Not an update: update_id or type is missing');
        }

        return new self(
            (int) $u['update_id'],
            (string) $u['type'],
            (string) ($u['occurred_at'] ?? ''),
            (string) ($u['bot_id'] ?? ''),
            is_array($u['data'] ?? null) ? $u['data'] : [],
            (int) ($u['v'] ?? 1),
        );
    }

    public static function fromJson(string $json): self
    {
        $u = json_decode($json, true);
        if (!is_array($u)) {
            throw new ElezaException('Not an update: the body is not a JSON object');
        }

        return self::fromArray($u);
    }

    public function is(string ...$types): bool
    {
        return in_array($this->type, $types, true);
    }

    /** The chat it happened in; null for inline queries and Eli updates. */
    public function chatId(): ?string
    {
        return isset($this->data['chat_id']) ? (string) $this->data['chat_id'] : null;
    }

    /**
     * Who did it. "handle" and "name" are present only with the profile:read scope.
     *
     * @return array{id:string,handle?:string,name?:string}|null
     */
    public function from(): ?array
    {
        return is_array($this->data['from'] ?? null) ? $this->data['from'] : null;
    }

    public function fromId(): ?string
    {
        return isset($this->data['from']['id']) ? (string) $this->data['from']['id'] : null;
    }

    /** The person's name, or their handle, or "" when the bot may not read profiles. */
    public function fromName(): string
    {
        return (string) ($this->data['from']['name'] ?? $this->data['from']['handle'] ?? '');
    }

    /**
     * The message: the one sent or edited, or the one whose button was pressed.
     *
     * @return array{seq:int,kind:string,text?:string,attachment?:string,reply_to_seq?:int,created_at:int,...}|null
     */
    public function message(): ?array
    {
        return is_array($this->data['message'] ?? null) ? $this->data['message'] : null;
    }

    /** Text of the message (the caption for media); "" when there is none. */
    public function text(): string
    {
        return (string) ($this->data['message']['text'] ?? '');
    }

    /** Seq of the message the update is about. */
    public function seq(): ?int
    {
        $seq = $this->data['message']['seq'] ?? $this->data['seq'] ?? null;

        return $seq === null ? null : (int) $seq;
    }

    /** The command without its slash, lower-case ("start" for user.started_bot). */
    public function command(): ?string
    {
        return isset($this->data['command']) ? (string) $this->data['command'] : null;
    }

    /** The text after the command. */
    public function args(): string
    {
        return (string) ($this->data['args'] ?? '');
    }

    /** user.started_bot: the deep-link parameter of eleza.me/<handle>?start=<payload>. */
    public function payload(): string
    {
        return (string) ($this->data['payload'] ?? '');
    }

    /** user.started_bot: this is the person's first message to the bot. */
    public function isFirstStart(): bool
    {
        return (bool) ($this->data['first'] ?? false);
    }

    /** Id of the callback query or inline query to answer. */
    public function queryId(): ?string
    {
        return $this->is(UpdateType::CALLBACK_QUERY, UpdateType::INLINE_QUERY) && isset($this->data['id']) ? (string) $this->data['id'] : null;
    }

    /** callback_query: the callback_data of the pressed button. */
    public function callbackData(): string
    {
        return $this->type === UpdateType::CALLBACK_QUERY ? (string) ($this->data['data'] ?? '') : '';
    }

    /** inline_query: what the person typed after @your_bot. */
    public function query(): string
    {
        return (string) ($this->data['query'] ?? '');
    }

    /** inline_query: the next_offset of your previous answer, when the person scrolls. */
    public function offset(): string
    {
        return (string) ($this->data['offset'] ?? '');
    }

    /** reaction.created: the emoji. */
    public function emoji(): string
    {
        return (string) ($this->data['emoji'] ?? '');
    }

    /** Eli updates: the bot's post it is about. */
    public function postId(): ?string
    {
        return isset($this->data['post_id']) ? (string) $this->data['post_id'] : null;
    }

    /** post.reply_created: the reply. @return array<string,mixed>|null */
    public function reply(): ?array
    {
        return is_array($this->data['reply'] ?? null) ? $this->data['reply'] : null;
    }

    /** post.reaction_changed: like | dislike | none. */
    public function reaction(): string
    {
        return (string) ($this->data['reaction'] ?? '');
    }

    /** post.reaction_changed: what it was before. */
    public function previousReaction(): string
    {
        return (string) ($this->data['previous'] ?? '');
    }
}

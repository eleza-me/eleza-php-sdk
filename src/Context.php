<?php

declare(strict_types=1);

namespace Eleza\Bot;

use Eleza\Bot\Exception\ElezaException;

/**
 * What a handler receives: the update, and shortcuts that act on it.
 *
 *     $bot->onText(fn (Context $ctx) => $ctx->reply('شما گفتید: ' . $ctx->text()));
 */
final class Context
{
    public function __construct(
        public readonly Update $update,
        public readonly Client $api,
    ) {
    }

    public function chatId(): ?string
    {
        return $this->update->chatId();
    }

    /** @return array{id:string,handle?:string,name?:string}|null */
    public function from(): ?array
    {
        return $this->update->from();
    }

    public function fromId(): ?string
    {
        return $this->update->fromId();
    }

    public function fromName(): string
    {
        return $this->update->fromName();
    }

    /** @return array<string,mixed>|null */
    public function message(): ?array
    {
        return $this->update->message();
    }

    public function text(): string
    {
        return $this->update->text();
    }

    public function command(): ?string
    {
        return $this->update->command();
    }

    public function args(): string
    {
        return $this->update->args();
    }

    public function payload(): string
    {
        return $this->update->payload();
    }

    public function callbackData(): string
    {
        return $this->update->callbackData();
    }

    public function query(): string
    {
        return $this->update->query();
    }

    /**
     * Sends a message to the chat of this update.
     *
     * @param array<string,mixed> $options as Client::sendMessage()
     * @return array{chat_id:string,message_id:string,seq:int,created_at:int,duplicate?:bool}
     */
    public function reply(string|Text $text, array $options = []): array
    {
        return $this->api->sendMessage($this->requireChat(), $text, $options);
    }

    /**
     * The same, as a reply to the message of this update.
     *
     * @param array<string,mixed> $options
     */
    public function replyQuoting(string|Text $text, array $options = []): array
    {
        $seq = $this->update->seq();

        return $this->reply($text, ($seq !== null ? ['reply_to_seq' => $seq] : []) + $options);
    }

    /** @param array<string,mixed> $options */
    public function replyWithPhoto(string|InputFile $photo, array $options = []): array
    {
        return $this->api->sendPhoto($this->requireChat(), $photo, $options);
    }

    /** @param array<string,mixed> $options */
    public function replyWithFile(string|InputFile $file, array $options = []): array
    {
        return $this->api->sendFile($this->requireChat(), $file, $options);
    }

    /** Shows "typing…" (or another ChatAction) in the chat of this update. */
    public function typing(string $action = ChatAction::TYPING): void
    {
        $this->api->sendChatAction($this->requireChat(), $action);
    }

    /** Reacts to the message of this update. */
    public function react(string $emoji): void
    {
        $this->api->setReaction($this->requireChat(), $this->requireSeq(), $emoji);
    }

    /**
     * callback_query: answers the press (a toast, a dialog with $alert, or
     * nothing at all — the button stops spinning either way).
     */
    public function answer(?string $text = null, bool $alert = false, ?string $url = null): bool
    {
        if ($this->update->type !== UpdateType::CALLBACK_QUERY) {
            throw new ElezaException('answer() is for callback_query updates; this one is ' . $this->update->type);
        }

        return $this->api->answerCallbackQuery((string) $this->update->queryId(), $text, $alert, $url);
    }

    /**
     * callback_query: edits the message the pressed button is on.
     *
     * @param array<string,mixed> $options as Client::editMessage()
     */
    public function editMessage(string|Text|null $text = null, array $options = []): array
    {
        return $this->api->editMessage($this->requireChat(), $this->requireSeq(), $text, $options);
    }

    /**
     * inline_query: answers it.
     *
     * @param list<array<string,mixed>|InlineResult> $results
     */
    public function answerInline(array $results, int $cacheTime = 0, bool $isPersonal = false, string $nextOffset = ''): void
    {
        if ($this->update->type !== UpdateType::INLINE_QUERY) {
            throw new ElezaException('answerInline() is for inline_query updates; this one is ' . $this->update->type);
        }
        $this->api->answerInlineQuery((string) $this->update->queryId(), $results, $cacheTime, $isPersonal, $nextOffset);
    }

    private function requireChat(): string
    {
        return $this->update->chatId() ?? throw new ElezaException('A ' . $this->update->type . ' update has no chat to answer in');
    }

    private function requireSeq(): int
    {
        return $this->update->seq() ?? throw new ElezaException('A ' . $this->update->type . ' update has no message');
    }
}

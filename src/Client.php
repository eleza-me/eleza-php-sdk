<?php

declare(strict_types=1);

namespace Eleza\Bot;

use Eleza\Bot\Exception\ApiException;
use Eleza\Bot\Exception\ElezaException;
use Eleza\Bot\Exception\TransportException;
use Eleza\Bot\Http\CurlTransport;
use Eleza\Bot\Http\Response;
use Eleza\Bot\Http\Transport;

/**
 * The Eleza Bot API (v1), one method per route.
 *
 *     $api = new Client(getenv('ELEZA_BOT_TOKEN'));
 *     $api->sendMessage($chatId, 'سلام!');
 *
 * Ids of chats, people, media and posts are opaque strings; a message inside
 * a chat is addressed by its integer "seq".
 */
class Client
{
    public const VERSION = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://api.eleza.me';

    /** Message kind => kind of the upload behind it. */
    private const UPLOAD_KIND = [
        'photo' => 'image', 'video' => 'video', 'file' => 'file', 'voice' => 'voice',
        'audio' => 'audio', 'gif' => 'gif', 'video_note' => 'video_note',
    ];

    private readonly string $token;
    private readonly string $baseUrl;
    private readonly float $timeout;
    private readonly int $maxRetries;
    private readonly float $mediaReadyTimeout;
    private readonly ?string $language;
    private readonly string $userAgent;
    private readonly Transport $transport;
    /** @var callable(float):void */
    private $sleeper;

    /**
     * @param string $token the token BotFather gave you ("ezb1.…")
     * @param array{
     *     base_url?: string,
     *     timeout?: float,
     *     max_retries?: int,
     *     media_ready_timeout?: float,
     *     language?: string,
     *     user_agent?: string,
     *     transport?: Transport,
     *     sleeper?: callable(float):void,
     * } $options
     *   - timeout: seconds per request (default 30)
     *   - max_retries: extra attempts after a rate limit, a 5xx or a network error (default 3)
     *   - media_ready_timeout: seconds to wait for uploaded media to finish processing when sending it (default 60)
     *   - language: "fa" or "en", the language of error titles
     */
    public function __construct(#[\SensitiveParameter] string $token, array $options = [])
    {
        $token = trim($token);
        if ($token === '') {
            throw new ElezaException('The bot token is empty. Get one from @BotFather in Eleza.');
        }
        $this->token = $token;
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = (float) ($options['timeout'] ?? 30.0);
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? 3));
        $this->mediaReadyTimeout = (float) ($options['media_ready_timeout'] ?? 60.0);
        $this->language = $options['language'] ?? null;
        $this->userAgent = $options['user_agent'] ?? ('eleza-bot-sdk-php/' . self::VERSION . ' PHP/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
        $this->transport = $options['transport'] ?? new CurlTransport();
        $this->sleeper = $options['sleeper'] ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    // ------------------------------------------------------------------ bot

    /**
     * The bot this token belongs to.
     *
     * @return array{id:string,handle:string,name:string,is_bot:bool,scopes:list<string>,link:string,...}
     */
    public function getMe(): array
    {
        return $this->request('GET', '/bot/v1/me');
    }

    // ------------------------------------------------------------- messages

    /**
     * Sends a text message. $text is a string, or a {@see Text} that carries its own formatting.
     *
     * Options (all optional):
     *   - parse_mode: "markdown" ({@see Markdown})
     *   - entities: [{type, offset, length, url?, language?}] with UTF-16 offsets, instead of parse_mode
     *   - reply_to_seq: seq of the message to reply to
     *   - reply_markup: an InlineKeyboard, a ReplyKeyboard or an array
     *   - link_preview: the URL to show a preview of
     *   - client_message_id: a UUID that makes a retried send safe; generated when you leave it out
     *
     * @param array<string,mixed> $options
     * @return array{chat_id:string,message_id:string,seq:int,created_at:int,duplicate?:bool}
     */
    public function sendMessage(string $chatId, string|Text $text, array $options = []): array
    {
        return $this->send($chatId, ['kind' => 'text', 'text' => $text] + $options);
    }

    /**
     * Sends a message of any kind. Prefer sendMessage() / sendPhoto() / …;
     * this is the route itself.
     *
     * @param array<string,mixed> $message kind, text, attachment, sticker, parse_mode, entities, reply_to_seq,
     *                                     reply_markup, link_preview, media_spoiler, audio, client_message_id
     * @return array{chat_id:string,message_id:string,seq:int,created_at:int,duplicate?:bool}
     */
    public function send(string $chatId, array $message): array
    {
        $message['client_message_id'] ??= self::uuid();
        if (($message['text'] ?? null) instanceof Text) {
            $entities = $message['text']->entities();
            $message['text'] = $message['text']->text();
            if ($entities !== []) {
                $message['entities'] = $entities;
            }
        }
        if (array_key_exists('reply_markup', $message)) {
            $message['reply_markup'] = self::markup($message['reply_markup']);
        }
        $message = array_filter($message, static fn ($v) => $v !== null);
        $path = '/bot/v1/chats/' . rawurlencode($chatId) . '/messages';

        $hasMedia = isset($message['attachment']);
        $deadline = microtime(true) + $this->mediaReadyTimeout;
        $wait = 1.0;
        while (true) {
            try {
                // The client_message_id makes a repeated send return the first message.
                return $this->request('POST', $path, body: $message, idempotent: true);
            } catch (ApiException $e) {
                // Media that was just uploaded is still being processed.
                if (!$hasMedia || !$e->is(ApiException::MEDIA_NOT_READY) || microtime(true) + $wait > $deadline) {
                    throw $e;
                }
                ($this->sleeper)($wait);
                $wait = min($wait * 1.5, 5.0);
            }
        }
    }

    /**
     * @param string|InputFile $photo a media id from uploadFile(), or a file to upload now
     * @param array<string,mixed> $options "text" is the caption; also media_spoiler and everything sendMessage() takes
     */
    public function sendPhoto(string $chatId, string|InputFile $photo, array $options = []): array
    {
        return $this->sendMedia($chatId, 'photo', $photo, $options);
    }

    /** @param array<string,mixed> $options */
    public function sendVideo(string $chatId, string|InputFile $video, array $options = []): array
    {
        return $this->sendMedia($chatId, 'video', $video, $options);
    }

    /** A document of any type. @param array<string,mixed> $options */
    public function sendFile(string $chatId, string|InputFile $file, array $options = []): array
    {
        return $this->sendMedia($chatId, 'file', $file, $options);
    }

    /** A voice message. @param array<string,mixed> $options */
    public function sendVoice(string $chatId, string|InputFile $voice, array $options = []): array
    {
        return $this->sendMedia($chatId, 'voice', $voice, $options);
    }

    /**
     * Music, shown with a player.
     *
     * @param array<string,mixed> $options also "audio" => ['title' => …, 'performer' => …]
     */
    public function sendAudio(string $chatId, string|InputFile $audio, array $options = []): array
    {
        return $this->sendMedia($chatId, 'audio', $audio, $options);
    }

    /** @param array<string,mixed> $options */
    public function sendGif(string $chatId, string|InputFile $gif, array $options = []): array
    {
        return $this->sendMedia($chatId, 'gif', $gif, $options);
    }

    /** A round video message. @param array<string,mixed> $options */
    public function sendVideoNote(string $chatId, string|InputFile $videoNote, array $options = []): array
    {
        return $this->sendMedia($chatId, 'video_note', $videoNote, $options);
    }

    /**
     * @param string $stickerId id of a sticker of a published pack
     * @param array<string,mixed> $options
     */
    public function sendSticker(string $chatId, string $stickerId, array $options = []): array
    {
        return $this->send($chatId, ['kind' => 'sticker', 'sticker' => $stickerId] + $options);
    }

    /**
     * Sends media of the given message kind (photo, video, file, voice, audio, gif, video_note).
     *
     * @param array<string,mixed> $options
     */
    public function sendMedia(string $chatId, string $kind, string|InputFile $media, array $options = []): array
    {
        if (!isset(self::UPLOAD_KIND[$kind])) {
            throw new ElezaException('Unknown media kind: ' . $kind);
        }
        if ($media instanceof InputFile) {
            $media = $this->uploadFile($media, self::UPLOAD_KIND[$kind]);
        }

        return $this->send($chatId, ['kind' => $kind, 'attachment' => $media] + $options);
    }

    /**
     * Edits one of the bot's own messages: its text, its inline keyboard, or both.
     *
     * @param string|Text|null $text null keeps the text
     * @param array<string,mixed> $options parse_mode, entities, link_preview, reply_markup
     *                                     (an inline keyboard; InlineKeyboard::none() removes it)
     * @return array<string,mixed> the message as it is now
     */
    public function editMessage(string $chatId, int $seq, string|Text|null $text = null, array $options = []): array
    {
        $body = $options;
        if ($text instanceof Text) {
            $body['text'] = $text->text();
            if ($text->entities() !== []) {
                $body['entities'] = $text->entities();
            }
        } elseif ($text !== null) {
            $body['text'] = $text;
        }
        if (array_key_exists('reply_markup', $body)) {
            $body['reply_markup'] = self::markup($body['reply_markup']);
        }
        if (!isset($body['text']) && !array_key_exists('reply_markup', $body)) {
            throw new ElezaException('editMessage() needs a new text, a new reply_markup, or both');
        }

        return $this->request('PATCH', $this->messagePath($chatId, $seq), body: $body, idempotent: true);
    }

    /** Replaces (or, with InlineKeyboard::none(), removes) the inline keyboard of the bot's own message. */
    public function editReplyMarkup(string $chatId, int $seq, mixed $replyMarkup): array
    {
        return $this->editMessage($chatId, $seq, null, ['reply_markup' => $replyMarkup]);
    }

    /** Deletes one of the bot's own messages. */
    public function deleteMessage(string $chatId, int $seq): void
    {
        $this->request('DELETE', $this->messagePath($chatId, $seq));
    }

    /** Reacts to a message with one emoji (replaces the bot's earlier reaction). */
    public function setReaction(string $chatId, int $seq, string $emoji): void
    {
        $this->request('PUT', $this->messagePath($chatId, $seq) . '/reaction', body: ['emoji' => $emoji]);
    }

    public function removeReaction(string $chatId, int $seq): void
    {
        $this->request('DELETE', $this->messagePath($chatId, $seq) . '/reaction');
    }

    /**
     * Shows what the bot is doing for a few seconds.
     *
     * @param string $action one of the ChatAction constants ("typing", "uploading_photo", …, "cancel")
     */
    public function sendChatAction(string $chatId, string $action = ChatAction::TYPING): void
    {
        $this->request('POST', '/bot/v1/chats/' . rawurlencode($chatId) . '/action', body: ['action' => $action]);
    }

    /**
     * A chat of the bot.
     *
     * @return array{id:string,type:string,state:string,user:array{id:string,handle?:string,name?:string},started:bool,can_send:bool,last_seq:int}
     */
    public function getChat(string $chatId): array
    {
        return $this->request('GET', '/bot/v1/chats/' . rawurlencode($chatId));
    }

    // -------------------------------------------------------------- updates

    /**
     * Long polling. Returns the updates that are waiting; with $timeout > 0
     * the call waits up to that many seconds for the first one.
     *
     * $offset confirms (deletes) every update with an id below it, so pass
     * "last update id + 1" once you have handled a batch. Updates that were
     * returned but not confirmed are returned again.
     *
     * @param int $limit 1–100
     * @param int $timeout seconds, 0–50
     * @param list<string>|null $allowedUpdates stores which types the bot wants ([] = all); null leaves it as it is
     * @return list<Update>
     */
    public function getUpdates(int $offset = 0, int $limit = 100, int $timeout = 0, ?array $allowedUpdates = null): array
    {
        $query = ['limit' => $limit, 'timeout' => $timeout];
        if ($offset > 0) {
            $query['offset'] = $offset;
        }
        if ($allowedUpdates !== null) {
            $query['allowed_updates'] = implode(',', $allowedUpdates);
        }
        $out = $this->request('GET', '/bot/v1/updates', $query, timeout: $timeout + max(10.0, $this->timeout));

        return array_map(static fn (array $u): Update => Update::fromArray($u), $out['updates'] ?? []);
    }

    /**
     * Has Eleza POST every update to $url instead of long polling.
     *
     * The URL must be HTTPS on port 443 or 8443 at a public address.
     *
     * @param array{secret?:string,max_connections?:int,allowed_updates?:list<string>,drop_pending_updates?:bool} $options
     *   secret: 16–256 characters of A-Z a-z 0-9 _ -; when left out one is generated
     * @return array<string,mixed> the webhook state; "secret" is present only when it was generated now. Store it.
     */
    public function setWebhook(string $url, array $options = []): array
    {
        return $this->request('PUT', '/bot/v1/webhook', body: ['url' => $url] + $options);
    }

    /**
     * Webhook configuration and delivery health.
     *
     * @return array{url:string,pending_update_count:int,last_error?:string,last_error_status?:int,next_retry_at?:int,...}
     */
    public function getWebhookInfo(): array
    {
        return $this->request('GET', '/bot/v1/webhook');
    }

    /** Removes the webhook: back to long polling. */
    public function deleteWebhook(bool $dropPendingUpdates = false): void
    {
        $this->request('DELETE', '/bot/v1/webhook', $dropPendingUpdates ? ['drop_pending_updates' => 'true'] : []);
    }

    // ------------------------------------------------------------- commands

    /**
     * Replaces the list shown in the app's command menu.
     *
     * @param array<string,string>|list<array{command:string,description:string}> $commands
     *        ['today' => "Today's weather", 'help' => 'Help'] or the API's own shape
     * @param string|null $language e.g. "fa" or "en" for a list in one language; null for every language
     */
    public function setCommands(array $commands, ?string $language = null): void
    {
        $list = [];
        foreach ($commands as $key => $value) {
            $list[] = is_array($value) ? $value : ['command' => ltrim((string) $key, '/'), 'description' => (string) $value];
        }
        $body = ['commands' => $list];
        if ($language !== null) {
            $body['language'] = $language;
        }
        $this->request('PUT', '/bot/v1/commands', body: $body);
    }

    /** @return list<array{command:string,description:string}> */
    public function getCommands(?string $language = null): array
    {
        $out = $this->request('GET', '/bot/v1/commands', $language !== null ? ['language' => $language] : []);

        return $out['commands'] ?? [];
    }

    public function deleteCommands(?string $language = null): void
    {
        $this->request('DELETE', '/bot/v1/commands', $language !== null ? ['language' => $language] : []);
    }

    // ---------------------------------------------------- callbacks, inline

    /**
     * Answers a button press. Call it for every callback_query within 10
     * seconds, even with no arguments, so the button stops spinning.
     *
     * @param string|null $text a short notice
     * @param bool $alert show $text in a dialog instead of a toast
     * @param string|null $url open this URL instead
     * @return bool false when the person was no longer waiting for the answer
     */
    public function answerCallbackQuery(string $queryId, ?string $text = null, bool $alert = false, ?string $url = null): bool
    {
        $body = array_filter(['text' => $text, 'alert' => $alert ?: null, 'url' => $url], static fn ($v) => $v !== null);
        $out = $this->request('POST', '/bot/v1/callback-queries/' . rawurlencode($queryId) . '/answer', body: $body);

        return (bool) ($out['delivered'] ?? false);
    }

    /**
     * Answers an inline query (within 8 seconds).
     *
     * @param list<array<string,mixed>|InlineResult> $results up to 50 ({@see InlineResult::article()})
     * @param int $cacheTime seconds the answer may be reused for the same query
     * @param bool $isPersonal cache per person instead of for everyone
     * @param string $nextOffset comes back as "offset" when the person scrolls; "" = no more
     */
    public function answerInlineQuery(string $queryId, array $results, int $cacheTime = 0, bool $isPersonal = false, string $nextOffset = ''): void
    {
        $body = ['results' => array_map(static fn ($r) => $r instanceof InlineResult ? $r->toArray() : $r, array_values($results))];
        if ($cacheTime > 0) {
            $body['cache_time'] = $cacheTime;
        }
        if ($isPersonal) {
            $body['is_personal'] = true;
        }
        if ($nextOffset !== '') {
            $body['next_offset'] = $nextOffset;
        }
        $this->request('POST', '/bot/v1/inline-queries/' . rawurlencode($queryId) . '/answer', body: $body);
    }

    // ------------------------------------------------------------ Eli posts

    /**
     * Publishes an Eli post as the bot.
     *
     * $post keys:
     *   - text: the text, or the caption of the media
     *   - media: [['id' => $mediaId, 'alt' => '…'], …] — upload with purpose "post"
     *   - poll: ['question' => '…', 'options' => ['…', '…'], 'duration' => '24h']
     *   - reply_policy: everyone (default) | followers | following | mentioned
     *   - client_post_id: makes a retried call safe for 24 hours; generated when you leave it out
     *
     * @param array<string,mixed> $post
     * @return array{id:string,created_at?:int,limited?:bool,duplicate?:bool}
     *         "limited": the bot has no official mark, so the post reaches followers and the profile only
     */
    public function createPost(array $post): array
    {
        $post['client_post_id'] ??= self::uuid();

        return $this->request('POST', '/bot/v1/posts', body: $post, idempotent: true);
    }

    /** A text-only Eli post. */
    public function createTextPost(string $text, array $options = []): array
    {
        return $this->createPost(['text' => $text] + $options);
    }

    public function deletePost(string $postId): void
    {
        $this->request('DELETE', '/bot/v1/posts/' . rawurlencode($postId));
    }

    // ---------------------------------------------------------------- media

    /**
     * Uploads a file and returns its media id, ready to be sent as an
     * attachment (chat) or listed in a post's media (purpose "post").
     *
     * @param string $kind what the file is: image, video, video_note, voice, audio, gif or file
     *                     (the kind of an upload: a "photo" message carries an "image")
     * @param string $purpose "chat" (default) or "post"
     */
    public function uploadFile(InputFile $file, string $kind = 'file', string $purpose = 'chat'): string
    {
        $upload = $this->createUpload([
            'purpose' => $purpose, 'kind' => $kind, 'size' => $file->size, 'mime' => $file->mime, 'filename' => $file->filename,
        ]);
        $uploadId = (string) $upload['upload_id'];
        try {
            $partSize = (int) ($upload['part_size'] ?? 0);
            foreach ($upload['parts'] ?? [] as $part) {
                $number = (int) $part['n'];
                $offset = ($number - 1) * $partSize;
                $this->putPart((string) $part['url'], $file->read($offset, $partSize > 0 ? $partSize : $file->size));
            }
            if (($upload['state'] ?? '') !== 'ready') {
                $upload = $this->completeUpload($uploadId);
            }
        } catch (\Throwable $e) {
            try {
                $this->abortUpload($uploadId);
            } catch (\Throwable) {
                // The session expires on its own.
            }
            throw $e;
        }

        return (string) $upload['media_id'];
    }

    /**
     * Starts a resumable upload. uploadFile() does all the steps for you.
     *
     * @param array{purpose:string,kind:string,size:int,mime:string,filename?:string} $upload
     * @return array{upload_id:string,media_id:string,part_size:int,parts:list<array{n:int,url:string}>,state:string,expires_at:int,...}
     */
    public function createUpload(array $upload): array
    {
        return $this->request('POST', '/bot/v1/uploads', body: $upload);
    }

    /** The parts already uploaded and fresh URLs for the rest. */
    public function getUpload(string $uploadId): array
    {
        return $this->request('GET', '/bot/v1/uploads/' . rawurlencode($uploadId));
    }

    /** Finishes an upload once every part is stored. Safe to repeat. */
    public function completeUpload(string $uploadId): array
    {
        return $this->request('POST', '/bot/v1/uploads/' . rawurlencode($uploadId) . '/complete', idempotent: true, timeout: max(150.0, $this->timeout));
    }

    public function abortUpload(string $uploadId): void
    {
        $this->request('DELETE', '/bot/v1/uploads/' . rawurlencode($uploadId));
    }

    /**
     * State and metadata of media this bot uploaded.
     *
     * @param string|null $variant when given and the media is ready, the answer carries a temporary "url" for it
     * @return array{id:string,state:string,kind:string,width?:int,height?:int,duration_ms?:int,url?:string,url_expires_at?:int,...}
     */
    public function getMedia(string $mediaId, ?string $variant = null): array
    {
        return $this->request('GET', '/bot/v1/media/' . rawurlencode($mediaId), $variant !== null ? ['variant' => $variant] : []);
    }

    // ------------------------------------------------------------ internals

    /**
     * Calls a route of the API. Public so that a route newer than this SDK
     * can be reached without waiting for a release.
     *
     * @param array<string,scalar> $query
     * @param array<string,mixed>|null $body JSON body
     * @param bool $idempotent repeating the call cannot do the thing twice (GET, PUT and DELETE always count as such)
     * @return array<string,mixed> the decoded answer ([] for an empty one)
     *
     * @throws ApiException the API refused the call
     * @throws TransportException no answer was received
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, bool $idempotent = false, ?float $timeout = null): array
    {
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $headers = [
            'Authorization' => 'Bot ' . $this->token,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];
        if ($this->language !== null) {
            $headers['Accept-Language'] = $this->language;
        }
        $payload = null;
        if ($body !== null) {
            $payload = $body === [] ? '{}' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = 'application/json';
        } elseif ($method === 'POST') {
            $payload = '';
        }
        $idempotent = $idempotent || $method !== 'POST';

        for ($attempt = 0; ; $attempt++) {
            $last = $attempt >= $this->maxRetries;
            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $timeout ?? $this->timeout);
            } catch (TransportException $e) {
                if ($last || !$idempotent) {
                    throw $e;
                }
                ($this->sleeper)(self::backoff($attempt));
                continue;
            }
            if ($response->status >= 200 && $response->status < 300) {
                return self::decode($response);
            }
            $error = self::error($response);
            $retry = $error->status === 429 || $error->retryable || ($error->status >= 500 && $idempotent);
            // Not ready yet / still running: the caller decides how long to wait for those.
            if ($last || !$retry || $error->is(ApiException::MEDIA_NOT_READY, ApiException::POLL_CONFLICT)) {
                throw $error;
            }
            ($this->sleeper)($error->retryAfter > 0 ? (float) $error->retryAfter : self::backoff($attempt));
        }
    }

    /** Stores one part of an upload. The URL is signed: the bot token is never sent to it. */
    private function putPart(string $url, string $bytes): void
    {
        for ($attempt = 0; ; $attempt++) {
            $last = $attempt >= $this->maxRetries;
            try {
                $response = $this->transport->send('PUT', $url, ['Content-Type' => 'application/octet-stream'], $bytes, max(120.0, $this->timeout));
            } catch (TransportException $e) {
                if ($last) {
                    throw $e;
                }
                ($this->sleeper)(self::backoff($attempt));
                continue;
            }
            if ($response->status >= 200 && $response->status < 300) {
                return;
            }
            if ($last || $response->status < 500) {
                throw new ApiException('upload.part_failed', 'Storing a part of the upload failed', $response->status, $response->status >= 500);
            }
            ($this->sleeper)(self::backoff($attempt));
        }
    }

    private function messagePath(string $chatId, int $seq): string
    {
        return '/bot/v1/chats/' . rawurlencode($chatId) . '/messages/' . $seq;
    }

    /** A keyboard object or array as the JSON object the API takes. */
    private static function markup(mixed $markup): mixed
    {
        if ($markup instanceof \JsonSerializable) {
            $markup = $markup->jsonSerialize();
        }
        if ($markup === []) {
            return new \stdClass();
        }

        return $markup;
    }

    /** @return array<string,mixed> */
    private static function decode(Response $response): array
    {
        if (trim($response->body) === '') {
            return [];
        }
        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            throw new ElezaException('The API answered ' . $response->status . ' with a body that is not JSON');
        }

        return $data;
    }

    private static function error(Response $response): ApiException
    {
        $p = json_decode($response->body, true);
        if (!is_array($p) || !isset($p['code'])) {
            // Not from the API itself: a proxy or a gateway in between.
            return new ApiException('http.' . $response->status, 'Unexpected HTTP ' . $response->status, $response->status, $response->status >= 500,
                (int) ($response->header('Retry-After') ?? 0));
        }

        return new ApiException(
            (string) $p['code'],
            (string) ($p['title'] ?? ''),
            (int) ($p['status'] ?? $response->status),
            (bool) ($p['retryable'] ?? false),
            (int) ($p['retry_after'] ?? $response->header('Retry-After') ?? 0),
            (string) ($p['request_id'] ?? ''),
        );
    }

    private static function backoff(int $attempt): float
    {
        return min(0.5 * (2 ** $attempt), 8.0) + random_int(0, 250) / 1000;
    }

    /** A random UUID (version 4). */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** Keeps the token out of var_dump() and error reports. */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'token' => '***'];
    }
}

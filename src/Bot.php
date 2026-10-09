<?php

declare(strict_types=1);

namespace Eleza\Bot;

use Eleza\Bot\Exception\ApiException;
use Eleza\Bot\Exception\ElezaException;
use Eleza\Bot\Exception\WebhookException;

/**
 * Routes updates to your handlers, from long polling or from a webhook.
 *
 *     $bot = new Bot(getenv('ELEZA_BOT_TOKEN'));
 *     $bot->onStart(fn (Context $ctx) => $ctx->reply('سلام!'));
 *     $bot->onCommand('help', fn (Context $ctx) => $ctx->reply('…'));
 *     $bot->onText(fn (Context $ctx) => $ctx->reply($ctx->text()));
 *     $bot->run();
 *
 * The first handler that matches an update handles it. An exception thrown
 * by a handler goes to onError() and the bot carries on with the next update.
 */
class Bot
{
    public readonly Client $api;

    /** @var list<array{0: callable(Update):bool, 1: callable(Context):mixed}> */
    private array $routes = [];
    /** @var (callable(Context):mixed)|null */
    private $fallback = null;
    /** @var callable(\Throwable, ?Update):void */
    private $errorHandler;
    private bool $running = false;

    /**
     * @param string|Client $token the bot token, or a Client you configured yourself
     * @param array<string,mixed> $options as Client::__construct(), when a token is given
     */
    public function __construct(#[\SensitiveParameter] string|Client $token, array $options = [])
    {
        $this->api = $token instanceof Client ? $token : new Client($token, $options);
        $this->errorHandler = static function (\Throwable $e, ?Update $update): void {
            error_log(sprintf('[eleza-bot] %s%s: %s', $update !== null ? 'update ' . $update->id . ' (' . $update->type . '), ' : '', $e::class, $e->getMessage()));
        };
    }

    // ------------------------------------------------------------- handlers

    /**
     * Handles updates of the given type(s) (UpdateType constants).
     *
     * @param string|list<string> $types
     * @param callable(Context):mixed $handler
     */
    public function on(string|array $types, callable $handler): static
    {
        $types = (array) $types;

        return $this->route(static fn (Update $u): bool => in_array($u->type, $types, true), $handler);
    }

    /**
     * The person pressed Start (or sent /start). $ctx->payload() is the
     * deep-link parameter of eleza.me/<handle>?start=<payload>.
     *
     * @param callable(Context):mixed $handler
     */
    public function onStart(callable $handler): static
    {
        return $this->on(UpdateType::USER_STARTED_BOT, $handler);
    }

    /**
     * A command: onCommand('help', …) handles "/help" and "/help@your_bot".
     * $ctx->args() is the text after it.
     *
     * @param string|list<string> $commands without or with the slash
     * @param callable(Context):mixed $handler
     */
    public function onCommand(string|array $commands, callable $handler): static
    {
        $names = array_map(static fn (string $c): string => strtolower(ltrim($c, '/')), (array) $commands);

        return $this->route(
            static fn (Update $u): bool => $u->is(UpdateType::COMMAND_RECEIVED, UpdateType::USER_STARTED_BOT) && in_array($u->command(), $names, true),
            $handler,
        );
    }

    /**
     * Any new message that is not a command: text, photo, voice, …
     *
     * @param callable(Context):mixed $handler
     */
    public function onMessage(callable $handler): static
    {
        return $this->on(UpdateType::MESSAGE_CREATED, $handler);
    }

    /**
     * A new text message that is not a command.
     *
     * @param callable(Context):mixed $handler
     * @param string|null $pattern a regular expression ('/^\d+$/') the text must match; null = any text
     */
    public function onText(callable $handler, ?string $pattern = null): static
    {
        return $this->route(
            static fn (Update $u): bool => $u->type === UpdateType::MESSAGE_CREATED
                && ($u->message()['kind'] ?? '') === 'text'
                && ($pattern === null || preg_match($pattern, $u->text()) === 1),
            $handler,
        );
    }

    /**
     * A new message of the given kind(s): photo, video, file, voice, audio, gif, video_note, sticker, location, …
     *
     * @param string|list<string> $kinds
     * @param callable(Context):mixed $handler
     */
    public function onKind(string|array $kinds, callable $handler): static
    {
        $kinds = (array) $kinds;

        return $this->route(
            static fn (Update $u): bool => $u->type === UpdateType::MESSAGE_CREATED && in_array($u->message()['kind'] ?? '', $kinds, true),
            $handler,
        );
    }

    /**
     * A press on an inline button.
     *
     * @param callable(Context):mixed $handler
     * @param string|null $data which buttons: the exact callback_data, or a prefix ending in "*" ('city:*'); null = all
     */
    public function onCallback(callable $handler, ?string $data = null): static
    {
        return $this->route(
            static function (Update $u) use ($data): bool {
                if ($u->type !== UpdateType::CALLBACK_QUERY) {
                    return false;
                }
                if ($data === null) {
                    return true;
                }

                return str_ends_with($data, '*') ? str_starts_with($u->callbackData(), substr($data, 0, -1)) : $u->callbackData() === $data;
            },
            $handler,
        );
    }

    /**
     * Someone typed "@your_bot …" in a chat (turn inline mode on in BotFather).
     *
     * @param callable(Context):mixed $handler
     */
    public function onInlineQuery(callable $handler): static
    {
        return $this->on(UpdateType::INLINE_QUERY, $handler);
    }

    /**
     * Handles every update no other handler took.
     *
     * @param callable(Context):mixed $handler
     */
    public function fallback(callable $handler): static
    {
        $this->fallback = $handler;

        return $this;
    }

    /**
     * Called with what a handler (or, while polling, the network) threw.
     * The default writes a line to the PHP error log.
     *
     * @param callable(\Throwable, ?Update):void $handler
     */
    public function onError(callable $handler): static
    {
        $this->errorHandler = $handler;

        return $this;
    }

    /**
     * Handles updates a test of yours decides on.
     *
     * @param callable(Update):bool $matches
     * @param callable(Context):mixed $handler
     */
    public function route(callable $matches, callable $handler): static
    {
        $this->routes[] = [$matches, $handler];

        return $this;
    }

    // ------------------------------------------------------------- dispatch

    /**
     * Runs the handler of one update. Returns whether a handler took it.
     * Never throws: errors go to onError().
     */
    public function handle(Update $update): bool
    {
        try {
            foreach ($this->routes as [$matches, $handler]) {
                if ($matches($update)) {
                    $handler(new Context($update, $this->api));

                    return true;
                }
            }
            if ($this->fallback !== null) {
                ($this->fallback)(new Context($update, $this->api));

                return true;
            }
        } catch (\Throwable $e) {
            $this->report($e, $update);

            return true;
        }

        return false;
    }

    // --------------------------------------------------------- long polling

    /**
     * Receives updates by long polling until stop() is called (or the
     * process gets SIGINT / SIGTERM, when the pcntl extension is loaded).
     *
     * Run it from the command line, one process per bot: a second poll of
     * the same bot ends the first with bot.poll_conflict.
     *
     * @param array{timeout?:int,limit?:int,allowed_updates?:list<string>,drop_pending_updates?:bool} $options
     *   - timeout: seconds one poll waits for updates (default 30, at most 50)
     *   - limit: updates per poll (default 100)
     *   - allowed_updates: the only types to receive from now on ([] = all)
     *   - drop_pending_updates: skip what queued up while the bot was not running
     *
     * @throws ApiException when polling cannot work: a bad token, a webhook that is set, another poller
     */
    public function run(array $options = []): void
    {
        $timeout = max(0, min(50, (int) ($options['timeout'] ?? 30)));
        $limit = max(1, min(100, (int) ($options['limit'] ?? 100)));
        $allowed = $options['allowed_updates'] ?? null;

        $this->running = true;
        $this->installSignalHandlers();

        $offset = 0;
        if ($options['drop_pending_updates'] ?? false) {
            $offset = $this->skipPending();
        }

        while ($this->running) {
            try {
                $updates = $this->api->getUpdates($offset, $limit, $timeout, $allowed);
                $allowed = null; // stored by the first call
            } catch (ApiException $e) {
                if ($e->status < 500 && $e->status !== 429) {
                    $this->running = false;
                    throw $e; // nothing a retry would fix
                }
                $this->report($e, null);
                $this->pause(3);
                continue;
            } catch (ElezaException $e) {
                $this->report($e, null);
                $this->pause(3);
                continue;
            }
            foreach ($updates as $update) {
                $this->handle($update);
                // Confirmed by the next poll: an update is gone only after its handler returned.
                $offset = $update->id + 1;
            }
        }

        if ($offset > 0) {
            try {
                $this->api->getUpdates($offset, 1, 0); // confirm what was handled before leaving
            } catch (\Throwable) {
                // They come again on the next start; handlers must be idempotent anyway.
            }
        }
    }

    /** Ends run() after the poll that is in flight. */
    public function stop(): void
    {
        $this->running = false;
    }

    // -------------------------------------------------------------- webhook

    /**
     * Verifies one webhook delivery and runs its handler.
     *
     * @param string $secret the webhook secret (from setWebhook)
     * @param string $rawBody the request body exactly as received
     * @param array<string,string|list<string>> $headers the request headers
     *
     * @throws WebhookException when the delivery is not authentic: answer 401 and do nothing
     */
    public function handleWebhook(#[\SensitiveParameter] string $secret, string $rawBody, array $headers): Update
    {
        $update = Webhook::parse($secret, $rawBody, $headers);
        $this->handle($update);

        return $update;
    }

    /**
     * A whole webhook endpoint for plain PHP (php-fpm, mod_php): reads the
     * current request, verifies it, runs the handler and writes the answer.
     *
     *     // hook.php
     *     $bot->serveWebhook(getenv('ELEZA_WEBHOOK_SECRET'));
     *
     * Eleza waits 10 seconds for a 2xx. With $answerFirst the 200 is sent
     * before your handler runs (php-fpm only), for handlers that take longer.
     */
    public function serveWebhook(#[\SensitiveParameter] string $secret, bool $answerFirst = false): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');

            return;
        }
        try {
            $update = Webhook::parse($secret, (string) file_get_contents('php://input'), Webhook::headersFromGlobals());
        } catch (WebhookException) {
            http_response_code(401);

            return;
        }
        if ($answerFirst && function_exists('fastcgi_finish_request')) {
            http_response_code(200);
            fastcgi_finish_request();
            $this->handle($update);

            return;
        }
        $this->handle($update);
        http_response_code(200);
    }

    // ------------------------------------------------------------ internals

    /** Confirms everything that is queued and returns the offset to continue from. */
    private function skipPending(): int
    {
        $offset = 0;
        do {
            $updates = $this->api->getUpdates($offset, 100, 0);
            if ($updates !== []) {
                $offset = end($updates)->id + 1;
            }
        } while ($updates !== []);

        return $offset;
    }

    private function report(\Throwable $e, ?Update $update): void
    {
        try {
            ($this->errorHandler)($e, $update);
        } catch (\Throwable) {
            // An error handler that fails must not take the bot down.
        }
    }

    private function pause(int $seconds): void
    {
        for ($i = 0; $i < $seconds * 10 && $this->running; $i++) {
            usleep(100_000);
        }
    }

    private function installSignalHandlers(): void
    {
        if (PHP_SAPI !== 'cli' || !function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        $stop = function (): void {
            $this->stop();
        };
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }
}

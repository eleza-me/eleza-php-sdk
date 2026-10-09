<?php

declare(strict_types=1);

namespace Eleza\Bot\Tests;

use Eleza\Bot\Bot;
use Eleza\Bot\Client;
use Eleza\Bot\Context;
use Eleza\Bot\Exception\ApiException;
use Eleza\Bot\Markdown;
use Eleza\Bot\Update;
use Eleza\Bot\UpdateType;
use Eleza\Bot\Webhook;
use PHPUnit\Framework\TestCase;

final class BotTest extends TestCase
{
    private FakeTransport $http;

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
    }

    private function bot(): Bot
    {
        return new Bot(new Client('ezb1.bot.key.secret', ['transport' => $this->http, 'sleeper' => static fn () => null]));
    }

    private static function update(string $type, array $data, int $id = 1): Update
    {
        return new Update($id, $type, '2026-10-03T08:00:00.000Z', 'u1', $data);
    }

    public function testRouting(): void
    {
        $seen = [];
        $bot = $this->bot()
            ->onStart(function (Context $c) use (&$seen) { $seen[] = 'start:' . $c->payload(); })
            ->onCommand('/Help', function (Context $c) use (&$seen) { $seen[] = 'help:' . $c->args(); })
            ->onText(function () use (&$seen) { $seen[] = 'digits'; }, '/^\d+$/')
            ->onText(function (Context $c) use (&$seen) { $seen[] = 'text:' . $c->text(); })
            ->onKind('photo', function () use (&$seen) { $seen[] = 'photo'; })
            ->onCallback(function (Context $c) use (&$seen) { $seen[] = 'city:' . $c->callbackData(); }, 'city:*')
            ->onCallback(function () use (&$seen) { $seen[] = 'ok'; }, 'ok')
            ->onInlineQuery(function (Context $c) use (&$seen) { $seen[] = 'inline:' . $c->query(); })
            ->fallback(function (Context $c) use (&$seen) { $seen[] = 'other:' . $c->update->type; });

        $msg = static fn (string $kind, string $text = '') => ['chat_id' => 'c1', 'from' => ['id' => 'u7'], 'message' => ['seq' => 1, 'kind' => $kind, 'text' => $text]];
        $bot->handle(self::update(UpdateType::USER_STARTED_BOT, $msg('text', '/start promo') + ['command' => 'start', 'payload' => 'promo']));
        $bot->handle(self::update(UpdateType::COMMAND_RECEIVED, $msg('text', '/help me') + ['command' => 'help', 'args' => 'me']));
        $bot->handle(self::update(UpdateType::MESSAGE_CREATED, $msg('text', '42')));
        $bot->handle(self::update(UpdateType::MESSAGE_CREATED, $msg('text', 'hello')));
        $bot->handle(self::update(UpdateType::MESSAGE_CREATED, $msg('photo', 'caption')));
        $bot->handle(self::update(UpdateType::CALLBACK_QUERY, ['id' => 'q', 'chat_id' => 'c1', 'data' => 'city:thr']));
        $bot->handle(self::update(UpdateType::CALLBACK_QUERY, ['id' => 'q', 'chat_id' => 'c1', 'data' => 'ok']));
        $bot->handle(self::update(UpdateType::CALLBACK_QUERY, ['id' => 'q', 'chat_id' => 'c1', 'data' => 'okay']));
        $bot->handle(self::update(UpdateType::INLINE_QUERY, ['id' => 'q', 'query' => 'tehran']));
        $bot->handle(self::update('something.new', []));

        self::assertSame(['start:promo', 'help:me', 'digits', 'text:hello', 'photo', 'city:city:thr', 'ok', 'other:callback_query',
            'inline:tehran', 'other:something.new'], $seen);
    }

    public function testUnhandledUpdateIsIgnored(): void
    {
        self::assertFalse($this->bot()->handle(self::update('something.new', [])));
    }

    public function testHandlerErrorsGoToOnError(): void
    {
        $errors = [];
        $bot = $this->bot()
            ->onMessage(function () { throw new \RuntimeException('boom'); })
            ->onError(function (\Throwable $e, ?Update $u) use (&$errors) { $errors[] = $e->getMessage() . '@' . $u?->id; });
        self::assertTrue($bot->handle(self::update(UpdateType::MESSAGE_CREATED, [], 5)));
        self::assertSame(['boom@5'], $errors);
    }

    public function testContextShortcuts(): void
    {
        $this->http->json(200, ['seq' => 2])->json(200, ['delivered' => true])->json(200, ['seq' => 1]);
        $bot = $this->bot()
            ->onText(fn (Context $c) => $c->replyQuoting('pong'))
            ->onCallback(function (Context $c) {
                $c->answer('done');
                $c->editMessage('picked ' . $c->callbackData());
            });
        $bot->handle(self::update(UpdateType::MESSAGE_CREATED, ['chat_id' => 'c1', 'message' => ['seq' => 4, 'kind' => 'text', 'text' => 'ping']]));
        self::assertSame(4, $this->http->sent()['json']['reply_to_seq']);

        $bot->handle(self::update(UpdateType::CALLBACK_QUERY, ['id' => 'q9', 'chat_id' => 'c1', 'data' => 'a', 'message' => ['seq' => 1]]));
        self::assertStringEndsWith('/bot/v1/callback-queries/q9/answer', $this->http->sent(1)['url']);
        self::assertSame('PATCH', $this->http->sent(2)['method']);
        self::assertStringEndsWith('/bot/v1/chats/c1/messages/1', $this->http->sent(2)['url']);
    }

    public function testRunConfirmsHandledUpdatesAndStops(): void
    {
        $u = static fn (int $id) => ['update_id' => $id, 'type' => 'message.created', 'data' => ['chat_id' => 'c1', 'message' => ['seq' => $id, 'kind' => 'text', 'text' => 't']]];
        $this->http
            ->json(200, ['updates' => [$u(10), $u(11)]])
            ->problem(503, 'service.unavailable', true) // retried by the client
            ->json(200, ['updates' => [$u(12)]])
            ->json(200, ['updates' => []]); // the confirmation on the way out

        $handled = [];
        $bot = $this->bot();
        $bot->onText(function (Context $c) use (&$handled, $bot) {
            $handled[] = $c->update->id;
            if ($c->update->id === 12) {
                $bot->stop();
            }
        });
        $bot->run(['timeout' => 20, 'allowed_updates' => ['message.created']]);

        self::assertSame([10, 11, 12], $handled);
        self::assertStringContainsString('allowed_updates=message.created', $this->http->sent(0)['url']);
        self::assertStringNotContainsString('offset=', $this->http->sent(0)['url']);
        self::assertStringContainsString('offset=12', $this->http->sent(1)['url']);
        self::assertStringNotContainsString('allowed_updates', $this->http->sent(1)['url']);
        self::assertStringContainsString('offset=13', $this->http->sent(3)['url']);
        self::assertStringContainsString('timeout=0', $this->http->sent(3)['url']);
    }

    public function testRunGivesUpOnErrorsARetryCannotFix(): void
    {
        $this->http->problem(409, 'bot.webhook_active');
        try {
            $this->bot()->run();
            self::fail('expected an ApiException');
        } catch (ApiException $e) {
            self::assertTrue($e->is(ApiException::WEBHOOK_ACTIVE));
        }
    }

    public function testDropPendingUpdates(): void
    {
        $this->http
            ->json(200, ['updates' => [['update_id' => 5, 'type' => 'message.created', 'data' => []]]])
            ->json(200, ['updates' => []])
            ->problem(401, 'bot.unauthorized');
        $handled = 0;
        $bot = $this->bot()->fallback(function () use (&$handled) { $handled++; });
        try {
            $bot->run(['drop_pending_updates' => true]);
        } catch (ApiException) {
        }
        self::assertSame(0, $handled);
        self::assertStringContainsString('offset=6', $this->http->sent(1)['url']);
        self::assertStringContainsString('offset=6', $this->http->sent(2)['url']);
    }

    public function testHandleWebhook(): void
    {
        $secret = 'whsec_0123456789abcdef';
        $body = '{"update_id":77,"type":"bot.blocked","data":{"chat_id":"c1","from":{"id":"u7"}}}';
        $ts = (string) time();
        $seen = null;
        $bot = $this->bot()->on(UpdateType::BOT_BLOCKED, function (Context $c) use (&$seen) { $seen = $c->fromId(); });
        $update = $bot->handleWebhook($secret, $body, [
            Webhook::HEADER_SIGNATURE => Webhook::sign($secret, $ts, '77', $body),
            Webhook::HEADER_TIMESTAMP => $ts,
            Webhook::HEADER_DELIVERY_ID => '77',
        ]);
        self::assertSame(77, $update->id);
        self::assertSame('u7', $seen);
    }

    public function testMarkdownEscape(): void
    {
        self::assertSame('a\\*b\\_c\\~d\\|\\|e\\`f\\[g](h) \\\\', Markdown::escape('a*b_c~d||e`f[g](h) \\'));
        self::assertSame('*1\\*2*', Markdown::bold('1*2'));
        self::assertSame('[a\\]b](https://e.com/x_(y%29)', Markdown::link('a]b', 'https://e.com/x_(y)'));
    }
}

<?php

declare(strict_types=1);

namespace Eleza\Bot\Tests;

use Eleza\Bot\Client;
use Eleza\Bot\Exception\ApiException;
use Eleza\Bot\Exception\ElezaException;
use Eleza\Bot\Exception\TransportException;
use Eleza\Bot\InlineResult;
use Eleza\Bot\InputFile;
use Eleza\Bot\Keyboard\Button;
use Eleza\Bot\Keyboard\InlineKeyboard;
use Eleza\Bot\Keyboard\ReplyKeyboard;
use Eleza\Bot\Text;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private FakeTransport $http;
    /** @var list<float> */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
        $this->slept = [];
    }

    private function client(array $options = []): Client
    {
        return new Client('ezb1.bot.key.secret', $options + [
            'transport' => $this->http,
            'sleeper' => function (float $s): void {
                $this->slept[] = $s;
            },
        ]);
    }

    public function testGetMeSendsTheTokenAndDecodes(): void
    {
        $this->http->json(200, ['id' => 'u1', 'handle' => 'my_bot', 'is_bot' => true]);
        $me = $this->client(['language' => 'en'])->getMe();

        self::assertSame('my_bot', $me['handle']);
        $r = $this->http->sent();
        self::assertSame('GET', $r['method']);
        self::assertSame('https://api.eleza.me/bot/v1/me', $r['url']);
        self::assertSame('Bot ezb1.bot.key.secret', $r['headers']['Authorization']);
        self::assertSame('en', $r['headers']['Accept-Language']);
        self::assertNull($r['body']);
    }

    public function testEmptyTokenIsRefused(): void
    {
        $this->expectException(ElezaException::class);
        new Client('  ');
    }

    public function testSendMessageAddsAnIdempotencyKeyAndKeyboard(): void
    {
        $this->http->json(200, ['chat_id' => 'c1', 'message_id' => 'm1', 'seq' => 7, 'created_at' => 1]);
        $kb = InlineKeyboard::make()->row(Button::callback('تهران', 'city:thr'), Button::url('Site', 'https://example.com'))
            ->row(Button::switchInline('Share'));
        $out = $this->client()->sendMessage('c1', 'سلام', ['reply_markup' => $kb, 'reply_to_seq' => 3]);

        self::assertSame(7, $out['seq']);
        $r = $this->http->sent();
        self::assertSame('POST', $r['method']);
        self::assertSame('https://api.eleza.me/bot/v1/chats/c1/messages', $r['url']);
        self::assertStringContainsString('سلام', $r['body'], 'UTF-8 is not escaped');
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $r['json']['client_message_id']);
        self::assertSame('text', $r['json']['kind']);
        self::assertSame(3, $r['json']['reply_to_seq']);
        self::assertSame([
            [['text' => 'تهران', 'callback_data' => 'city:thr'], ['text' => 'Site', 'url' => 'https://example.com']],
            [['text' => 'Share', 'switch_inline_query' => '']],
        ], $r['json']['reply_markup']['inline_keyboard']);
    }

    public function testTextBuilderBecomesEntitiesWithUtf16Offsets(): void
    {
        $this->http->json(200, ['seq' => 1]);
        $text = Text::make('😀 ')->bold('سلام')->plain(' ')->link('here', 'https://e.com')->code('');
        $this->client()->sendMessage('c1', $text);

        $json = $this->http->sent()['json'];
        self::assertSame('😀 سلام here', $json['text']);
        self::assertSame([
            ['type' => 'bold', 'offset' => 3, 'length' => 4],
            ['type' => 'text_link', 'offset' => 8, 'length' => 4, 'url' => 'https://e.com'],
        ], $json['entities']);
    }

    public function testReplyKeyboards(): void
    {
        $kb = ReplyKeyboard::make()->row('A', 'B')->row('C')->resize()->oneTime()->placeholder('Pick');
        self::assertSame([
            'keyboard' => [[['text' => 'A'], ['text' => 'B']], [['text' => 'C']]],
            'resize_keyboard' => true, 'one_time_keyboard' => true, 'input_field_placeholder' => 'Pick',
        ], $kb->jsonSerialize());
        self::assertSame(['remove_keyboard' => true], ReplyKeyboard::remove()->jsonSerialize());
    }

    public function testCallbackDataIsBounded(): void
    {
        $this->expectException(ElezaException::class);
        Button::callback('x', str_repeat('a', 65));
    }

    public function testEditAndRemoveKeyboard(): void
    {
        $this->http->json(200, ['seq' => 7, 'text' => 'new']);
        $this->client()->editMessage('c1', 7, 'new', ['reply_markup' => InlineKeyboard::none()]);
        $r = $this->http->sent();
        self::assertSame('PATCH', $r['method']);
        self::assertSame('https://api.eleza.me/bot/v1/chats/c1/messages/7', $r['url']);
        self::assertSame('new', $r['json']['text']);
        self::assertStringContainsString('"reply_markup":{"inline_keyboard":[]}', $r['body']);
    }

    public function testEditNeedsSomething(): void
    {
        $this->expectException(ElezaException::class);
        $this->client()->editMessage('c1', 7);
    }

    public function testNoContentAnswers(): void
    {
        $this->http->json(204)->json(204)->json(204)->json(204);
        $c = $this->client();
        $c->deleteMessage('c1', 7);
        self::assertSame('DELETE', $this->http->sent()['method']);
        $c->setReaction('c1', 7, '👍');
        self::assertSame('{"emoji":"👍"}', $this->http->sent()['body']);
        self::assertStringEndsWith('/messages/7/reaction', $this->http->sent()['url']);
        $c->sendChatAction('c1');
        self::assertSame('{"action":"typing"}', $this->http->sent()['body']);
        $c->deleteWebhook(true);
        self::assertStringEndsWith('/bot/v1/webhook?drop_pending_updates=true', $this->http->sent()['url']);
    }

    public function testAnswerCallbackSendsAnEmptyObject(): void
    {
        $this->http->json(200, ['delivered' => true])->json(200, ['delivered' => false]);
        $c = $this->client();
        self::assertTrue($c->answerCallbackQuery('q1'));
        self::assertSame('{}', $this->http->sent()['body']);
        self::assertFalse($c->answerCallbackQuery('q1', 'Saved', true));
        self::assertSame(['text' => 'Saved', 'alert' => true], $this->http->sent()['json']);
    }

    public function testAnswerInlineQuery(): void
    {
        $this->http->json(204);
        $this->client()->answerInlineQuery('q1', [
            InlineResult::article('thr', 'Tehran', 'Tehran: 24°')->description('clear')->thumbnail('https://e.com/t.png'),
            ['type' => 'article', 'id' => 'syz', 'title' => 'Shiraz', 'text' => 'Shiraz: 27°'],
        ], 60, true, 'p2');
        $json = $this->http->sent()['json'];
        self::assertSame('thr', $json['results'][0]['id']);
        self::assertSame('https://e.com/t.png', $json['results'][0]['thumb_url']);
        self::assertSame('syz', $json['results'][1]['id']);
        self::assertSame(60, $json['cache_time']);
        self::assertTrue($json['is_personal']);
        self::assertSame('p2', $json['next_offset']);
    }

    public function testGetUpdates(): void
    {
        $this->http->json(200, ['updates' => [
            ['update_id' => 10, 'type' => 'message.created', 'occurred_at' => '2026-10-03T08:00:00.000Z', 'bot_id' => 'u1', 'v' => 1,
                'data' => ['chat_id' => 'c1', 'from' => ['id' => 'u7', 'name' => 'Sara'], 'message' => ['seq' => 12, 'kind' => 'text', 'text' => 'hi']]],
        ]]);
        $ups = $this->client()->getUpdates(10, 50, 30, ['message.created', 'callback_query']);

        self::assertCount(1, $ups);
        self::assertSame(10, $ups[0]->id);
        self::assertSame('hi', $ups[0]->text());
        self::assertSame('Sara', $ups[0]->fromName());
        self::assertSame(12, $ups[0]->seq());
        $r = $this->http->sent();
        self::assertSame('https://api.eleza.me/bot/v1/updates?limit=50&timeout=30&offset=10&allowed_updates=message.created%2Ccallback_query', $r['url']);
        self::assertGreaterThan(30.0, $r['timeout'], 'the HTTP timeout outlasts the poll');
    }

    public function testCommands(): void
    {
        $this->http->json(204)->json(200, ['commands' => [['command' => 'help', 'description' => 'Help']]]);
        $c = $this->client();
        $c->setCommands(['/today' => 'Weather', 'help' => 'Help'], 'en');
        self::assertSame(['commands' => [['command' => 'today', 'description' => 'Weather'], ['command' => 'help', 'description' => 'Help']],
            'language' => 'en'], $this->http->sent()['json']);
        self::assertSame('help', $c->getCommands()[0]['command']);
    }

    public function testApiErrorsCarryTheStableCode(): void
    {
        $this->http->problem(403, 'bot.user_not_started');
        try {
            $this->client()->sendMessage('c1', 'x');
            self::fail('expected an ApiException');
        } catch (ApiException $e) {
            self::assertSame('bot.user_not_started', $e->errorCode);
            self::assertSame(403, $e->status);
            self::assertSame('req-1', $e->requestId);
            self::assertTrue($e->isCannotWrite());
            self::assertStringNotContainsString('ezb1', $e->getMessage());
        }
        self::assertCount(1, $this->http->requests, '4xx is not retried');
    }

    public function testRateLimitIsRetriedAfterRetryAfter(): void
    {
        $this->http->problem(429, 'rate_limited', false, 2)->json(200, ['seq' => 1]);
        $this->client()->sendMessage('c1', 'x');

        self::assertSame([2.0], $this->slept);
        self::assertCount(2, $this->http->requests);
        self::assertSame($this->http->sent(0)['json']['client_message_id'], $this->http->sent(1)['json']['client_message_id'],
            'a retry repeats the same idempotency key');
    }

    public function testServerErrorsAndNetworkErrorsAreRetriedForIdempotentCalls(): void
    {
        $this->http->fail()->problem(503, 'service.unavailable', true)->json(200, ['id' => 'u1']);
        self::assertSame('u1', $this->client()->getMe()['id']);
        self::assertCount(3, $this->http->requests);
    }

    public function testNetworkErrorOnANonIdempotentCallIsNotRetried(): void
    {
        $this->http->fail();
        $this->expectException(TransportException::class);
        try {
            $this->client()->answerCallbackQuery('q1');
        } finally {
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testRetriesStop(): void
    {
        $this->http->problem(500, 'internal')->problem(500, 'internal');
        try {
            $this->client(['max_retries' => 1])->getMe();
            self::fail('expected an ApiException');
        } catch (ApiException $e) {
            self::assertSame('internal', $e->errorCode);
        }
        self::assertCount(2, $this->http->requests);
    }

    public function testPollConflictIsNotRetried(): void
    {
        $this->http->problem(409, 'bot.poll_conflict');
        $this->expectException(ApiException::class);
        $this->client()->getUpdates();
    }

    public function testNonJsonErrorFromAProxy(): void
    {
        $this->http->json(502, ['oops' => 1])->json(502, ['oops' => 1]);
        try {
            $this->client(['max_retries' => 1])->getMe();
            self::fail('expected an ApiException');
        } catch (ApiException $e) {
            self::assertSame('http.502', $e->errorCode);
        }
    }

    public function testUploadAndSendPhoto(): void
    {
        $bytes = str_repeat('a', 5) . str_repeat('b', 5) . 'cc'; // 12 bytes, parts of 5
        $this->http
            ->json(201, ['upload_id' => 'up1', 'media_id' => 'md1', 'part_size' => 5, 'state' => 'uploading', 'parts' => [
                ['n' => 1, 'url' => 'https://s3.example/p?part=1&sig=x'], ['n' => 2, 'url' => 'https://s3.example/p?part=2&sig=x'],
                ['n' => 3, 'url' => 'https://s3.example/p?part=3&sig=x']]])
            ->json(200)->json(200)->json(200)
            ->json(202, ['upload_id' => 'up1', 'media_id' => 'md1', 'state' => 'processing'])
            ->problem(409, 'media.not_ready', true)
            ->json(200, ['seq' => 9]);

        $out = $this->client()->sendPhoto('c1', InputFile::fromString($bytes, 'cat.jpg'), ['text' => 'caption']);
        self::assertSame(9, $out['seq']);

        self::assertSame(['purpose' => 'chat', 'kind' => 'image', 'size' => 12, 'mime' => 'image/jpeg', 'filename' => 'cat.jpg'], $this->http->sent(0)['json']);
        foreach ([1 => 'aaaaa', 2 => 'bbbbb', 3 => 'cc'] as $i => $chunk) {
            $part = $this->http->sent($i);
            self::assertSame('PUT', $part['method']);
            self::assertSame($chunk, $part['body']);
            self::assertArrayNotHasKey('Authorization', $part['headers'], 'the token never goes to the storage host');
        }
        self::assertSame('https://api.eleza.me/bot/v1/uploads/up1/complete', $this->http->sent(4)['url']);
        self::assertSame('', $this->http->sent(4)['body']);
        $send = $this->http->sent();
        self::assertSame(['kind' => 'photo', 'attachment' => 'md1', 'text' => 'caption'], array_diff_key($send['json'], ['client_message_id' => 1]));
        self::assertCount(7, $this->http->requests, 'the send is repeated while the media is processed');
    }

    public function testFailedUploadIsAborted(): void
    {
        $this->http
            ->json(201, ['upload_id' => 'up1', 'media_id' => 'md1', 'part_size' => 5, 'state' => 'uploading', 'parts' => [['n' => 1, 'url' => 'https://s3.example/p']]])
            ->json(403)
            ->json(204);
        try {
            $this->client()->uploadFile(InputFile::fromString('abc', 'a.bin'));
            self::fail('expected an ApiException');
        } catch (ApiException $e) {
            self::assertSame('upload.part_failed', $e->errorCode);
        }
        self::assertSame('DELETE', $this->http->sent()['method']);
        self::assertStringEndsWith('/bot/v1/uploads/up1', $this->http->sent()['url']);
    }

    public function testCreatePostIsIdempotent(): void
    {
        $this->http->problem(409, 'bot.post_in_progress', true)->json(200, ['id' => 'p1', 'limited' => true]);
        $out = $this->client()->createTextPost('خبر تازه', ['reply_policy' => 'followers']);
        self::assertSame('p1', $out['id']);
        self::assertSame($this->http->sent(0)['json']['client_post_id'], $this->http->sent(1)['json']['client_post_id']);
        self::assertSame('followers', $this->http->sent()['json']['reply_policy']);
    }

    public function testTokenStaysOutOfDumps(): void
    {
        self::assertStringNotContainsString('secret', print_r($this->client(), true));
    }
}

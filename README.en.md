# Eleza Bot SDK for PHP

Build bots for [Eleza](https://eleza.me) in a few lines of PHP: receive updates (long polling or webhook), send text and media, keyboards, inline mode and Eli posts.

[فارسی](README.md) · [Bot API guide](https://eleza.me/bots)

- No dependencies: PHP 8.1+ with the `curl` and `json` extensions
- Automatic retries for rate limits (429) and transient failures, with an idempotency key so nothing is sent twice
- Webhook signature verification
- Media upload in one call

## Install

```bash
composer require eleza/bot-sdk
```

Without Composer (shared hosting): put the library folder next to your project and

```php
require __DIR__ . '/bot-sdk-php/autoload.php';
```

## Get a token

Open a chat with **@BotFather** in Eleza and send `/newbot`. You get a token that starts with `ezb1.`.

**The token is a password.** It is shown once. Keep it out of your code and repository; read it from the environment. If it leaks: `/revoke`, then `/token` in BotFather.

## First bot

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Eleza\Bot\Bot;
use Eleza\Bot\Context;

$bot = new Bot(getenv('ELEZA_BOT_TOKEN'));

$bot->onStart(fn (Context $ctx) => $ctx->reply('Hello ' . $ctx->fromName() . '!'));
$bot->onCommand('help', fn (Context $ctx) => $ctx->reply('I repeat what you write.'));
$bot->onText(fn (Context $ctx) => $ctx->reply($ctx->text()));

$bot->run(); // long polling
```

```bash
ELEZA_BOT_TOKEN='ezb1.…' php bot.php
```

Complete bots are in [`examples/`](examples).

## Receiving updates

The first handler that matches an update handles it.

| Method | Runs when |
|---|---|
| `onStart($fn)` | the person pressed Start or sent `/start`; `$ctx->payload()` is the parameter of `eleza.me/<handle>?start=<payload>` |
| `onCommand('help', $fn)` | `/help`; the rest of the text is `$ctx->args()` |
| `onText($fn, $pattern = null)` | a text message that is not a command, optionally matching a regular expression |
| `onKind('photo', $fn)` | a message of one kind: `photo`, `video`, `file`, `voice`, `audio`, `gif`, `video_note`, `sticker`, … |
| `onMessage($fn)` | any new message that is not a command |
| `onCallback($fn, 'city:*')` | an inline button was pressed; `$ctx->callbackData()` |
| `onInlineQuery($fn)` | someone typed `@your_bot …` in a chat |
| `on(UpdateType::BOT_BLOCKED, $fn)` | any update type (`UpdateType` constants) |
| `fallback($fn)` | whatever no other handler took |
| `onError($fn)` | a handler threw; the bot carries on with the next update |

**Delivery is at least once:** the same update may arrive twice. Where doing something twice would hurt (orders, credits), store `$ctx->update->id` and skip repeats. Updates of one chat arrive in order; there is no order across chats.

A person's name and handle (`from.name`, `from.handle`) need the `profile:read` scope. A bot never gets a phone number.

### Long polling

Run `$bot->run()` from the command line, **one** process per bot: a second poll ends the first with `bot.poll_conflict`. An update is confirmed only after its handler returned, so a crash loses nothing.

```php
$bot->run([
    'timeout' => 30,                       // at most 50 seconds
    'allowed_updates' => ['message.created', 'command.received', 'user.started_bot', 'callback_query'],
    'drop_pending_updates' => false,       // true: skip what queued up while the bot was down
]);
```

### Webhook

The URL must be HTTPS on port 443 or 8443 at a public address. Register it once and keep the secret:

```php
$info = $bot->api->setWebhook('https://bot.example.com/eleza/hook.php');
echo $info['secret']; // shown only this once
```

```php
// hook.php
$bot = new Bot(getenv('ELEZA_BOT_TOKEN'));
$bot->onText(fn (Context $ctx) => $ctx->reply('Got it'));
$bot->serveWebhook(getenv('ELEZA_WEBHOOK_SECRET'));
```

`serveWebhook` checks the signature and the timestamp (401 when wrong), runs the handler and answers 200. Eleza waits 10 seconds for a 2xx and retries otherwise; for slow work use `serveWebhook($secret, answerFirst: true)` (php-fpm) or hand the work to a queue.

In a framework (Laravel, Symfony, Slim), pass the raw body and the headers yourself:

```php
use Eleza\Bot\Exception\WebhookException;

try {
    $bot->handleWebhook($secret, $request->getContent(), $request->headers->all());
    return response('', 200);
} catch (WebhookException) {
    return response('', 401);
}
```

While a webhook is set, long polling answers `bot.webhook_active`. Go back with `$bot->api->deleteWebhook()`.

## Sending

A bot can write only in a chat the person started, and not after they blocked it.

```php
use Eleza\Bot\Client;
use Eleza\Bot\Text;

$api = new Client(getenv('ELEZA_BOT_TOKEN'));   // or $bot->api

$sent = $api->sendMessage($chatId, 'Hello!');
$api->sendMessage($chatId, 'A reply', ['reply_to_seq' => 12]);

$api->editMessage($chatId, $sent['seq'], 'New text');   // the bot's own messages only
$api->deleteMessage($chatId, $sent['seq']);
$api->setReaction($chatId, 12, '👍');
$api->sendChatAction($chatId);                           // "typing…"
```

### Formatting

`Text` is the safe way, because it needs no escaping. Use it whenever a piece of the text comes from a person or another system:

```php
$text = Text::make('Order ')->bold($orderName)->plain(' is placed.')->newline()
    ->plain('Tracking code: ')->code($trackingCode)->newline()
    ->link('View the order', 'https://example.com/orders/42');

$api->sendMessage($chatId, $text);
```

Or with `parse_mode`:

```php
use Eleza\Bot\Markdown;

$api->sendMessage($chatId, '*bold* _italic_ __underline__ ~strike~ ||spoiler|| `code` [link](https://eleza.me)', [
    'parse_mode' => 'markdown',
]);

// Escape what you did not write yourself: an unclosed marker is a bot.parse_error
$api->sendMessage($chatId, 'Hello ' . Markdown::escape($userName), ['parse_mode' => 'markdown']);
```

### Media

```php
use Eleza\Bot\InputFile;

$api->sendPhoto($chatId, InputFile::fromPath('/path/photo.jpg'), ['text' => 'caption']);
$api->sendFile($chatId, InputFile::fromString($pdfBytes, 'report.pdf'));
$api->sendVoice($chatId, InputFile::fromPath('voice.ogg'));
$api->sendAudio($chatId, InputFile::fromPath('song.mp3'), ['audio' => ['title' => 'Song', 'performer' => 'Artist']]);

// Upload once, send many times
$mediaId = $api->uploadFile(InputFile::fromPath('banner.jpg'), 'image');
foreach ($chatIds as $id) {
    $api->sendPhoto($id, $mediaId);
}
```

Also `sendVideo`, `sendGif`, `sendVideoNote` and `sendSticker`. A file is always given as an `InputFile`; no string is ever read as a path, so text from a person can never make your bot send a file from your server.

The kind of an upload differs from the kind of a message: a `photo` message carries an `image` upload (the others share their name). A bot can read only the media it uploaded itself.

## Keyboards

### Inline keyboard (under a message)

```php
use Eleza\Bot\Keyboard\Button;
use Eleza\Bot\Keyboard\InlineKeyboard;

$keyboard = InlineKeyboard::make()
    ->row(Button::callback('Tehran', 'city:thr'), Button::callback('Shiraz', 'city:syz'))
    ->row(Button::url('Site', 'https://example.com'))
    ->row(Button::switchInline('Share…', 'tehran'));

$api->sendMessage($chatId, 'Choose a city', ['reply_markup' => $keyboard]);

$bot->onCallback(function (Context $ctx) {
    $ctx->answer('Selected');                              // always answer, so the button stops spinning
    $ctx->editMessage('City: ' . $ctx->callbackData());    // edits the message the button is on
}, 'city:*');
```

`callback_data` is at most 64 bytes and is never sent to people's apps: the server tells you which button was pressed and by whom, so you can trust it. Answer every press within 10 seconds. Up to 25 rows, 8 buttons per row, 100 in total. Remove the buttons with `$api->editReplyMarkup($chatId, $seq, InlineKeyboard::none())`.

### Reply keyboard (replaces the person's keyboard)

```php
use Eleza\Bot\Keyboard\ReplyKeyboard;

$api->sendMessage($chatId, 'What next?', [
    'reply_markup' => ReplyKeyboard::make()->row('Today', 'Week')->row('Settings')->resize()->oneTime(),
]);

$api->sendMessage($chatId, 'Closed', ['reply_markup' => ReplyKeyboard::remove()]);
```

Pressing a button sends its text as an ordinary message from the person.

## Commands and deep links

```php
$api->setCommands(['today' => "Today's weather", 'help' => 'Help']);
```

`https://eleza.me/my_weather_bot?start=promo42` opens the bot with a Start button; in `onStart`, `$ctx->payload()` is `promo42` (up to 64 characters of `A-Za-z0-9_-`).

## Inline mode

Send `/setinline` to BotFather first. Answer within 8 seconds:

```php
use Eleza\Bot\InlineResult;

$bot->onInlineQuery(function (Context $ctx) {
    $ctx->answerInline([
        InlineResult::article('thr', 'Tehran', 'Tehran: 24°, clear')->description('24°, clear'),
        InlineResult::article('syz', 'Shiraz', 'Shiraz: 27°')->thumbnail('https://example.com/syz.png'),
    ], cacheTime: 60);
});
```

Up to 50 results. The picked result is sent as the person's own message, marked "via @your_bot". Its buttons can only be URL or switch-inline buttons. Inline mode is not available in anonymous conversations.

## Eli posts

```php
$post = $api->createTextPost('A new version is out #eleza');

$api->createPost([
    'text' => 'caption',
    'media' => [['id' => $api->uploadFile(InputFile::fromPath('a.jpg'), 'image', 'post'), 'alt' => 'what the picture shows']],
]);

$api->createPost([
    'poll' => ['question' => 'Which one?', 'options' => ['A', 'B'], 'duration' => '24h'],
]);

$api->deletePost($post['id']);
```

- Top-level posts only: a bot cannot reply, quote or publish threads.
- `limited: true` in the answer means the bot has no official mark, so the post reaches its followers and its profile only.
- `post.reply_created`, `post.reaction_changed` and `follower.added` arrive through `$bot->on(...)`. They open no way to message those people.

## Errors

```php
use Eleza\Bot\Exception\ApiException;
use Eleza\Bot\Exception\TransportException;

try {
    $api->sendMessage($chatId, 'Hello');
} catch (ApiException $e) {
    if ($e->isCannotWrite()) {
        // not started, or blocked: take this person off your list
    }
    error_log($e->errorCode . ' ' . $e->requestId);   // a stable code, e.g. bot.user_not_started
} catch (TransportException $e) {
    // the network: no answer was received
}
```

| Status | `errorCode` | Meaning |
|---|---|---|
| 401 | `bot.unauthorized` | wrong or revoked token |
| 403 | `bot.suspended`, `bot.switched_off`, `bot.disabled` | the bot is suspended or switched off |
| 403 | `bot.scope_missing` | the token lacks the scope |
| 403 | `bot.user_not_started`, `chat.blocked` | the person has not started the bot / has blocked it |
| 404 | `chat.not_found` | not a chat of this bot |
| 400 | `bot.parse_error`, `bot.markup_invalid` | broken formatting or keyboard |
| 409 | `bot.poll_conflict`, `bot.webhook_active` | another poll is running / a webhook is set |
| 429 | `rate_limited` | slow down; wait `retryAfter` seconds |

The SDK retries 429, 5xx and network failures itself (3 times by default) and honors `retry_after`. Messages and posts always carry an idempotency key, so a retry never sends twice. For bulk sending, pace yourself too: by default 30 messages per second per bot and 60 per minute per chat.

## Options

```php
$api = new Client($token, [
    'timeout' => 30,          // seconds per request
    'max_retries' => 3,
    'language' => 'en',       // language of error titles: fa or en
    'base_url' => 'https://api.eleza.me',
]);

$bot = new Bot($api);         // or new Bot($token, [...])
```

For a route the SDK has no method for yet: `$api->request('GET', '/bot/v1/…')`.

## Tests

```bash
composer install
composer test
```

## License

MIT — see [LICENSE](LICENSE).

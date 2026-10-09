<?php

/**
 * A webhook endpoint. Put this file behind HTTPS (port 443 or 8443), e.g.
 * https://bot.example.com/eleza/hook.php, and register it once with
 * 03-webhook-setup.php.
 *
 * Environment: ELEZA_BOT_TOKEN, ELEZA_WEBHOOK_SECRET.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Eleza\Bot\Bot;
use Eleza\Bot\Context;

$bot = new Bot(elezaToken());

$bot->onStart(fn (Context $ctx) => $ctx->reply('سلام! این ربات با وبهوک کار می‌کند.'));
$bot->onText(fn (Context $ctx) => $ctx->reply('دریافت شد: ' . $ctx->text()));

// Checks the signature (401 when it is wrong), runs the handler, answers 200.
//
// Deliveries are "at least once": the same update can come twice. If doing
// something twice would hurt (charging, creating an order), remember
// $ctx->update->id — in your database, with a unique index — and skip repeats.
$bot->serveWebhook((string) getenv('ELEZA_WEBHOOK_SECRET'));

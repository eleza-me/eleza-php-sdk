<?php

/** The smallest bot: greets on Start and repeats what it is told. Long polling. */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Eleza\Bot\Bot;
use Eleza\Bot\Context;

$bot = new Bot(elezaToken());

$me = $bot->api->getMe();
echo "Running as @{$me['handle']} — press Ctrl+C to stop\n";

$bot->api->setCommands([
    'start' => 'شروع',
    'help' => 'راهنما',
]);

$bot->onStart(function (Context $ctx): void {
    $name = $ctx->fromName() ?: 'دوست من';
    $ctx->reply("سلام {$name}! هر چه بنویسی برایت تکرار می‌کنم.");
});

$bot->onCommand('help', fn (Context $ctx) => $ctx->reply('یک پیام متنی بفرست.'));

$bot->onText(fn (Context $ctx) => $ctx->replyQuoting($ctx->text()));

$bot->onMessage(fn (Context $ctx) => $ctx->reply('فعلاً فقط متن را می‌فهمم.'));

$bot->run();

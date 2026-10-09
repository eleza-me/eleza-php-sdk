<?php

/**
 * Inline mode: people type "@your_bot something" in any chat and pick a result.
 * Turn it on first: /setinline in @BotFather.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Eleza\Bot\Bot;
use Eleza\Bot\Context;
use Eleza\Bot\InlineResult;
use Eleza\Bot\Keyboard\Button;
use Eleza\Bot\Keyboard\InlineKeyboard;
use Eleza\Bot\Text;

$bot = new Bot(elezaToken());

$bot->onInlineQuery(function (Context $ctx): void {
    $query = trim($ctx->query());
    if ($query === '') {
        $ctx->answerInline([]);

        return;
    }
    $search = 'https://www.google.com/search?q=' . rawurlencode($query);

    $ctx->answerInline([
        InlineResult::article('upper', 'حروف بزرگ', mb_strtoupper($query))
            ->description(mb_strtoupper($query)),
        InlineResult::article('bold', 'پررنگ', Text::make()->bold($query))
            ->description('متن را پررنگ می‌فرستد'),
        InlineResult::article('search', 'جست‌وجو', Text::make('جست‌وجو برای ')->link($query, $search))
            ->keyboard(InlineKeyboard::make()->row(Button::url('باز کردن', $search))),
    ], cacheTime: 60);
});

$bot->onStart(fn (Context $ctx) => $ctx->reply('در هر گفت‌وگویی نام من را با @ بنویس و بعدش یک متن.'));

$bot->run(['allowed_updates' => ['inline_query', 'user.started_bot']]);

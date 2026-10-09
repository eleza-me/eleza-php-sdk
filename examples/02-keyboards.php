<?php

/** Inline buttons with callbacks, editing the message in place, and a reply keyboard. */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Eleza\Bot\Bot;
use Eleza\Bot\Context;
use Eleza\Bot\Keyboard\Button;
use Eleza\Bot\Keyboard\InlineKeyboard;
use Eleza\Bot\Keyboard\ReplyKeyboard;
use Eleza\Bot\Text;

const CITIES = ['thr' => 'تهران', 'syz' => 'شیراز', 'tbz' => 'تبریز', 'mhd' => 'مشهد'];

$bot = new Bot(elezaToken());

$cityKeyboard = static function (): InlineKeyboard {
    $buttons = [];
    foreach (CITIES as $code => $name) {
        $buttons[] = Button::callback($name, 'city:' . $code);
    }

    return InlineKeyboard::make()
        ->grid($buttons, 2)
        ->row(Button::url('سایت الزا', 'https://eleza.me'));
};

$bot->onStart(fn (Context $ctx) => $ctx->reply('یک شهر انتخاب کن:', ['reply_markup' => $cityKeyboard()]));

// callback_data is "city:<code>"
$bot->onCallback(function (Context $ctx): void {
    $code = substr($ctx->callbackData(), strlen('city:'));
    $name = CITIES[$code] ?? null;
    if ($name === null) {
        $ctx->answer('این شهر را نمی‌شناسم', alert: true);

        return;
    }
    $ctx->answer($name . ' انتخاب شد');
    $ctx->editMessage(
        Text::make('شهر شما: ')->bold($name),
        ['reply_markup' => InlineKeyboard::make()->row(Button::callback('تغییر', 'change'))],
    );
}, 'city:*');

$bot->onCallback(function (Context $ctx) use ($cityKeyboard): void {
    $ctx->answer();
    $ctx->editMessage('یک شهر انتخاب کن:', ['reply_markup' => $cityKeyboard()]);
}, 'change');

// A reply keyboard: its buttons send their text as ordinary messages.
$bot->onCommand('menu', fn (Context $ctx) => $ctx->reply('چه کاری انجام دهم؟', [
    'reply_markup' => ReplyKeyboard::make()->row('امروز', 'این هفته')->row('بستن منو')->resize()->placeholder('یکی را انتخاب کن'),
]));

$bot->onText(fn (Context $ctx) => $ctx->reply('منو بسته شد.', ['reply_markup' => ReplyKeyboard::remove()]), '/^بستن منو$/u');

$bot->onText(fn (Context $ctx) => $ctx->reply('انتخاب شما: ' . $ctx->text()));

$bot->run();

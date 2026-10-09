<div dir="rtl">

# SDK ربات الزا برای PHP

با این کتابخانه در چند خط PHP برای [الزا](https://eleza.me) ربات بسازید: دریافت پیام‌ها (long polling یا وبهوک)، ارسال متن و رسانه، دکمه‌ها، حالت درون‌خطی (inline) و انتشار الی.

[English](README.en.md) · [راهنمای Bot API](https://eleza.me/bots)

- بدون وابستگی: فقط PHP 8.1 به بالا با افزونه‌های `curl` و `json`
- تلاش دوبارهٔ خودکار برای محدودیت نرخ (429) و خطاهای موقت، با کلید تکرارناپذیری تا پیامی دوبار فرستاده نشود
- بررسی امضای وبهوک
- بارگذاری رسانه در یک فراخوانی

## نصب

با Composer:

</div>

```bash
composer require eleza/bot-sdk
```

<div dir="rtl">

بدون Composer (مثلاً روی هاست اشتراکی): پوشهٔ کتابخانه را کنار پروژه بگذارید و `autoload.php` را فراخوانی کنید:

</div>

```php
require __DIR__ . '/bot-sdk-php/autoload.php';
```

<div dir="rtl">

## ساخت ربات و گرفتن توکن

در برنامهٔ الزا با **@BotFather** گفت‌وگو کنید و `/newbot` را بفرستید. در پایان یک توکن می‌گیرید که با `ezb1.` شروع می‌شود.

**توکن مثل رمز عبور است.** فقط یک بار نشان داده می‌شود؛ آن را در کد و مخزن نگذارید و از متغیر محیطی بخوانید. اگر لو رفت در BotFather دستور `/revoke` و سپس `/token` را بفرستید.

## اولین ربات

</div>

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Eleza\Bot\Bot;
use Eleza\Bot\Context;

$bot = new Bot(getenv('ELEZA_BOT_TOKEN'));

$bot->onStart(fn (Context $ctx) => $ctx->reply('سلام ' . $ctx->fromName() . '!'));
$bot->onCommand('help', fn (Context $ctx) => $ctx->reply('هر چه بنویسی تکرار می‌کنم.'));
$bot->onText(fn (Context $ctx) => $ctx->reply($ctx->text()));

$bot->run(); // long polling
```

```bash
ELEZA_BOT_TOKEN='ezb1.…' php bot.php
```

<div dir="rtl">

نمونه‌های کامل در پوشهٔ [`examples/`](examples) هستند.

## دریافت به‌روزرسانی‌ها

اولین هندلری که با یک به‌روزرسانی جور باشد آن را می‌گیرد.

| متد | چه وقت اجرا می‌شود |
|---|---|
| `onStart($fn)` | کاربر «شروع» را زده یا `/start` فرستاده؛ `$ctx->payload()` پارامتر پیوند `eleza.me/<handle>?start=<payload>` است |
| `onCommand('help', $fn)` | دستور `/help`؛ متن بعد از دستور در `$ctx->args()` |
| `onText($fn, $pattern = null)` | پیام متنی (غیر از دستور)، در صورت نیاز با عبارت باقاعده |
| `onKind('photo', $fn)` | پیام از یک نوع خاص: `photo`، `video`، `file`، `voice`، `audio`، `gif`، `video_note`، `sticker` و … |
| `onMessage($fn)` | هر پیام تازه‌ای که دستور نیست |
| `onCallback($fn, 'city:*')` | فشردن دکمهٔ شیشه‌ای؛ `$ctx->callbackData()` |
| `onInlineQuery($fn)` | کاربر در یک گفت‌وگو `@ربات شما …` نوشته |
| `on(UpdateType::BOT_BLOCKED, $fn)` | هر نوع به‌روزرسانی (ثابت‌های `UpdateType`) |
| `fallback($fn)` | هر چیزی که هندلر دیگری نداشت |
| `onError($fn)` | خطای هندلرها؛ ربات با به‌روزرسانی بعدی ادامه می‌دهد |

**تضمین تحویل «حداقل یک بار» است:** ممکن است یک به‌روزرسانی دوبار برسد. اگر تکرار یک کار ضرر دارد (ثبت سفارش، کسر اعتبار)، `$ctx->update->id` را ذخیره کنید و تکراری‌ها را رد کنید. ترتیب به‌روزرسانی‌های یک گفت‌وگو حفظ می‌شود؛ بین گفت‌وگوهای مختلف ترتیبی وجود ندارد.

نام و شناسهٔ کاربر (`from.name`، `from.handle`) فقط با مجوز `profile:read` می‌آید. شمارهٔ تلفن هرگز به ربات داده نمی‌شود.

### Long polling

`$bot->run()` را از خط فرمان اجرا کنید؛ برای هر ربات فقط **یک** پردازش. اجرای دوم، اولی را با خطای `bot.poll_conflict` متوقف می‌کند. به‌روزرسانی فقط بعد از تمام‌شدن هندلرش تأیید می‌شود، پس اگر برنامه وسط کار از کار بیفتد چیزی گم نمی‌شود.

</div>

```php
$bot->run([
    'timeout' => 30,                       // حداکثر ۵۰ ثانیه
    'allowed_updates' => ['message.created', 'command.received', 'user.started_bot', 'callback_query'],
    'drop_pending_updates' => false,       // true: هر چه در نبود ربات جمع شده نادیده گرفته شود
]);
```

<div dir="rtl">

### وبهوک

نشانی باید HTTPS روی درگاه 443 یا 8443 و در دسترس عموم باشد. یک بار ثبتش کنید و راز (secret) را نگه دارید:

</div>

```php
$info = $bot->api->setWebhook('https://bot.example.com/eleza/hook.php');
echo $info['secret']; // فقط همین یک بار نشان داده می‌شود
```

```php
// hook.php
$bot = new Bot(getenv('ELEZA_BOT_TOKEN'));
$bot->onText(fn (Context $ctx) => $ctx->reply('دریافت شد'));
$bot->serveWebhook(getenv('ELEZA_WEBHOOK_SECRET'));
```

<div dir="rtl">

`serveWebhook` امضا و زمان درخواست را بررسی می‌کند (در صورت نادرستی 401)، هندلر را اجرا می‌کند و 200 برمی‌گرداند. الزا ۱۰ ثانیه منتظر پاسخ 2xx می‌ماند و در غیر این صورت دوباره تلاش می‌کند؛ اگر کارتان طولانی است `serveWebhook($secret, answerFirst: true)` را (روی php-fpm) به کار ببرید یا کار را به صف بسپارید.

در فریم‌ورک‌ها (Laravel، Symfony، Slim) بدنهٔ خام و هدرها را خودتان بدهید:

</div>

```php
use Eleza\Bot\Exception\WebhookException;

try {
    $bot->handleWebhook($secret, $request->getContent(), $request->headers->all());
    return response('', 200);
} catch (WebhookException) {
    return response('', 401);
}
```

<div dir="rtl">

تا وقتی وبهوک فعال است long polling کار نمی‌کند (`bot.webhook_active`). برای برگشتن: `$bot->api->deleteWebhook()`.

## ارسال پیام

ربات فقط در گفت‌وگویی می‌تواند بنویسد که کاربر آن را شروع کرده باشد، و نه بعد از این‌که کاربر ربات را مسدود کرد.

</div>

```php
use Eleza\Bot\Client;
use Eleza\Bot\Text;

$api = new Client(getenv('ELEZA_BOT_TOKEN'));   // یا $bot->api

$sent = $api->sendMessage($chatId, 'سلام!');
$api->sendMessage($chatId, 'پاسخ به پیام شما', ['reply_to_seq' => 12]);

$api->editMessage($chatId, $sent['seq'], 'متن تازه');   // فقط پیام‌های خود ربات
$api->deleteMessage($chatId, $sent['seq']);
$api->setReaction($chatId, 12, '👍');
$api->sendChatAction($chatId);                           // «در حال نوشتن…»
```

<div dir="rtl">

### قالب‌بندی متن

امن‌ترین راه `Text` است، چون نیازی به escape ندارد؛ هر جا بخشی از متن از کاربر یا سیستم دیگری می‌آید از آن استفاده کنید:

</div>

```php
$text = Text::make('سفارش ')->bold($orderName)->plain(' ثبت شد.')->newline()
    ->plain('کد پیگیری: ')->code($trackingCode)->newline()
    ->link('مشاهدهٔ سفارش', 'https://example.com/orders/42');

$api->sendMessage($chatId, $text);
```

<div dir="rtl">

یا با `parse_mode`:

</div>

```php
use Eleza\Bot\Markdown;

$api->sendMessage($chatId, '*پررنگ* _کج_ __زیرخط__ ~خط‌خورده~ ||پنهان|| `کد` [پیوند](https://eleza.me)', [
    'parse_mode' => 'markdown',
]);

// متنی که خودتان ننوشته‌اید را escape کنید؛ علامت بازِ بسته‌نشده خطای bot.parse_error می‌دهد
$api->sendMessage($chatId, 'سلام ' . Markdown::escape($userName), ['parse_mode' => 'markdown']);
```

<div dir="rtl">

### رسانه

</div>

```php
use Eleza\Bot\InputFile;

$api->sendPhoto($chatId, InputFile::fromPath('/path/photo.jpg'), ['text' => 'زیرنویس']);
$api->sendFile($chatId, InputFile::fromString($pdfBytes, 'report.pdf'));
$api->sendVoice($chatId, InputFile::fromPath('voice.ogg'));
$api->sendAudio($chatId, InputFile::fromPath('song.mp3'), ['audio' => ['title' => 'نام آهنگ', 'performer' => 'خواننده']]);

// یک بار بارگذاری، چند بار ارسال
$mediaId = $api->uploadFile(InputFile::fromPath('banner.jpg'), 'image');
foreach ($chatIds as $id) {
    $api->sendPhoto($id, $mediaId);
}
```

<div dir="rtl">

همچنین `sendVideo`، `sendGif`، `sendVideoNote` و `sendSticker`. فایل همیشه با `InputFile` داده می‌شود و هیچ رشته‌ای به‌عنوان مسیر فایل تعبیر نمی‌شود، پس متن کاربر هرگز نمی‌تواند فایلی از سرور شما را بفرستد.

نوع بارگذاری با نوع پیام فرق دارد: پیام `photo` یک بارگذاری از نوع `image` دارد (بقیه هم‌نام‌اند). ربات فقط به رسانه‌ای که خودش بارگذاری کرده دسترسی دارد.

## دکمه‌ها

### دکمه‌های شیشه‌ای (زیر پیام)

</div>

```php
use Eleza\Bot\Keyboard\Button;
use Eleza\Bot\Keyboard\InlineKeyboard;

$keyboard = InlineKeyboard::make()
    ->row(Button::callback('تهران', 'city:thr'), Button::callback('شیراز', 'city:syz'))
    ->row(Button::url('سایت', 'https://example.com'))
    ->row(Button::switchInline('اشتراک‌گذاری…', 'tehran'));

$api->sendMessage($chatId, 'یک شهر انتخاب کن', ['reply_markup' => $keyboard]);

$bot->onCallback(function (Context $ctx) {
    $ctx->answer('انتخاب شد');                      // همیشه پاسخ بدهید تا دکمه از حالت انتظار دربیاید
    $ctx->editMessage('شهر: ' . $ctx->callbackData());   // همان پیام را ویرایش می‌کند
}, 'city:*');
```

<div dir="rtl">

`callback_data` حداکثر ۶۴ بایت است و هرگز به برنامه‌های کاربران فرستاده نمی‌شود؛ سرور می‌گوید کدام دکمه را چه کسی زده، پس می‌توانید به آن اعتماد کنید. به هر فشردن دکمه ظرف ۱۰ ثانیه پاسخ دهید. حداکثر ۲۵ ردیف، ۸ دکمه در هر ردیف و ۱۰۰ دکمه در کل. برای برداشتن دکمه‌ها: `$api->editReplyMarkup($chatId, $seq, InlineKeyboard::none())`.

### صفحه‌کلید پاسخ (به‌جای صفحه‌کلید کاربر)

</div>

```php
use Eleza\Bot\Keyboard\ReplyKeyboard;

$api->sendMessage($chatId, 'بعد چه؟', [
    'reply_markup' => ReplyKeyboard::make()->row('امروز', 'این هفته')->row('تنظیمات')->resize()->oneTime(),
]);

$api->sendMessage($chatId, 'بسته شد', ['reply_markup' => ReplyKeyboard::remove()]);
```

<div dir="rtl">

فشردن هر دکمه متن آن را به‌عنوان پیام معمولی کاربر می‌فرستد.

## دستورها و پیوند شروع

</div>

```php
$api->setCommands(['today' => 'هوای امروز', 'help' => 'راهنما']);
```

<div dir="rtl">

پیوند `https://eleza.me/my_weather_bot?start=promo42` ربات را با دکمهٔ «شروع» باز می‌کند و `$ctx->payload()` در `onStart` مقدار `promo42` خواهد بود (حداکثر ۶۴ نویسه از `A-Za-z0-9_-`).

## حالت درون‌خطی

ابتدا در BotFather دستور `/setinline` را بفرستید. ظرف ۸ ثانیه پاسخ دهید:

</div>

```php
use Eleza\Bot\InlineResult;

$bot->onInlineQuery(function (Context $ctx) {
    $ctx->answerInline([
        InlineResult::article('thr', 'تهران', 'تهران: ۲۴ درجه، صاف')->description('۲۴°، صاف'),
        InlineResult::article('syz', 'شیراز', 'شیراز: ۲۷ درجه')->thumbnail('https://example.com/syz.png'),
    ], cacheTime: 60);
});
```

<div dir="rtl">

حداکثر ۵۰ نتیجه. نتیجهٔ انتخاب‌شده به‌عنوان پیام خود کاربر و با نشان «از طریق @ربات» فرستاده می‌شود. دکمه‌های نتیجه فقط می‌توانند پیوند یا switch-inline باشند. حالت درون‌خطی در گفت‌وگوهای ناشناس در دسترس نیست.

## انتشار الی

</div>

```php
$post = $api->createTextPost('نسخهٔ تازه منتشر شد #eleza');

$api->createPost([
    'text' => 'زیرنویس',
    'media' => [['id' => $api->uploadFile(InputFile::fromPath('a.jpg'), 'image', 'post'), 'alt' => 'توضیح تصویر']],
]);

$api->createPost([
    'poll' => ['question' => 'کدام؟', 'options' => ['الف', 'ب'], 'duration' => '24h'],
]);

$api->deletePost($post['id']);
```

<div dir="rtl">

- فقط پست سطح‌اول: ربات نمی‌تواند پاسخ بدهد، نقل‌قول کند یا رشته بسازد.
- `limited: true` در پاسخ یعنی ربات نشان رسمی ندارد و پست فقط به دنبال‌کننده‌ها و پروفایل ربات می‌رسد.
- به‌روزرسانی‌های `post.reply_created`، `post.reaction_changed` و `follower.added` با `$bot->on(...)` دریافت می‌شوند. این‌ها راهی برای پیام‌دادن به آن افراد باز نمی‌کنند.

## خطاها

</div>

```php
use Eleza\Bot\Exception\ApiException;
use Eleza\Bot\Exception\TransportException;

try {
    $api->sendMessage($chatId, 'سلام');
} catch (ApiException $e) {
    if ($e->isCannotWrite()) {
        // کاربر ربات را شروع نکرده یا مسدود کرده است: او را از فهرست ارسال بردارید
    }
    error_log($e->errorCode . ' ' . $e->requestId);   // کد پایدار، مثل bot.user_not_started
} catch (TransportException $e) {
    // شبکه: پاسخی دریافت نشد
}
```

<div dir="rtl">

| وضعیت | `errorCode` | معنی |
|---|---|---|
| 401 | `bot.unauthorized` | توکن نادرست یا باطل‌شده |
| 403 | `bot.suspended`، `bot.switched_off`، `bot.disabled` | ربات تعلیق یا خاموش شده |
| 403 | `bot.scope_missing` | توکن مجوز این کار را ندارد |
| 403 | `bot.user_not_started`، `chat.blocked` | کاربر ربات را شروع نکرده / مسدود کرده |
| 404 | `chat.not_found` | این گفت‌وگو متعلق به ربات نیست |
| 400 | `bot.parse_error`، `bot.markup_invalid` | قالب‌بندی متن یا دکمه‌ها نادرست است |
| 409 | `bot.poll_conflict`، `bot.webhook_active` | polling دیگری در جریان است / وبهوک فعال است |
| 429 | `rate_limited` | محدودیت نرخ؛ `retryAfter` ثانیه صبر کنید |

کتابخانه خودش 429، خطاهای 5xx و قطعی شبکه را (پیش‌فرض تا ۳ بار) دوباره امتحان می‌کند و به `retry_after` احترام می‌گذارد. ارسال پیام و پست همیشه با کلید تکرارناپذیری انجام می‌شود، پس تلاش دوباره پیام تکراری نمی‌سازد. برای ارسال انبوه، خودتان هم سرعت را نگه دارید: پیش‌فرض ۳۰ پیام در ثانیه برای هر ربات و ۶۰ پیام در دقیقه برای هر گفت‌وگو.

## تنظیمات

</div>

```php
$api = new Client($token, [
    'timeout' => 30,          // ثانیه برای هر درخواست
    'max_retries' => 3,
    'language' => 'fa',       // زبان متن خطاها: fa یا en
    'base_url' => 'https://api.eleza.me',
]);

$bot = new Bot($api);         // یا new Bot($token, [...])
```

<div dir="rtl">

برای مسیری که هنوز در SDK متد ندارد: `$api->request('GET', '/bot/v1/…')`.

## آزمون‌ها

</div>

```bash
composer install
composer test
```

<div dir="rtl">

## مجوز

MIT — پرونده‌ٔ [LICENSE](LICENSE) را ببینید.

</div>

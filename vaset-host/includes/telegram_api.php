<?php
// لایه نازک روی Telegram Bot API. توکن از جدول settings خوانده می‌شود (از
// admin/telegram.php قابل تنظیم است)، پس این فایل باید بعد از includes/config.php
// require شود.

// ─── سوییچ بات فعال برای همین درخواست: هر webhook دقیقاً یک بات رو مدیریت
// می‌کنه (بات اصلی پنل یا بات اختصاصی یک ریسلر)، پس telegram/webhook.php قبل
// از هر کاری این تابع رو صدا می‌زنه تا بقیه‌ی توابع این فایل (بدون تغییر
// امضا) خودکار روی توکن درست کار کنن. پراکسی/بریج (زیرساخت دور زدن فیلترینگ)
// بین همه‌ی بات‌ها مشترکه، فقط توکن فرق می‌کنه.
function tg_setActiveBotToken(?string $token) {
    $GLOBALS['TG_ACTIVE_BOT_TOKEN'] = $token;
}

function tg_token() {
    if (!empty($GLOBALS['TG_ACTIVE_BOT_TOKEN'])) return $GLOBALS['TG_ACTIVE_BOT_TOKEN'];
    return getSetting('telegram_bot_token', '');
}

// ─── api.telegram.org معمولاً از سرورهای ایران مستقیم قابل‌دسترسی نیست (فیلتر
// شبکه، نه مشکل کد). دو راه برای دور زدنش پشتیبانی می‌شه:
// ۱) telegram_proxy: یک پراکسی واقعی SOCKS5/HTTP (برای کسایی که VPS با دسترسی
//    root/SSH دارن و می‌تونن خودشون یک پراکسی نصب کنن).
// ۲) telegram_bridge_url + telegram_bridge_secret: یک اسکریپت PHP ساده
//    (tg_bridge.php) که روی هر هاست اشتراکی/cPanel خارج از ایران قابل آپلوده
//    و به‌جای پراکسی واقعی، خودش نقش واسطه رو بازی می‌کنه - برای کسایی که فقط
//    هاست اشتراکی دارن، نه VPS. اگه بریج تنظیم شده باشه، اولویت با اونه.
function tg_proxy() {
    return trim(getSetting('telegram_proxy', ''));
}

function tg_bridgeUrl() {
    return trim(getSetting('telegram_bridge_url', ''));
}

function tg_bridgeSecret() {
    return trim(getSetting('telegram_bridge_secret', ''));
}

// ─── هسته‌ی مشترک همه‌ی تماس‌ها با تلگرام: مستقیم (با پراکسی اختیاری) یا از طریق
// بریج. $path همون بخش بعد از https://api.telegram.org هست (مثلاً
// "/bot<token>/sendMessage" یا "/file/bot<token>/<file_path>"). $fields
// می‌تونه شامل CURLFile هم باشه (برای آپلود فایل). خروجی: ['body'=>..,'err'=>..].
function tg_rawCall($path, $fields = null, $timeout = 15, $connectTimeout = 8) {
    $bridge = tg_bridgeUrl();
    if ($bridge !== '') {
        $ch = curl_init($bridge);
        $post = ['_path' => $path];
        if ($fields !== null) {
            foreach ($fields as $k => $v) $post['_p_' . $k] = $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_HTTPHEADER     => ['X-Relay-Secret: ' . tg_bridgeSecret()],
            CURLOPT_TIMEOUT        => $timeout + 20,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout + 5,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['body' => $body, 'err' => $err];
    }

    $ch = curl_init('https://api.telegram.org' . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
    ];
    if ($fields !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $fields;
    }
    curl_setopt_array($ch, $opts);
    $proxy = tg_proxy();
    if ($proxy !== '') curl_setopt($ch, CURLOPT_PROXY, $proxy);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['body' => $body, 'err' => $err];
}

// ─── تماس عمومی با API (application/x-www-form-urlencoded) ───
function tg_api($method, $params = [], $timeout = 15) {
    $token = tg_token();
    if ($token === '') return ['ok' => false, 'description' => 'توکن ربات تنظیم نشده'];

    $r = tg_rawCall("/bot{$token}/{$method}", $params, $timeout);
    if ($r['err']) return ['ok' => false, 'description' => $r['err']];
    $decoded = json_decode((string)$r['body'], true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'invalid json از تلگرام'];
}

// $parseMode='HTML' به‌صورت پیش‌فرض تا بشه توی متن پیام‌ها از <b>پررنگ</b>/
// <i>مورب</i> برای ظاهر بهتر استفاده کرد؛ هر رشته‌ی متغیر (یوزرنیم، اسم
// فروشگاه و...) باید قبل از قرار گرفتن توی متن با htmlspecialchars() پاک‌سازی
// بشه وگرنه اگه شامل </>&  باشه می‌تونه فرمت پیام رو خراب کنه.
function tg_sendMessage($chatId, $text, $replyMarkup = null, $parseMode = 'HTML') {
    $params = ['chat_id' => $chatId, 'text' => $text];
    if ($parseMode !== null) $params['parse_mode'] = $parseMode;
    if ($replyMarkup !== null) $params['reply_markup'] = json_encode($replyMarkup);
    return tg_api('sendMessage', $params);
}

function tg_editMessageText($chatId, $messageId, $text, $replyMarkup = null, $parseMode = 'HTML') {
    $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];
    if ($parseMode !== null) $params['parse_mode'] = $parseMode;
    if ($replyMarkup !== null) $params['reply_markup'] = json_encode($replyMarkup);
    return tg_api('editMessageText', $params);
}

// ─── ویرایش کپشن یک پیامِ عکس (کارت‌های سفارش که با tg_sendPhotoByFileId
// فرستاده شدن با editMessageText قابل ویرایش نیستن - فقط کپشن‌شون) ───
function tg_editMessageCaption($chatId, $messageId, $caption, $replyMarkup = null, $parseMode = 'HTML') {
    $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'caption' => $caption];
    if ($parseMode !== null) $params['parse_mode'] = $parseMode;
    if ($replyMarkup !== null) $params['reply_markup'] = json_encode($replyMarkup);
    return tg_api('editMessageCaption', $params);
}

function tg_editMessageReplyMarkup($chatId, $messageId, $replyMarkup = null) {
    return tg_api('editMessageReplyMarkup', [
        'chat_id'      => $chatId,
        'message_id'   => $messageId,
        'reply_markup' => json_encode($replyMarkup ?? ['inline_keyboard' => []]),
    ]);
}

function tg_answerCallbackQuery($callbackId, $text = '', $showAlert = false) {
    return tg_api('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => $text,
        'show_alert'        => $showAlert ? 'true' : 'false',
    ]);
}

// ─── ارسال عکس با file_id (برای فوروارد رسید پرداخت به ادمین) ───
function tg_sendPhotoByFileId($chatId, $fileId, $caption = '', $replyMarkup = null, $parseMode = 'HTML') {
    $params = ['chat_id' => $chatId, 'photo' => $fileId, 'caption' => $caption];
    if ($parseMode !== null) $params['parse_mode'] = $parseMode;
    if ($replyMarkup !== null) $params['reply_markup'] = json_encode($replyMarkup);
    return tg_api('sendPhoto', $params);
}

// ─── ارسال فایل واقعی از دیسک (برای Export دیتابیس) ───
function tg_sendDocumentFile($chatId, $filePath, $caption = '') {
    $token = tg_token();
    if ($token === '' || !is_file($filePath)) return ['ok' => false];

    $r = tg_rawCall("/bot{$token}/sendDocument", [
        'chat_id'  => $chatId,
        'caption'  => $caption,
        'document' => new CURLFile($filePath),
    ], 180, 8);
    if ($r['err']) return ['ok' => false, 'description' => $r['err']];
    $decoded = json_decode((string)$r['body'], true);
    return is_array($decoded) ? $decoded : ['ok' => false];
}

function tg_getFile($fileId) {
    return tg_api('getFile', ['file_id' => $fileId]);
}

// ─── دانلود فایلی که کاربر برای ربات فرستاده (عکس رسید / فایل sql) ───
function tg_downloadFile($telegramFilePath, $destPath) {
    $token = tg_token();
    if ($token === '') return false;

    $r = tg_rawCall("/file/bot{$token}/{$telegramFilePath}", null, 120, 10);
    if ($r['err'] || $r['body'] === false || $r['body'] === '') return false;

    $written = @file_put_contents($destPath, $r['body']);
    if ($written === false || $written === 0) {
        @unlink($destPath);
        return false;
    }
    return true;
}

function tg_setWebhook($url, $secretToken = '') {
    $params = ['url' => $url, 'drop_pending_updates' => 'true'];
    if ($secretToken !== '') $params['secret_token'] = $secretToken;
    return tg_api('setWebhook', $params);
}

function tg_deleteWebhook() {
    return tg_api('deleteWebhook', ['drop_pending_updates' => 'true']);
}

function tg_getWebhookInfo() {
    return tg_api('getWebhookInfo');
}

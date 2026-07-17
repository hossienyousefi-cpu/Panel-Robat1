<?php
// لایه نازک روی Telegram Bot API. توکن از جدول settings خوانده می‌شود (از
// admin/telegram.php قابل تنظیم است)، پس این فایل باید بعد از includes/config.php
// require شود.

function tg_token() {
    return getSetting('telegram_bot_token', '');
}

// ─── api.telegram.org معمولاً از سرورهای ایران مستقیم قابل‌دسترسی نیست (فیلتر
// شبکه، نه مشکل کد) - اگه یک پراکسی توی تنظیمات ثبت شده باشه، همه‌ی تماس‌های
// این فایل باهاش می‌رن. فرمت مقدار: socks5://user:pass@host:port یا
// http://user:pass@host:port (همون فرمتی که CURLOPT_PROXY قبول می‌کنه).
function tg_proxy() {
    return trim(getSetting('telegram_proxy', ''));
}

function tg_applyProxy($ch) {
    $proxy = tg_proxy();
    if ($proxy !== '') {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }
}

// ─── تماس عمومی با API (application/x-www-form-urlencoded) ───
function tg_api($method, $params = [], $timeout = 15) {
    $token = tg_token();
    if ($token === '') return ['ok' => false, 'description' => 'توکن ربات تنظیم نشده'];
    $url = "https://api.telegram.org/bot{$token}/{$method}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    tg_applyProxy($ch);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['ok' => false, 'description' => $err];
    $decoded = json_decode((string)$res, true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'invalid json از تلگرام'];
}

function tg_sendMessage($chatId, $text, $replyMarkup = null) {
    $params = ['chat_id' => $chatId, 'text' => $text];
    if ($replyMarkup !== null) $params['reply_markup'] = json_encode($replyMarkup);
    return tg_api('sendMessage', $params);
}

function tg_editMessageText($chatId, $messageId, $text, $replyMarkup = null) {
    $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];
    if ($replyMarkup !== null) $params['reply_markup'] = json_encode($replyMarkup);
    return tg_api('editMessageText', $params);
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
function tg_sendPhotoByFileId($chatId, $fileId, $caption = '', $replyMarkup = null) {
    $params = ['chat_id' => $chatId, 'photo' => $fileId, 'caption' => $caption];
    if ($replyMarkup !== null) $params['reply_markup'] = json_encode($replyMarkup);
    return tg_api('sendPhoto', $params);
}

// ─── ارسال فایل واقعی از دیسک (برای Export دیتابیس) ───
function tg_sendDocumentFile($chatId, $filePath, $caption = '') {
    $token = tg_token();
    if ($token === '' || !is_file($filePath)) return ['ok' => false];
    $url = "https://api.telegram.org/bot{$token}/sendDocument";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'chat_id'  => $chatId,
            'caption'  => $caption,
            'document' => new CURLFile($filePath),
        ],
        CURLOPT_TIMEOUT => 180,
    ]);
    tg_applyProxy($ch);
    $res = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode((string)$res, true);
    return is_array($decoded) ? $decoded : ['ok' => false];
}

function tg_getFile($fileId) {
    return tg_api('getFile', ['file_id' => $fileId]);
}

// ─── دانلود فایلی که کاربر برای ربات فرستاده (عکس رسید / فایل sql) ───
function tg_downloadFile($telegramFilePath, $destPath) {
    $token = tg_token();
    if ($token === '') return false;
    $url = "https://api.telegram.org/file/bot{$token}/{$telegramFilePath}";

    $fp = fopen($destPath, 'w');
    if (!$fp) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    tg_applyProxy($ch);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $err || !is_file($destPath) || filesize($destPath) === 0) {
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

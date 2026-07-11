<?php
// نقطه ورود Webhook تلگرام. آدرس این فایل باید با admin/telegram.php (دکمه «تنظیم
// Webhook») روی سرور تلگرام ثبت شود. هیچ درخواستی از بیرون این فایل نباید مستقیماً
// اجرا شود مگر توسط خود تلگرام - به همین دلیل هدر secret_token بررسی می‌شود.
require_once '../includes/config.php';
require_once '../includes/ibsng_api.php';
require_once '../includes/telegram_api.php';
require_once '../includes/db_backup.php';
require_once 'bot.php';

$secret = getSetting('telegram_webhook_secret', '');
if ($secret !== '') {
    $given = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals($secret, $given)) {
        http_response_code(403);
        exit;
    }
}

$raw = file_get_contents('php://input');
$update = json_decode((string)$raw, true);

if (is_array($update)) {
    try {
        tg_handle_update($update);
    } catch (Throwable $e) {
        error_log('telegram webhook error: ' . $e->getMessage());
    }
}

http_response_code(200);
echo 'OK';

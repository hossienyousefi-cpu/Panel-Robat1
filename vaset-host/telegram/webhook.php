<?php
// نقطه ورود Webhook تلگرام. برای بات اصلی پنل، آدرس این فایل (بدون پارامتر)
// روی سرور تلگرام ثبت می‌شه. برای بات اختصاصی یک ریسلر، همین فایل با
// ?r=<reseller_id> ثبت می‌شه (admin/telegram.php و reseller/telegram.php هر
// دو خودشون آدرس درست رو می‌سازن) - از روی همین پارامتر تشخیص می‌دیم آپدیت
// مال کدوم بات هست. هیچ درخواستی از بیرون این فایل نباید مستقیماً اجرا شود
// مگر توسط خود تلگرام - به همین دلیل هدر secret_token بررسی می‌شود (هر بات
// secret_token جدای خودش رو داره).
require_once '../includes/config.php';
require_once '../includes/ibsng_api.php';
require_once '../includes/telegram_api.php';
require_once '../includes/db_backup.php';
require_once '../includes/reseller_bot.php';
require_once 'bot.php';

$resellerId = isset($_GET['r']) ? (int)$_GET['r'] : 0;

if ($resellerId > 0) {
    $bot = rb_getBot($resellerId);
    if (!$bot || !$bot['enabled']) {
        http_response_code(404);
        exit;
    }
    tg_setActiveBotToken($bot['bot_token']);
    $secret = $bot['webhook_secret'];
} else {
    $secret = getSetting('telegram_webhook_secret', '');
}

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
        tg_handle_update($update, $resellerId);
    } catch (Throwable $e) {
        error_log('telegram webhook error: ' . $e->getMessage());
    }
}

http_response_code(200);
echo 'OK';

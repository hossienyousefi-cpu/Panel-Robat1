<?php
// کمکی‌های مشترک بات تلگرام اختصاصی هر ریسلر (جدول reseller_bots). هم
// telegram/webhook.php (برای مسیریابی آپدیت‌های ورودی) و هم reseller/telegram.php
// (صفحه‌ی تنظیمات خودِ ریسلر) از این فایل استفاده می‌کنن.

function rb_getBot(int $resellerId): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM reseller_bots WHERE reseller_id=?");
    $stmt->execute([$resellerId]);
    $r = $stmt->fetch();
    return $r ?: null;
}

// ─── آدرس واقعی webhook.php روی همین پنل برای بات این ریسلر (پارامتر ?r=
// همون‌جاست که telegram/webhook.php ازش تشخیص می‌ده این آپدیت مال کدوم بات
// هست) - دقیقاً هم‌خانواده‌ی tg_computePanelWebhookUrl توی admin/telegram.php ───
function rb_computeWebhookUrl(int $resellerId): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $base = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/reseller/telegram.php'));
    $base = $base === '/' || $base === '\\' ? '' : $base;
    return $scheme . '://' . $host . $base . '/telegram/webhook.php?r=' . $resellerId;
}

// ─── ذخیره/به‌روزرسانی توکن بات یک ریسلر. webhook_secret اگه از قبل نبوده،
// خودکار (یک‌بار برای همیشه) ساخته می‌شه - بدون این، وبهوک این ریسلر بدون
// هیچ احراز هویتی در معرض دید عمومی می‌موند. ───
function rb_saveBotToken(int $resellerId, string $token): array {
    global $pdo;
    $existing = rb_getBot($resellerId);
    $secret = $existing['webhook_secret'] ?? bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO reseller_bots (reseller_id, bot_token, webhook_secret, enabled)
                   VALUES (?, ?, ?, 1)
                   ON DUPLICATE KEY UPDATE bot_token = VALUES(bot_token), webhook_secret = VALUES(webhook_secret)")
        ->execute([$resellerId, $token, $secret]);
    return rb_getBot($resellerId);
}

function rb_saveTexts(int $resellerId, string $paymentCardInfo, string $supportMessage): void {
    global $pdo;
    $pdo->prepare("UPDATE reseller_bots SET payment_card_info=?, support_message=? WHERE reseller_id=?")
        ->execute([$paymentCardInfo, $supportMessage, $resellerId]);
}

// ─── برندینگ بات: اسم فروشگاه (توی پیام خوش‌آمد و همه‌جای بات نشون داده
// می‌شه) + یک پیام خوش‌آمد اختصاصی اختیاری (اگه خالی بمونه، یک متن پیش‌فرض
// خوشگل با همون اسم فروشگاه ساخته می‌شه - نیازی به پر کردن اجباری نیست) ───
function rb_saveBranding(int $resellerId, string $shopName, string $welcomeMessage): void {
    global $pdo;
    $pdo->prepare("UPDATE reseller_bots SET shop_name=?, welcome_message=? WHERE reseller_id=?")
        ->execute([$shopName !== '' ? $shopName : null, $welcomeMessage !== '' ? $welcomeMessage : null, $resellerId]);
}

function rb_setEnabled(int $resellerId, bool $enabled): void {
    global $pdo;
    $pdo->prepare("UPDATE reseller_bots SET enabled=? WHERE reseller_id=?")->execute([$enabled ? 1 : 0, $resellerId]);
}

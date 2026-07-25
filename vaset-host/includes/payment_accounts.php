<?php
// کمکی‌های حساب‌های دریافت وجه (چند حساب/کارت، فقط یکی «فعال» و نمایش‌داده‌شده
// به مشتری در هر لحظه). reseller_id=0 یعنی حساب‌های بات اصلی، عدد دیگه یعنی
// حساب‌های بات اختصاصی همون ریسلر. هم admin/telegram.php و reseller/telegram.php
// (برای مدیریت) و هم telegram/bot.php (برای نمایش به مشتری) از این فایل
// استفاده می‌کنن.

function pa_list(int $resellerId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM payment_accounts WHERE reseller_id=? ORDER BY id DESC");
    $stmt->execute([$resellerId]);
    return $stmt->fetchAll();
}

function pa_add(int $resellerId, string $bank, string $card, string $holder, string $note): void {
    global $pdo;
    $pdo->prepare("INSERT INTO payment_accounts (reseller_id, bank_name, card_number, account_holder, extra_note) VALUES (?,?,?,?,?)")
        ->execute([$resellerId, trim($bank) ?: null, trim($card) ?: null, trim($holder) ?: null, trim($note) ?: null]);
}

function pa_delete(int $resellerId, int $id): void {
    global $pdo;
    $pdo->prepare("DELETE FROM payment_accounts WHERE id=? AND reseller_id=?")->execute([$id, $resellerId]);
}

function pa_setActive(int $resellerId, int $id): void {
    global $pdo;
    $pdo->prepare("UPDATE payment_accounts SET is_active=0 WHERE reseller_id=?")->execute([$resellerId]);
    $pdo->prepare("UPDATE payment_accounts SET is_active=1 WHERE id=? AND reseller_id=?")->execute([$id, $resellerId]);
}

// ─── متن آماده برای نمایش به مشتری از روی حساب فعال، یا null اگه هیچ حسابی
// ثبت/فعال نشده باشه (تا فراخوان بتونه به متن آزاد قدیمی برگرده) ───
function pa_getActiveText(int $resellerId): ?string {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM payment_accounts WHERE reseller_id=? AND is_active=1 LIMIT 1");
    $stmt->execute([$resellerId]);
    $a = $stmt->fetch();
    if (!$a) return null;

    $lines = [];
    if (!empty($a['card_number'])) $lines[] = '💳 شماره کارت: ' . $a['card_number'];
    if (!empty($a['bank_name'])) $lines[] = '🏦 بانک: ' . $a['bank_name'];
    if (!empty($a['account_holder'])) $lines[] = '👤 به نام: ' . $a['account_holder'];
    if (!empty($a['extra_note'])) $lines[] = $a['extra_note'];
    return $lines ? implode("\n", $lines) : null;
}

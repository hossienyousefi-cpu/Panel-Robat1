<?php
// کمکی‌های ادمین‌های چندگانه‌ی هر بات تلگرام (هم بات اصلی/سراسری پنل، هم بات
// اختصاصی هر ریسلر). «صاحب» بات از قبل جای دیگه مدیریت می‌شه (ادمین‌های جدول
// admins برای بات اصلی، خودِ ریسلر برای بات اختصاصی‌اش) - این فایل فقط
// ادمین‌های *اضافه*ی روی جدول bot_admins رو مدیریت می‌کنه (کسی که لازم نیست
// حتماً لاگین پنل وب داشته باشه) و یک نمای یکپارچه از «همه‌ی ادمین‌های این
// بات» می‌ده. هم telegram/bot.php (برای تشخیص/تأیید/تیکت) و هم admin/telegram.php
// و reseller/telegram.php (برای مدیریت خودِ لیست) از این فایل استفاده می‌کنن.
// عمداً به هیچ تابعی از telegram/bot.php وابسته نیست تا توی صفحات پنل وب هم
// بدون require کردن کل بات قابل‌استفاده باشه.

function ba_list(int $resellerId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM bot_admins WHERE reseller_id=? ORDER BY id");
    $stmt->execute([$resellerId]);
    return $stmt->fetchAll();
}

// ─── خطای متنی برمی‌گردونه (رشته‌ی خالی یعنی موفق) ───
function ba_add(int $resellerId, string $chatId, string $name): string {
    global $pdo;
    $chatId = preg_replace('/[^0-9\-]/', '', trim($chatId));
    $name = trim($name);
    if ($chatId === '') return 'Chat ID نامعتبر است.';
    try {
        $pdo->prepare("INSERT INTO bot_admins (reseller_id, telegram_chat_id, display_name) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE display_name = VALUES(display_name)")
            ->execute([$resellerId, $chatId, $name !== '' ? $name : null]);
        return '';
    } catch (Throwable $e) {
        return 'ثبت ادمین ناموفق بود: ' . $e->getMessage();
    }
}

function ba_remove(int $resellerId, int $id): void {
    global $pdo;
    $pdo->prepare("DELETE FROM bot_admins WHERE id=? AND reseller_id=?")->execute([$id, $resellerId]);
}

// ─── فقط برای بات اختصاصی ریسلر معنی داره (خودِ ریسلر = صاحب بات) ───
function ba_owner_chat_id(int $resellerId): ?string {
    global $pdo;
    if ($resellerId <= 0) return null;
    $stmt = $pdo->prepare("SELECT telegram_chat_id FROM resellers WHERE id=?");
    $stmt->execute([$resellerId]);
    $v = $stmt->fetchColumn();
    return ($v !== false && $v !== null && $v !== '') ? (string)$v : null;
}

// ─── همه‌ی Chat ID هایی که «ادمین این بات» محسوب می‌شن (صاحب + اضافه‌ها) ───
function ba_all_admin_chat_ids(int $resellerId): array {
    global $pdo;
    $ids = [];
    if ($resellerId <= 0) {
        $ids = $pdo->query("SELECT telegram_chat_id FROM admins WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id <> ''")
            ->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $own = ba_owner_chat_id($resellerId);
        if ($own !== null) $ids[] = $own;
    }
    $stmt = $pdo->prepare("SELECT telegram_chat_id FROM bot_admins WHERE reseller_id=?");
    $stmt->execute([$resellerId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $c) $ids[] = $c;
    return array_values(array_unique(array_map('strval', $ids)));
}

function ba_is_admin_chat(int $resellerId, $chatId): bool {
    return in_array((string)$chatId, ba_all_admin_chat_ids($resellerId), true);
}

// ─── فقط «صاحب واقعی» بات (نه ادمین‌های اضافه) - برای دستورهای حساس مثل
// /export و /import که فقط باید دست خودِ صاحب پنل/بات باشه ───
function ba_is_owner_chat(int $resellerId, $chatId): bool {
    global $pdo;
    if ($resellerId <= 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE telegram_chat_id=?");
        $stmt->execute([(string)$chatId]);
        return (bool)$stmt->fetchColumn();
    }
    return ba_owner_chat_id($resellerId) === (string)$chatId;
}

// ─── اسم نمایشی یک ادمین بر اساس Chat ID (برای پیام‌های «توسط چه‌کسی») ───
function ba_display_name(int $resellerId, $chatId): string {
    global $pdo;
    $chatId = (string)$chatId;

    $stmt = $pdo->prepare("SELECT display_name FROM bot_admins WHERE reseller_id=? AND telegram_chat_id=?");
    $stmt->execute([$resellerId, $chatId]);
    $name = $stmt->fetchColumn();
    if ($name) return $name;

    if ($resellerId <= 0) {
        $stmt = $pdo->prepare("SELECT username FROM admins WHERE telegram_chat_id=?");
        $stmt->execute([$chatId]);
        $u = $stmt->fetchColumn();
        if ($u) return $u;
    } elseif (ba_owner_chat_id($resellerId) === $chatId) {
        $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(full_name,''), username) FROM resellers WHERE id=?");
        $stmt->execute([$resellerId]);
        $u = $stmt->fetchColumn();
        if ($u) return $u;
    }
    return $chatId;
}

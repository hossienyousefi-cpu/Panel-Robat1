<?php
// صفحه‌ی موقت تشخیصی - فقط برای پیدا کردن اینکه کند بودن سرچ کاربران از کجا میاد.
// بعد از رفع مشکل می‌توان این فایل را حذف کرد.
require_once '../includes/config.php';
require_once '../includes/ibsng_api.php';
requireAdmin();
header('Content-Type: text/plain; charset=utf-8');

$t0 = microtime(true);
$fresh = ibsng_dbCacheFresh($pdo);
$t1 = microtime(true);
echo "۱) ibsng_dbCacheFresh() = " . ($fresh ? 'true (کش تازه در نظر گرفته می‌شود)' : 'false (کش قدیمی/خالی است)') . "\n";
echo "   زمان این چک: " . round(($t1 - $t0) * 1000) . " ms\n\n";

$row = $pdo->query("SELECT COUNT(*) c, MAX(updated_at) m FROM ibsng_users_cache")->fetch();
echo "۲) تعداد ردیف جدول ibsng_users_cache: {$row['c']}\n";
echo "   آخرین سینک (updated_at): {$row['m']}\n";
echo "   الان: " . date('Y-m-d H:i:s') . "\n\n";

$t2 = microtime(true);
$onlineSet = ibsng_getOnlineUsernameSet('');
$t3 = microtime(true);
echo "۳) زمان گرفتن لیست آنلاین از IBSng (ibsng_getOnlineUsernameSet): " . round(($t3 - $t2) * 1000) . " ms\n";
echo "   تعداد کاربر آنلاین: " . count($onlineSet) . "\n\n";

$t4 = microtime(true);
$res = ibsng_dbCacheSearch($pdo, '', '', '', '', '', 'desc', 0, 50);
$t5 = microtime(true);
echo "۴) زمان کامل ibsng_dbCacheSearch (بدون هیچ فیلتری، فقط ۵۰ ردیف اول): " . round(($t5 - $t4) * 1000) . " ms\n";
echo "   تعداد کل نتیجه: {$res['total']}\n";
echo "   کش استفاده شد: " . ($res['cached'] ? 'بله' : 'خیر') . "\n\n";

echo "۵) تماس مستقیم و خام با report.getOnlineUsers (تایم‌اوت ۳۰ ثانیه، بدون کش/circuit breaker):\n";
$t6 = microtime(true);
$raw = ibsng_call('report.getOnlineUsers', [
    'normal_sort_by' => 'username',
    'normal_desc'    => false,
    'voip_sort_by'   => 'username',
    'voip_desc'      => false,
    'conds'          => [],
], 0, 30);
$t7 = microtime(true);
echo "   زمان: " . round(($t7 - $t6) * 1000) . " ms\n";
echo "   error: " . var_export($raw['error'] ?? null, true) . "\n";
echo "   ساختار result: " . (isset($raw['result']) ? gettype($raw['result']) : 'وجود ندارد') . "\n";
echo "   خروجی کامل (اگر خیلی بزرگ بود فقط ۲۰۰۰ کاراکتر اول): \n";
echo substr(json_encode($raw, JSON_UNESCAPED_UNICODE), 0, 2000) . "\n\n";

echo "=== پایان تست ===\n";

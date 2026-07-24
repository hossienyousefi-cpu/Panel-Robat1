<?php
// صفحه‌ی موقت تشخیصی - فقط برای پیدا کردن اینکه کند بودن سرچ کاربران ریسلر از
// کجا میاد. بعد از رفع مشکل می‌توان این فایل را حذف کرد.
require_once '../includes/config.php';
require_once '../includes/ibsng_api.php';
requireReseller();
header('Content-Type: text/plain; charset=utf-8');

$rid = (int)$_SESSION['reseller_id'];
$stmt = $pdo->prepare("SELECT * FROM resellers WHERE id=?");
$stmt->execute([$rid]);
$resellerRow = $stmt->fetch();
$ispName = trim($resellerRow['isp_name'] ?? '');

echo "ISP این ریسلر: " . ($ispName !== '' ? $ispName : '(خالی است!)') . "\n\n";

$t0 = microtime(true);
$fresh = ibsng_dbCacheFresh($pdo);
$t1 = microtime(true);
echo "۱) ibsng_dbCacheFresh() = " . ($fresh ? 'true' : 'false') . " (" . round(($t1 - $t0) * 1000) . " ms)\n\n";

$t2 = microtime(true);
$result = ibsng_getIspUsersPage($ispName, '', '', '', 'desc', 0, 50);
$t3 = microtime(true);
echo "۲) زمان کامل ibsng_getIspUsersPage(): " . round(($t3 - $t2) * 1000) . " ms\n";
echo "   total: {$result['total']}, total_isp: {$result['total_isp']}, cached: " . ($result['cached'] ? 'بله' : 'خیر') . "\n\n";

echo "=== پایان تست ===\n";

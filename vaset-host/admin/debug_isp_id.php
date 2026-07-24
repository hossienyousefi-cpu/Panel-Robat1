<?php
// صفحه‌ی موقت تشخیصی - برای پیدا کردن اینکه چرا isp_id یک ISP تازه‌ساز پیدا
// نمی‌شه (که باعث می‌شه لیست کاربرهاش همیشه خالی باشه). بعد از رفع مشکل حذف شود.
require_once '../includes/config.php';
require_once '../includes/ibsng_api.php';
requireAdmin();
header('Content-Type: text/plain; charset=utf-8');

$ispParam = trim($_GET['isp'] ?? '');

echo "۱) لیست ریسلرهای موجود توی دیتابیس پنل (ستون isp_name):\n";
$rs = $pdo->query("SELECT id, username, isp_name FROM resellers ORDER BY id DESC")->fetchAll();
foreach ($rs as $r) {
    echo "   #{$r['id']}  username=" . var_export($r['username'], true) . "  isp_name=" . var_export($r['isp_name'], true) . "\n";
}
echo "\n";

if ($ispParam === '') {
    echo "برای ادامه‌ی تست، آدرس رو با ?isp=NAME باز کن (دقیقاً همون isp_name بالا رو کپی کن).\n";
    exit;
}

echo "۲) تست برای isp=" . var_export($ispParam, true) . " (طول رشته: " . strlen($ispParam) . " بایت)\n\n";

echo "۳) نگاشت دستی ذخیره‌شده (settings.isp_id_map):\n";
$manual = ibsng_getIspIdManualMap();
echo "   کل نگاشت: " . json_encode($manual, JSON_UNESCAPED_UNICODE) . "\n";
echo "   مقدار برای این ISP: " . var_export($manual[$ispParam] ?? null, true) . "\n\n";

echo "۴) admin.getAdminInfo با admin_username = این ISP:\n";
$t0 = microtime(true);
$adminInfo = ibsng_call('admin.getAdminInfo', ['admin_username' => $ispParam]);
echo "   زمان: " . round((microtime(true) - $t0) * 1000) . " ms\n";
echo "   خروجی: " . json_encode($adminInfo, JSON_UNESCAPED_UNICODE) . "\n\n";

echo "۵) اسکن تازه‌ی isp_id (بدون اعتماد به کش قدیمی، تا ۲۰۰ id):\n";
$t1 = microtime(true);
$freshMap = ibsng_getIspIdMap(200, true);
echo "   زمان: " . round((microtime(true) - $t1) * 1000) . " ms\n";
echo "   تعداد ISP پیدا شده: " . count($freshMap) . "\n";
echo "   نقشه‌ی کامل: " . json_encode($freshMap, JSON_UNESCAPED_UNICODE) . "\n";
echo "   مقدار برای این ISP: " . var_export($freshMap[$ispParam] ?? null, true) . "\n\n";

echo "۶) نتیجه‌ی نهایی ibsng_getIspId():\n";
$finalId = ibsng_getIspId($ispParam);
echo "   isp_id = " . var_export($finalId, true) . "\n\n";

echo "۷) اسم‌های واقعی ISP از دید خودِ IBSng (isp.getAllISPNames):\n";
$allIsps = ibsng_getIsps();
echo "   " . json_encode($allIsps, JSON_UNESCAPED_UNICODE) . "\n";
$exactMatch = in_array($ispParam, $allIsps, true);
echo "   تطابق دقیق (حساس به بزرگی/کوچکی حروف و فاصله) با اسم واردشده: " . ($exactMatch ? 'بله' : 'خیر - این احتمالاً ریشه‌ی مشکله!') . "\n";
if (!$exactMatch) {
    foreach ($allIsps as $realIsp) {
        if (strcasecmp(trim($realIsp), trim($ispParam)) === 0) {
            echo "   شبیه‌ترین مورد (فقط با بزرگی/کوچکی یا فاصله فرق داره): " . var_export($realIsp, true) . "\n";
        }
    }
}

echo "\n=== پایان تست ===\n";

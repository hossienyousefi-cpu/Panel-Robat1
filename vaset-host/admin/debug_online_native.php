<?php
// صفحه‌ی موقت تشخیصی - برای پیدا کردن آدرس واقعی صفحه‌ی «کاربران آنلاین» توی
// پنل اصلی IBSng (نه API)، چون report.getOnlineUsers روی این IBSng جواب نمی‌ده
// ولی خودِ پنل اصلی آنلاین‌ها رو درست نشون می‌ده. بعد از پیدا شدن آدرس درست،
// این فایل حذف می‌شود.
require_once '../includes/config.php';
require_once '../includes/ibsng_api.php';
requireAdmin();
header('Content-Type: text/plain; charset=utf-8');

echo "۱) لاگین به پنل اصلی IBSng...\n";
$ok = ibsng_nativeLogin();
echo "   نتیجه لاگین: " . ($ok ? 'موفق' : 'ناموفق') . "\n\n";
if (!$ok) { echo "بدون لاگین موفق نمی‌شه ادامه داد.\n"; exit; }

$cookieFile = ibsng_nativeCookieFile();
$base = rtrim(IBS_URL, '/');

$candidates = [
    'report/online_user_rpt.php',
    'report/online_user_rpt_res.php',
    'report/online_users.php',
    'report/online_user_list.php',
    'user/online_user_rpt.php',
    'online_user_rpt.php',
    'report/onlineuser.php',
    'report/online.php',
];

foreach ($candidates as $path) {
    $url = $base . '/' . $path;
    $res = ibsng_nativeHttpEx($url, $cookieFile);
    $body = $res['body'];
    $len  = $body === false ? 0 : strlen($body);
    $isLoggedInPage = $body !== false && ibsng_nativeIsLoggedIn($body);
    echo "── $path ──\n";
    echo "   HTTP: {$res['http_code']}   effective_url: {$res['effective_url']}\n";
    echo "   طول پاسخ: $len بایت   صفحه‌ی داخلی معتبر (لینک Logout داره): " . ($isLoggedInPage ? 'بله' : 'خیر') . "\n";
    if ($body !== false && $len > 0) {
        $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
        echo "   متن خام (بدون تگ، ۳۰۰ کاراکتر اول): " . substr($snippet, 0, 300) . "\n";
    }
    echo "\n";
}

echo "=== پایان تست ===\n";

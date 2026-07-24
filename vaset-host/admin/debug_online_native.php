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

// از تست قبلی معلوم شد آدرس واقعی همینه (خودِ IBSng توی پیام خطای Referer لو
// دادش): user/search_user.php با تب Online. مثل kill_user_by_id.php یک هدر
// Referer معتبر لازم داره وگرنه رد می‌شه.
$path = 'user/search_user.php?tab1_selected=Online';
$url  = $base . '/' . $path;

$refererCandidates = [
    $base . '/user/search_user.php',
    $base . '/admin_index.php',
];

foreach ($refererCandidates as $ref) {
    $headers = ['Referer: ' . $ref];
    $res  = ibsng_nativeHttpEx($url, $cookieFile, null, $headers);
    $body = $res['body'];
    $len  = $body === false ? 0 : strlen($body);
    $isLoggedInPage = $body !== false && ibsng_nativeIsLoggedIn($body);
    echo "── Referer: $ref ──\n";
    echo "   HTTP: {$res['http_code']}   effective_url: {$res['effective_url']}\n";
    echo "   طول پاسخ: $len بایت   صفحه‌ی داخلی معتبر (لینک Logout داره): " . ($isLoggedInPage ? 'بله' : 'خیر') . "\n";
    if ($body !== false && $len > 0) {
        if ($isLoggedInPage) {
            echo "   *** HTML کامل (برای پیدا کردن ساختار جدول) ***\n";
            echo $body . "\n";
        } else {
            $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
            echo "   متن خام (بدون تگ، ۳۰۰ کاراکتر اول): " . substr($snippet, 0, 300) . "\n";
        }
    }
    echo "\n";
    if ($isLoggedInPage) break;
}

echo "=== پایان تست ===\n";

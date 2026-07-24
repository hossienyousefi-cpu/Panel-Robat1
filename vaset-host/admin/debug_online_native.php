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
// Referer معتبر لازم داره وگرنه رد می‌شه. تست قبلی فقط خودِ فرم خالی رو نشون داد
// (چون search=1/submit_form=1 ارسال نشده بود) - این بار واقعاً فرم رو submit
// می‌کنیم (چک‌باکس Online + چند تا attribute) تا نتیجه‌ی واقعی کاربرهای آنلاین
// رو ببینیم.
$searchUrl = $base . '/user/search_user.php';

$postFields = [
    'search'          => '1',
    'show_reports'    => '1',
    'submit_form'     => '1',
    'page'            => '1',
    'is_online_yes'   => 'On',
    'order_by'        => 'user_id',
    'desc'            => 'on',
    'rpp'             => '5000',
    'view_options'    => '2', // WEB - برای دیدن ساختار واقعی جدول
    'Internet_Username' => 'show__attrs_normal_username',
    'User_ID'           => 'show__basic_user_id',
    'Group'             => 'show__basic_group_name',
    'ISP'               => 'show__basic_isp_name',
    'Online'            => 'show__online_status|formatOnline',
    'Remote_IPs'        => 'show__remote_ips',
];

$headers = ['Referer: ' . $searchUrl . '?tab1_selected=Online'];
$res  = ibsng_nativeHttpEx($searchUrl, $cookieFile, $postFields, $headers);
$body = $res['body'];
$len  = $body === false ? 0 : strlen($body);
$isLoggedInPage = $body !== false && ibsng_nativeIsLoggedIn($body);

echo "── POST جستجوی کاربران آنلاین ──\n";
echo "   HTTP: {$res['http_code']}   effective_url: {$res['effective_url']}\n";
echo "   طول پاسخ: $len بایت   صفحه‌ی داخلی معتبر: " . ($isLoggedInPage ? 'بله' : 'خیر') . "\n\n";

if ($body === false || $len === 0) {
    echo "پاسخی دریافت نشد.\n";
} elseif (!$isLoggedInPage) {
    $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
    echo "متن خام (بدون تگ، ۵۰۰ کاراکتر اول): " . substr($snippet, 0, 500) . "\n";
} else {
    // فقط بخش جدول نتایج (list_table0) رو نشون بده، نه کل فرم جستجو
    $pos = strpos($body, "id='list_table0'");
    if ($pos === false) $pos = strpos($body, 'id="list_table0"');
    if ($pos !== false) {
        $start = max(0, $pos - 50);
        echo "*** بخش جدول نتایج (از نزدیک list_table0) ***\n";
        echo substr($body, $start, 8000) . "\n";
    } else {
        echo "!!! id='list_table0' توی پاسخ پیدا نشد - کل HTML رو چاپ می‌کنم:\n";
        echo $body . "\n";
    }
}

echo "\n=== پایان تست ===\n";

<?php
require_once '../includes/config.php';
require_once '../includes/ibsng_api.php';
requireAdmin();

// ابزار موقت دیباگ: خروجی خام  رو برای یک یوزرنیم نشون می‌ده تا مقادیر واقعی
// فیلدهایی مثل status رو بدون حدس زدن ببینیم. فقط خواندنی (read-only) و هیچ
// تغییری روی IBSng یا دیتابیس اعمال نمی‌کنه.
$username = trim($_GET['username'] ?? '');
$ispName  = trim($_GET['isp'] ?? '');
$raw = null; $err = ''; $ispRaw = null;
if ($username !== '') {
    $r = ibsng_call('user.searchUser', [
        'conds' => ['normal_username' => $username, 'normal_username_op' => 'like'],
        'from' => 0, 'to' => 5, 'order_by' => 'user_id', 'desc' => true,
    ]);
    $uids = $r['result'][2] ?? [];
    if (empty($uids)) {
        $err = 'کاربری با این نام پیدا نشد. پاسخ خام جستجو: ' . htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE));
    } else {
        $inf = ibsng_call('user.getUserInfo', ['user_id' => implode(',', $uids)]);
        $raw = $inf['result'] ?? $inf;
    }
}
if ($ispName !== '') {
    // خروجی خام searchUser فقط با فیلتر ISP (بدون یوزرنیم) - برای دیدن اینکه
    // conds['isp_name'] واقعاً روی  فیلتر می‌کنه یا نه، بدون حدس زدن.
    $r2 = ibsng_call('user.searchUser', [
        'conds' => ['isp_name' => [$ispName]],
        'from' => 0, 'to' => 10, 'order_by' => 'user_id', 'desc' => true,
    ]);
    $ispUids = $r2['result'][2] ?? [];
    $ispInfos = [];
    if (!empty($ispUids)) {
        $ii = ibsng_call('user.getUserInfo', ['user_id' => implode(',', $ispUids)]);
        foreach ($ispUids as $u) {
            $row = $ii['result'][$u] ?? $ii['result'][(string)$u] ?? null;
            $ispInfos[$u] = $row['basic_info']['isp_name'] ?? '(نامشخص)';
        }
    }
    $ispRaw = ['total' => $r2['result'][0] ?? null, 'uids' => $ispUids, 'actual_isp_of_each_uid' => $ispInfos, 'raw' => $r2];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>دیباگ کاربر</title>
<style>
body{font-family:monospace;background:#0b1120;color:#e2e8f0;padding:24px}
form{margin-bottom:20px;display:flex;gap:8px}
input{padding:10px;border-radius:8px;border:1px solid #334155;background:#111827;color:#fff;font-size:14px;flex:1;max-width:320px}
button{padding:10px 18px;border-radius:8px;border:none;background:#3b82f6;color:#fff;cursor:pointer;font-weight:700}
pre{background:#111827;border:1px solid #1e293b;border-radius:10px;padding:16px;white-space:pre-wrap;word-break:break-word;font-size:13px;line-height:1.6}
a{color:#60a5fa}
.err{color:#f87171}
</style>
</head>
<body>
<a href="users.php">← بازگشت به کاربران</a>
<h2>خروجی خام IBSng برای یک یوزرنیم</h2>
<form method="GET">
  <input type="text" name="username" placeholder="یوزرنیم دقیق، مثلاً TR069" value="<?=htmlspecialchars($username)?>" autofocus>
  <button type="submit">نمایش</button>
</form>
<?php if($err):?><p class="err"><?=$err?></p><?php endif;?>
<?php if($raw!==null):?>
<pre><?=htmlspecialchars(json_encode($raw, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre>
<?php endif;?>

<h2>خروجی خام IBSng فقط با فیلتر ISP (بدون یوزرنیم)</h2>
<form method="GET">
  <input type="text" name="isp" placeholder="اسم دقیق ISP، مثلاً Milad" value="<?=htmlspecialchars($ispName)?>">
  <button type="submit">نمایش</button>
</form>
<?php if($ispRaw!==null):?>
<p>تعداد کل گزارش‌شده: <b><?=htmlspecialchars((string)($ispRaw['total']??'?'))?></b> — UIDهای برگشتی: <b><?=count($ispRaw['uids'])?></b></p>
<p>ISP واقعی هر UID برگشتی (باید همه برابر با ISP جستجوشده باشند):</p>
<pre><?=htmlspecialchars(json_encode($ispRaw['actual_isp_of_each_uid'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre>
<p>پاسخ خام کامل:</p>
<pre><?=htmlspecialchars(json_encode($ispRaw['raw'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre>
<?php endif;?>
</body>
</html>

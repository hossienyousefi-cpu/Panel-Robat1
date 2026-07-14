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
// ۷) تست admin.getAdminInfo(admin_username=اسم ISP) برای همه‌ی ISPها با هم - معلوم
// شد این فقط وقتی کار می‌کنه که یوزرنیم ادمین دقیقاً همون اسم ISP باشه (فقط Milad).
// فیلد صحیح پاسخ "username" هست نه "admin_username".
$allIspLookup = [];
$allIsps = ibsng_getIsps();
if (!empty($allIsps)) {
    foreach ($allIsps as $ispEach) {
        $rEach = ibsng_call('admin.getAdminInfo', ['admin_username' => $ispEach]);
        $infoEach = $rEach['result'] ?? null;
        $allIspLookup[$ispEach] = [
            'requested'          => $ispEach,
            'returned_username'  => $infoEach['username'] ?? null,
            'returned_admin_id'  => $infoEach['admin_id'] ?? null,
            'returned_isp_name'  => $infoEach['isp_name'] ?? null,
            'username_matches'   => isset($infoEach['username']) && $infoEach['username'] === $ispEach,
            'error'              => $rEach['error'] ?? null,
        ];
    }
}

// ۸) admin.getAdminInfo با admin_id عددی اصلاً کار نمی‌کنه (تست شد: برای هر عددی
// همون خطای "argument admin_username not found" رو می‌ده - یعنی admin_id پارامتر
// معتبری براش نیست). پس به‌جاش از خودِ فیلتر کارکردِ isp_id روی user.searchUser
// استفاده می‌کنیم: برای هر عدد کاندید ۱ تا ۵۰، یک کاربر نمونه می‌گیریم و
// isp_name واقعیش رو می‌خونیم - این باید کل نگاشت واقعی اسم‌ISP↔isp_id رو نشون بده.
$ispIdScan = [];
for ($iid = 1; $iid <= 50; $iid++) {
    $rI = ibsng_call('user.searchUser', [
        'conds' => ['isp_id' => [(string)$iid]], 'from' => 0, 'to' => 1,
        'order_by' => 'user_id', 'desc' => true,
    ]);
    $total = $rI['result'][0] ?? 0;
    $uidsI = $rI['result'][2] ?? [];
    if (empty($uidsI)) continue;
    $infI = ibsng_call('user.getUserInfo', ['user_id' => (string)$uidsI[0]]);
    $rowI = $infI['result'][$uidsI[0]] ?? $infI['result'][(string)$uidsI[0]] ?? null;
    $ispIdScan[$iid] = [
        'isp_id'   => $iid,
        'total'    => $total,
        'isp_name' => $rowI['basic_info']['isp_name'] ?? null,
    ];
}

$ispCandidates = [];
$ispNamesRaw = null;
$sanityChecks = [];
if ($ispName !== '') {
    // isp_name به‌صورت آرایه هیچ فیلتری اعمال نمی‌کرد (total همیشه کل کاربرها بود) -
    // پس چند شکل مختلف conds رو موازی امتحان می‌کنیم تا بدون حدس زدن بیشتر ببینیم
    // کدومش واقعاً روی  فیلتر می‌کنه.
    $shapes = [
        "isp_name => ['{$ispName}']"            => ['isp_name' => [$ispName]],
        "isp_name => '{$ispName}'"              => ['isp_name' => $ispName],
        "isp_name+op => 'equals'"               => ['isp_name' => [$ispName], 'isp_name_op' => 'equals'],
        "isp_name_1 => '{$ispName}'"            => ['isp_name_1' => $ispName],
        "isp => ['{$ispName}']"                 => ['isp' => [$ispName]],
        "owner_isp => ['{$ispName}']"           => ['owner_isp' => [$ispName]],
    ];
    foreach ($shapes as $label => $conds) {
        $rr = ibsng_call('user.searchUser', [
            'conds' => $conds, 'from' => 0, 'to' => 5, 'order_by' => 'user_id', 'desc' => true,
        ]);
        $total = $rr['result'][0] ?? null;
        $uids  = $rr['result'][2] ?? [];
        $actualIsps = [];
        if (!empty($uids)) {
            $ii = ibsng_call('user.getUserInfo', ['user_id' => implode(',', $uids)]);
            foreach ($uids as $u) {
                $row = $ii['result'][$u] ?? $ii['result'][(string)$u] ?? null;
                $actualIsps[] = $row['basic_info']['isp_name'] ?? '?';
            }
        }
        $matches = $total !== null && !empty($actualIsps) && count(array_unique($actualIsps)) === 1 && $actualIsps[0] === $ispName;
        $ispCandidates[$label] = ['total' => $total, 'uids' => $uids, 'actual_isps' => $actualIsps, 'looks_correct' => $matches, 'error' => $rr['error'] ?? null];
    }

    // ۱) خروجی خام isp.getAllISPNames - شاید id هر ISP رو هم برگردونه، نه فقط اسم
    $ispNamesRaw = ibsng_call('isp.getAllISPNames', [], 0);

    // ۲) sanity check: آیا ترکیب یک کلید ناشناخته با normal_username (که خودش کار
    // می‌کنه) باعث می‌شه کل conds نادیده گرفته بشه؟ اگه اینجا هم total کامل برگرده
    // یعنی مشکل کلی‌تر از اسم فیلده. اگه فیلتر یوزرنیم درست کار کنه، یعنی کلید
    // ناشناخته فقط خودش نادیده گرفته می‌شه و مشکل مختص isp_name/معادل‌هاشه.
    $rSanity = ibsng_call('user.searchUser', [
        'conds' => ['normal_username' => 'a', 'normal_username_op' => 'like', 'zzz_bogus_field' => 'x'],
        'from' => 0, 'to' => 5, 'order_by' => 'user_id', 'desc' => true,
    ]);
    $sanityChecks['bogus_key_with_working_username_filter'] = [
        'total' => $rSanity['result'][0] ?? null,
        'error' => $rSanity['error'] ?? null,
    ];
    // ۳) آیا group_name هم همین مشکل رو داره؟ (یعنی مشکل فقط ISP نیست، همه‌ی
    // فیلترهای چندانتخابی/چک‌باکسی همینطور نادیده گرفته می‌شن)
    $groups = ibsng_getGroups();
    if (!empty($groups)) {
        $rGrp = ibsng_call('user.searchUser', [
            'conds' => ['group_name' => $groups[0]], 'from' => 0, 'to' => 5, 'order_by' => 'user_id', 'desc' => true,
        ]);
        $gUids = $rGrp['result'][2] ?? [];
        $gActual = [];
        if (!empty($gUids)) {
            $gi = ibsng_call('user.getUserInfo', ['user_id' => implode(',', $gUids)]);
            foreach ($gUids as $u) {
                $row = $gi['result'][$u] ?? $gi['result'][(string)$u] ?? null;
                $gActual[] = $row['basic_info']['group_name'] ?? '?';
            }
        }
        $sanityChecks['group_name_filter_test'] = [
            'tried_group' => $groups[0], 'total' => $rGrp['result'][0] ?? null, 'actual_groups' => $gActual,
        ];
    }

    // ۴) خروجی کامل و خام getUserInfo برای یک کاربر شناخته‌شده‌ی این ISP (بدون
    // محدود کردن به basic_info.isp_name) - شاید یک فیلد id عددی برای ISP هم توی
    // پاسخ باشه که ما تا الان نادیده گرفتیمش.
    $anyIspUids = $ispCandidates["isp_name => ['{$ispName}']"]['uids'] ?? [];
    if (!empty($anyIspUids)) {
        $fullDump = ibsng_call('user.getUserInfo', ['user_id' => (string)$anyIspUids[0]]);
        $sanityChecks['full_user_dump'] = ['uid' => $anyIspUids[0], 'raw' => $fullDump];
    }

    // ۵) حالا که فهمیدیم Milad دقیقاً isp_id=6 داره (از دامپ کامل بالا)، چند شکل
    // مختلف رو مستقیم با همین id امتحان می‌کنیم - شامل حالتی که فیلدهای عددی مثل
    // Credit1 توی فرم اصلی یک عملگر (isp_id_op=equals) هم دارن.
    $realIspId = null;
    if (!empty($sanityChecks['full_user_dump']['raw']['result'])) {
        $firstRow = reset($sanityChecks['full_user_dump']['raw']['result']);
        $realIspId = $firstRow['basic_info']['isp_id'] ?? null;
    }
    $idGuesses = [];
    if ($realIspId !== null) {
        $idShapes = [
            "isp_id => {$realIspId}"                         => ['isp_id' => $realIspId],
            "isp_id => '{$realIspId}'"                       => ['isp_id' => (string)$realIspId],
            "isp_id => [{$realIspId}]"                        => ['isp_id' => [$realIspId]],
            "isp_id+op='=' "                                  => ['isp_id' => $realIspId, 'isp_id_op' => '='],
            "isp_id+op='equals'"                              => ['isp_id' => $realIspId, 'isp_id_op' => 'equals'],
            "group_id => 1 (sanity, matches earlier group)"   => ['group_id' => 1],
        ];
        foreach ($idShapes as $label => $conds) {
            $rId = ibsng_call('user.searchUser', [
                'conds' => $conds, 'from' => 0, 'to' => 5, 'order_by' => 'user_id', 'desc' => true,
            ]);
            $total = $rId['result'][0] ?? null;
            $idUids = $rId['result'][2] ?? [];
            $idActual = [];
            if (!empty($idUids)) {
                $iu = ibsng_call('user.getUserInfo', ['user_id' => implode(',', $idUids)]);
                foreach ($idUids as $u) {
                    $row = $iu['result'][$u] ?? $iu['result'][(string)$u] ?? null;
                    $idActual[] = $row['basic_info']['isp_name'] ?? '?';
                }
            }
            $idGuesses[$label] = ['total' => $total, 'actual_isps' => $idActual, 'error' => $rId['error'] ?? null];
        }
    }
    if (!empty($idGuesses)) $sanityChecks['isp_id_guesses'] = $idGuesses;

    // ۶) حالا که فهمیدیم isp_id (به‌صورت رشته یا آرایه) واقعاً فیلتر می‌کنه، باید
    // بتونیم بدون داشتن یک کاربر از قبل، فقط از روی اسم ISP این عدد رو پیدا کنیم.
    // از روی عکس‌های ادمین پنل، isp_id همون Admin ID حساب ادمینِ صاحب آن ISP هست
    // (یوزرنیم ادمین = اسم ISP). چند متد احتمالی رو امتحان می‌کنیم.
    $ispIdLookup = [];
    $lookupMethods = [
        'isp.getIspInfo (isp_name)'         => ['isp.getIspInfo', ['isp_name' => $ispName]],
        'admin.getAdminInfo (admin_username)' => ['admin.getAdminInfo', ['admin_username' => $ispName]],
        'admin.getAdminInfo (username)'     => ['admin.getAdminInfo', ['username' => $ispName]],
        'admin.searchAdmin (admin_username)' => ['admin.searchAdmin', ['conds' => ['admin_username' => $ispName], 'from' => 0, 'to' => 3]],
    ];
    foreach ($lookupMethods as $label => [$method, $params]) {
        $res = ibsng_call($method, $params);
        $ispIdLookup[$label] = $res;
    }
    $sanityChecks['isp_id_lookup_attempts'] = $ispIdLookup;
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

<h2>تست admin.getAdminInfo برای همه‌ی ISPها (خودکار، بدون نیاز به ورودی)</h2>
<p>ستون "تطابق؟" باید ✅ باشه یعنی admin_username برگشتی دقیقاً همون اسمیه که خواستیم. اگه برای چند ISP مختلف admin_id یکسان برگرده (مثلاً همه‌شون admin_id ISP اصلی/Main رو نشون بدن)، یعنی IBSng برای اسم‌های نامعتبر/غیردقیق یک fallback (احتمالاً اولین ادمین) برمی‌گردونه به‌جای خطا.</p>
<?php if(!empty($allIspLookup)):?>
<table style="width:100%;border-collapse:collapse;margin-bottom:20px" border="1" cellpadding="8">
<tr style="background:#1e293b"><th>اسم ISP درخواستی</th><th>admin_username برگشتی</th><th>admin_id برگشتی</th><th>isp_name برگشتی</th><th>تطابق؟</th><th>خطا</th></tr>
<?php foreach($allIspLookup as $label=>$c):?>
<tr>
  <td><code><?=htmlspecialchars($label)?></code></td>
  <td><?=htmlspecialchars((string)($c['returned_username']??'—'))?></td>
  <td><?=htmlspecialchars((string)($c['returned_admin_id']??'—'))?></td>
  <td><?=htmlspecialchars((string)($c['returned_isp_name']??'—'))?></td>
  <td><?=$c['username_matches']?'✅':'❌'?></td>
  <td><?=htmlspecialchars((string)($c['error']??''))?></td>
</tr>
<?php endforeach;?>
</table>
<?php else:?>
<p class="err">isp.getAllISPNames چیزی برنگردوند.</p>
<?php endif;?>

<h2>اسکن isp_id از ۱ تا ۵۰ روی user.searchUser (نگاشت واقعی اسم‌ISP ↔ isp_id)</h2>
<p>admin.getAdminInfo با admin_id عددی اصلاً کار نکرد (همون خطای «admin_username پیدا نشد» رو برای هر عددی داد). این جدول به‌جاش مستقیماً فیلتر isp_id روی user.searchUser رو (که ثابت‌شده کار می‌کنه) امتحان می‌کنه: برای هر عدد کاندید، یک کاربر نمونه می‌گیره و اسم واقعی ISP اون کاربر رو می‌خونه. اگه هر ۸ تا ISP اینجا دیده بشن، یعنی این راه‌حل کار می‌کنه.</p>
<?php if(!empty($ispIdScan)):?>
<table style="width:100%;border-collapse:collapse;margin-bottom:20px" border="1" cellpadding="8">
<tr style="background:#1e293b"><th>isp_id</th><th>total کاربر</th><th>isp_name واقعی</th></tr>
<?php foreach($ispIdScan as $row):?>
<tr>
  <td><?=htmlspecialchars((string)$row['isp_id'])?></td>
  <td><?=htmlspecialchars((string)($row['total']??'—'))?></td>
  <td><?=htmlspecialchars((string)($row['isp_name']??'—'))?></td>
</tr>
<?php endforeach;?>
</table>
<?php else:?>
<p class="err">هیچ isp_id ای (از ۱ تا ۵۰) کاربری برنگردوند.</p>
<?php endif;?>

<h2>تست چند شکل مختلف فیلتر ISP (بدون یوزرنیم)</h2>
<form method="GET">
  <input type="text" name="isp" placeholder="اسم دقیق ISP، مثلاً Milad" value="<?=htmlspecialchars($ispName)?>">
  <button type="submit">تست همه</button>
</form>
<?php if(!empty($ispCandidates)):?>
<p>هر ردیف یک شکل مختلف از conds هست. اگه یکیشون واقعاً درست باشه، total باید خیلی کمتر از کل کاربرها باشه و ستون "درسته؟" باید ✅ باشه.</p>
<table style="width:100%;border-collapse:collapse;margin-bottom:20px" border="1" cellpadding="8">
<tr style="background:#1e293b"><th>شکل conds</th><th>total</th><th>ISPهای واقعی برگشتی</th><th>درسته؟</th><th>خطا</th></tr>
<?php foreach($ispCandidates as $label=>$c):?>
<tr>
  <td><code><?=htmlspecialchars($label)?></code></td>
  <td><?=htmlspecialchars((string)($c['total']??'?'))?></td>
  <td><?=htmlspecialchars(implode(', ', $c['actual_isps']))?></td>
  <td><?=$c['looks_correct']?'✅':'❌'?></td>
  <td><?=htmlspecialchars((string)($c['error']??''))?></td>
</tr>
<?php endforeach;?>
</table>
<?php endif;?>

<?php if($ispNamesRaw!==null):?>
<h2>خروجی خام isp.getAllISPNames</h2>
<pre><?=htmlspecialchars(json_encode($ispNamesRaw, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre>
<?php endif;?>

<?php if(!empty($sanityChecks)):?>
<h2>تست‌های کمکی</h2>
<?php if(isset($sanityChecks['bogus_key_with_working_username_filter'])): $b=$sanityChecks['bogus_key_with_working_username_filter'];?>
<p>۱) فیلتر یوزرنیم "a" + یک کلید ناشناخته (zzz_bogus_field) با هم: total=<b><?=htmlspecialchars((string)($b['total']??'?'))?></b>
(اگه این عدد خیلی کمتر از کل کاربرهاست، یعنی فیلتر یوزرنیم درست کار می‌کنه حتی با وجود کلید ناشناخته - پس مشکل مختص isp_name است نه کل conds)</p>
<?php endif;?>
<?php if(isset($sanityChecks['group_name_filter_test'])): $g=$sanityChecks['group_name_filter_test'];?>
<p>۲) فیلتر group_name با گروه "<?=htmlspecialchars($g['tried_group'])?>": total=<b><?=htmlspecialchars((string)($g['total']??'?'))?></b>،
گروه‌های واقعی برگشتی: <b><?=htmlspecialchars(implode(', ', $g['actual_groups']))?></b>
(اگه همه‌شون همون گروه باشن یعنی group_name درست فیلتر می‌کنه و مشکل فقط مال ISP هست؛ اگه نه، یعنی همه‌ی فیلترهای چندانتخابی خراب‌ان)</p>
<?php endif;?>
<?php if(isset($sanityChecks['full_user_dump'])): $fd=$sanityChecks['full_user_dump'];?>
<p>۳) خروجی خام و کامل getUserInfo برای uid=<?=htmlspecialchars((string)$fd['uid'])?> (این کاربر توی همون ISP هست) - دنبال هر فیلد عددی مربوط به ISP بگرد (مثلاً چیزی شبیه isp_id):</p>
<pre><?=htmlspecialchars(json_encode($fd['raw'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre>
<?php endif;?>
<?php if(isset($sanityChecks['isp_id_guesses'])):?>
<p>۴) حدس عددی isp_id که total غیر از کل کاربرها داشتن:</p>
<pre><?=htmlspecialchars(json_encode($sanityChecks['isp_id_guesses'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre>
<?php else:?>
<p>۴) هیچ‌کدوم از isp_id های ۱ تا ۱۰ روی total تأثیری نداشتن (یا همه دقیقاً 15315 برگردوندن).</p>
<?php endif;?>
<?php if(isset($sanityChecks['isp_id_lookup_attempts'])):?>
<p>۵) تست چند متد مختلف برای پیدا کردن isp_id فقط از روی اسم ISP (بدون نیاز به کاربر از قبل):</p>
<pre><?=htmlspecialchars(json_encode($sanityChecks['isp_id_lookup_attempts'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre>
<?php endif;?>
<?php endif;?>
</body>
</html>

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

    // ۵) حدس زدن isp_id عددی (شاید ترتیب الفبایی اسم‌ها id واقعی نباشه، ولی امتحانش
    // ارزش داره) - برای هر عدد از ۱ تا ۱۰ چک می‌کنیم total چقدره
    $idGuesses = [];
    for ($i = 1; $i <= 10; $i++) {
        $rId = ibsng_call('user.searchUser', [
            'conds' => ['isp_id' => $i], 'from' => 0, 'to' => 3, 'order_by' => 'user_id', 'desc' => true,
        ]);
        $total = $rId['result'][0] ?? null;
        if ($total !== null && (int)$total !== 15315) {
            $idUids = $rId['result'][2] ?? [];
            $idActual = [];
            if (!empty($idUids)) {
                $iu = ibsng_call('user.getUserInfo', ['user_id' => implode(',', $idUids)]);
                foreach ($idUids as $u) {
                    $row = $iu['result'][$u] ?? $iu['result'][(string)$u] ?? null;
                    $idActual[] = $row['basic_info']['isp_name'] ?? '?';
                }
            }
            $idGuesses[$i] = ['total' => $total, 'actual_isps' => $idActual];
        }
    }
    if (!empty($idGuesses)) $sanityChecks['isp_id_guesses'] = $idGuesses;
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
<?php endif;?>
</body>
</html>

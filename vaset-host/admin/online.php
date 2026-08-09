<?php
require_once '../includes/config.php';
require_once '../includes/icons.php';
require_once '../includes/ibsng_api.php';
requireAdmin();

// AJAX: آمار ISP
if (isset($_GET['ajax']) && $_GET['ajax'] === 'isp_stats') {
    header('Content-Type: application/json');

    // به‌جای کش/تماس جداگانه، از ibsng_getOnlineRaw() استفاده می‌کنیم که خودش
    // کش ۳۰ ثانیه‌ای + تایم‌اوت کوتاه + circuit breaker (وقتی IBSng به این متد
    // جواب نمی‌ده، دیگه هر بار معطلش نمی‌مونیم) رو یک‌جا داره.
    $allOnline = ibsng_getOnlineRaw()['data'] ?? [];

    $totalOnline = count($allOnline);

    // تعداد آنلاین برای هر ISP
    $ispOnline = [];
    foreach ($allOnline as $u) {
        $isp = $u['isp_name'] ?? 'نامشخص';
        $ispOnline[$isp] = ($ispOnline[$isp] ?? 0) + 1;
    }

    // لیست ISP ها - فقط تعداد آنلاین هر کدام لازم است (تعداد کل کاربر دیگر نمایش
    // داده نمی‌شود، پس نیازی به یک فراخوانی searchUser جداگانه به‌ازای هر ISP نیست)
    $allIsps = ibsng_call('isp.getAllISPNames', []);
    $ispList = $allIsps['result'] ?? [];

    $rows = [];
    foreach ($ispList as $ispName) {
        $rows[] = ['isp' => $ispName, 'online' => (int)($ispOnline[$ispName] ?? 0)];
    }
    // ISP هایی که فقط آنلاین هستند ولی در لیست نبودند
    foreach ($ispOnline as $isp => $cnt) {
        if (!in_array($isp, $ispList)) {
            $rows[] = ['isp' => $isp, 'online' => $cnt];
        }
    }
    usort($rows, fn($a, $b) => $b['online'] <=> $a['online']);

    echo json_encode(['total_online' => $totalOnline, 'isp_count' => count($rows), 'rows' => $rows]);
    exit;
}

// AJAX: لیست کاربران آنلاین یک ISP خاص (تسک ۵)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'isp_users') {
    header('Content-Type: application/json');
    $ispFilter = trim($_GET['isp'] ?? '');
    $search    = trim($_GET['search'] ?? '');
    $onlineResult = ibsng_getOnlineRaw();
    if (!empty($onlineResult['error'])) {
        echo json_encode(['error' => $onlineResult['error'], 'rows' => []]);
        exit;
    }
    $rows = [];
    $usernames = [];
    foreach ($onlineResult['data'] as $u) {
        if ($ispFilter !== '' && ($u['isp_name'] ?? '') !== $ispFilter) continue;
        $un = $u['normal_username'] ?? $u['username'] ?? '—';
        if ($search !== '' && stripos($un, $search) === false) continue;
        $dur = (int)($u['duration_secs'] ?? 0);
        $usernames[] = $un;
        $rows[] = [
            'username' => $un,
            'ip'       => $u['remote_ip'] ?? $u['framed_ip_address'] ?? '—',
            'ras'      => $u['unique_id'] ?? $u['nas_ip_address'] ?? $u['nas_identifier'] ?? '—',
            'duration' => ibsng_formatDuration($dur),
            'group'    => $u['group_name'] ?? '—',
            'uid'      => null,
        ];
    }
    // uid هر ردیف رو از جدول کش محلی (که هر شب پر می‌شه) به‌جای یک تماس جدا برای
    // هر کاربر، با یک کوئری دسته‌ای می‌گیریم - برای دکمه‌ی Kill لازمه.
    if (!empty($usernames)) {
        $ph = implode(',', array_fill(0, count($usernames), '?'));
        $uStmt = $pdo->prepare("SELECT username, uid FROM ibsng_users_cache WHERE username IN ($ph)");
        $uStmt->execute($usernames);
        $uidMap = [];
        foreach ($uStmt->fetchAll() as $r) $uidMap[$r['username']] = $r['uid'];
        foreach ($rows as &$row) $row['uid'] = $uidMap[$row['username']] ?? null;
        unset($row);
    }
    echo json_encode(['rows' => $rows, 'total' => count($rows)]);
    exit;
}

// AJAX: Kill کردن یک کاربر آنلاین
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kill_user') {
    header('Content-Type: application/json');
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'درخواست نامعتبر است، صفحه را رفرش کنید.']);
        exit;
    }
    $uid = sanitize($_POST['user_id'] ?? '');
    if ($uid === '') { echo json_encode(['ok' => false, 'error' => 'کاربر نامعتبر']); exit; }
    $rKick = ibsng_kickUser($uid);
    if ($rKick['error'] ?? null) {
        echo json_encode(['ok' => false, 'error' => $rKick['error']]);
    } else {
        logActivity('admin', $_SESSION['admin_id'], 'kill_user', "کاربر #$uid از قسمت کاربران آنلاین Kick شد");
        echo json_encode(['ok' => true, 'method' => $rKick['method'] ?? '?']);
    }
    exit;
}

if (isset($_GET['refresh_cache'])) {
    foreach ([sys_get_temp_dir() . '/ibs_online_cache.json'] as $cf) {
        if (file_exists($cf)) unlink($cf);
    }
    header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit;
}

$pendingCount = $pdo->query("SELECT COUNT(*) FROM payment_requests WHERE status='pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>کاربران آنلاین</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<link href="../assets/css/theme.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#080c18;--sb:#0d1424;--card:#131e30;--surf:#0d1a2a;--bor:#1e2d45;--acc:#3b82f6;--acc2:#06b6d4;--pur:#8b5cf6;--txt:#e2e8f0;--txt2:#94a3b8;--muted:#475569;--red:#ef4444;--grn:#10b981;--yel:#f59e0b;--sw:260px}
html,body{overflow-x:hidden}
body{font-family:'Vazirmatn',sans-serif;background:var(--bg);color:var(--txt);min-height:100vh}
aside{width:var(--sw);background:var(--sb);border-left:1px solid var(--bor);position:fixed;right:0;top:0;bottom:0;display:flex;flex-direction:column;z-index:100}
@media(max-width:768px){aside{transform:translateX(100%);transition:.3s}aside.open{transform:none}}
.hamburger{display:none;position:fixed;top:14px;right:14px;z-index:101;background:var(--sb);border:1px solid var(--bor);border-radius:9px;padding:8px 10px;cursor:pointer;color:var(--txt);font-size:18px}
@media(max-width:768px){.hamburger{display:block}main{margin-right:0!important}}
.logo{padding:20px;border-bottom:1px solid var(--bor)}
.logo-t{font-size:17px;font-weight:900;background:linear-gradient(135deg,var(--acc),var(--acc2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.logo-b{font-size:10px;color:var(--muted);-webkit-text-fill-color:var(--muted)}
nav{flex:1;padding:12px 10px;overflow-y:auto}
.ns{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);padding:8px 12px 4px;font-weight:600}
.ni{display:flex;align-items:center;gap:10px;padding:10px 13px;border-radius:9px;color:var(--txt2);text-decoration:none;font-size:13px;font-weight:500;transition:all .2s;margin-bottom:2px}
.ni:hover{background:rgba(59,130,246,.08);color:var(--txt)}
.ni.active{background:rgba(59,130,246,.15);color:var(--acc)}
.pb{background:rgba(245,158,11,.2);color:var(--yel);padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;margin-right:auto}
.sf{padding:12px 10px;border-top:1px solid var(--bor)}
.ai{display:flex;align-items:center;gap:9px;padding:9px 12px;border-radius:9px;background:rgba(255,255,255,.03);margin-bottom:4px}
.av{width:32px;height:32px;background:linear-gradient(135deg,var(--acc),var(--pur));border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px}
.logout{color:var(--red)!important}
main{margin-right:var(--sw);flex:1;min-width:0}
.topbar{background:var(--sb);border-bottom:1px solid var(--bor);padding:14px 20px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;gap:10px;flex-wrap:wrap}
.pg-t{font-size:17px;font-weight:700}
.content{padding:20px}
.btn{padding:8px 14px;border-radius:9px;font-family:'Vazirmatn';font-size:13px;font-weight:700;cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:6px;text-decoration:none;white-space:nowrap}
.bp{background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff}
.bg{background:rgba(16,185,129,.15);color:var(--grn);border:1px solid rgba(16,185,129,.3)}
.bc{background:rgba(6,182,212,.15);color:#22d3ee;border:1px solid rgba(6,182,212,.3)}
.bd{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.3)}
.bsm{padding:5px 8px;font-size:11px;border-radius:7px}
/* stats top */
.stats-top{display:flex;gap:14px;margin-bottom:20px;flex-wrap:wrap}
.sc{background:var(--card);border:1px solid var(--bor);border-radius:12px;padding:18px 28px;text-align:center;flex:1;min-width:130px}
.sv{font-size:34px;font-weight:900;line-height:1}
.sl{font-size:11px;color:var(--muted);margin-top:4px}
/* ISP grid */
.isp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.isp-card{background:var(--card);border:1px solid var(--bor);border-radius:12px;padding:16px 18px;transition:border .2s}
.isp-card:hover{border-color:var(--acc)}
.isp-name{font-size:13px;font-weight:700;color:var(--txt);margin-bottom:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.isp-nums{display:flex;gap:10px}
.isp-num{flex:1;text-align:center;background:var(--surf);border-radius:8px;padding:8px 4px}
.isp-num .n{font-size:26px;font-weight:900;line-height:1}
.isp-num .l{font-size:10px;color:var(--muted);margin-top:2px}
.n-online{color:var(--grn)}
.n-total{color:var(--acc)}
.isp-users-list table{width:100%;border-collapse:collapse;margin-top:6px}
.isp-users-list th{font-size:10px;color:var(--muted);padding:4px 6px;background:rgba(0,0,0,.3);text-align:right}
.isp-users-list td{font-size:11px;padding:4px 6px;border-bottom:1px solid rgba(30,48,50,.3);color:var(--txt2)}
.isp-card.expanded{border-color:var(--acc)}
.loading{text-align:center;padding:40px;color:var(--muted);font-size:14px}
.sbox{background:var(--card);border:1px solid var(--bor);border-radius:12px;padding:12px 14px;margin-bottom:16px}
.srow{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.si{padding:9px 12px;background:var(--surf);border:1px solid var(--bor);border-radius:8px;color:var(--txt);font-family:'Vazirmatn';font-size:13px;outline:none;transition:border .2s;flex:1;min-width:150px}
.si:focus{border-color:var(--acc)}
.card{background:var(--card);border:1px solid var(--bor);border-radius:12px;overflow:hidden}
.tw{overflow-x:auto}
</style>
</head>
<body class="theme-admin">
<button class="hamburger" onclick="document.getElementById('sidebar').classList.toggle('open')" title="منو">☰</button>
<aside id="sidebar">
  <div class="logo">
    <?php $siteLogo=getSetting('site_logo',''); if($siteLogo&&file_exists(dirname(__DIR__).'/'.$siteLogo)): ?>
    <img src="../<?=sanitize($siteLogo)?>" alt="لوگو" style="max-height:52px;max-width:180px;object-fit:contain;margin-bottom:4px;display:block">
    <?php else: ?>
    <div class="logo-t">⚡ پنل مدیریت</div>
    <?php endif; ?>
    <div class="logo-b">سیستم مدیریت اینترنت</div>
  </div>
  <nav>
    <div class="ns">اصلی</div>
    <a href="dashboard.php" class="ni"><?=svgIcon('dashboard')?> داشبورد</a>
    <div class="ns">مدیریت</div>
    <a href="resellers.php" class="ni"><?=svgIcon('users')?> ریسلرها</a>
    <a href="users.php" class="ni"><?=svgIcon('user')?> کاربران</a>
    <a href="online.php" class="ni active">🟢 کاربران آنلاین</a>
    <div class="ns">مالی</div>
    <a href="transactions.php" class="ni"><?=svgIcon('card')?> تراکنش‌ها</a>
    <a href="renewals.php" class="ni"><?=svgIcon('refresh')?> کاربران تمدیدشده</a>
    <a href="debts.php" class="ni"><?=svgIcon('wallet')?> مدیریت موجودی</a>
    <a href="payments.php" class="ni"><?=svgIcon('receipt')?> فیش پرداخت <?php if($pendingCount>0):?><span class="pb"><?=$pendingCount?></span><?php endif;?></a>
    <div class="ns">فروش مستقیم تلگرام</div>
    <a href="direct_packages.php" class="ni"><?=svgIcon('box')?> بسته‌های فروش مستقیم</a>
    <a href="direct_orders.php" class="ni"><?=svgIcon('cart')?> سفارش‌های مستقیم</a>
    <a href="telegram.php" class="ni"><?=svgIcon('bot')?> ربات تلگرام</a>
    <div class="ns">سیستم</div>
    <a href="settings.php" class="ni"><?=svgIcon('gear')?> تنظیمات</a>
    <a href="logs.php" class="ni"><?=svgIcon('list')?> لاگ‌ها</a>
  </nav>
  <div class="sf">
    <div class="ai"><div class="av"><?=svgIcon('shield')?></div>
      <div><div style="font-size:13px;font-weight:600"><?=$_SESSION['admin_username']?></div><div style="font-size:11px;color:var(--muted)">مدیر اصلی</div></div>
    </div>
    <a href="logout.php" class="ni logout"><?=svgIcon('logout')?> خروج</a>
  </div>
</aside>

<main>
  <div class="topbar">
    <div class="pg-t">🟢 کاربران آنلاین <span id="hCnt" style="font-size:13px;color:var(--grn)"></span></div>
    <div style="display:flex;gap:7px">
      <span id="autoTxt" style="font-size:12px;color:var(--muted);align-self:center"></span>
      <button class="btn bg bsm" onclick="refresh()" title="بروزرسانی لیست"><?=svgIcon('refresh')?> بروزرسانی</button>
      <button class="btn bc bsm" id="autoBtn" onclick="toggleAuto()" title="بروزرسانی خودکار">⏱ خودکار</button>
    </div>
  </div>
  <div class="content">
    <div class="stats-top">
      <div class="sc"><div class="sv" style="color:var(--grn)" id="sTotalOnline">—</div><div class="sl">کل آنلاین</div></div>
      <div class="sc"><div class="sv" style="color:var(--acc)" id="sTotalIsps">—</div><div class="sl">تعداد ISP</div></div>
    </div>
    <div class="sbox">
      <div class="srow">
        <input type="text" class="si" id="srch" placeholder="🔍 جستجوی نام کاربری در بین همه‌ی ISPها...">
        <button class="btn bc bsm" onclick="document.getElementById('srch').value='';doSearch()">✕ پاک</button>
      </div>
    </div>
    <div id="ispContainer" class="isp-grid"><div class="loading">⏳ در حال بارگذاری...</div></div>
    <div id="resultsContainer" style="margin-top:16px"></div>
  </div>
</main>

<script>
const CSRF_TOKEN = <?=json_encode(generateCsrf())?>;
function killUser(uid, username, afterFn){
  if(!uid){alert('شناسه‌ی این کاربر پیدا نشد (شاید هنوز توی کش سینک نشده)');return;}
  if(!confirm('اتصال آنلاین کاربر «'+username+'» قطع بشه؟'))return;
  const fd=new FormData();
  fd.append('csrf_token',CSRF_TOKEN);
  fd.append('action','kill_user');
  fd.append('user_id',uid);
  fetch('online.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.ok){ if(afterFn) afterFn(); else load(); }
    else alert('خطا: '+(d.error||'نامشخص'));
  }).catch(()=>alert('خطا در اتصال به سرور'));
}
let autoInt=null;
let curIsp='';

// رندر مشترک نتایج (چه از جستجوی سراسری، چه از کلیک روی یک ISP) توی یک جدول
// تمام‌عرض زیر گرید ISPها - جای کافی برای همه‌ی ستون‌ها (از جمله دکمه‌ی Kill)
// داره، برخلاف حالت قبلی که سعی می‌کرد جدول رو داخل خودِ کارت کوچیک ISP جا بده.
function renderResults(url){
  var el = document.getElementById('resultsContainer');
  el.innerHTML = '<div class="loading">⏳ در حال بارگذاری...</div>';
  fetch(url).then(function(r){return r.json();}).then(function(d){
    if(d.error){ el.innerHTML='<div class="loading"><?=svgIcon('warning')?> '+d.error+'</div>'; return; }
    if(!d.rows || !d.rows.length){ el.innerHTML='<div class="loading"><?=svgIcon('signal')?> هیچ کاربری آنلاین نیست</div>'; return; }
    var html='<div class="card"><div class="tw"><table class="isp-users-list" style="width:100%"><thead><tr>'
      +'<th><?=svgIcon('user')?> کاربر</th><th><?=svgIcon('globe')?> IP</th><th>⏱ مدت</th><th><?=svgIcon('box')?> گروه</th><th>عملیات</th></tr></thead><tbody>';
    d.rows.forEach(function(u,i){
      html+='<tr><td><strong>'+u.username+'</strong></td><td style="font-family:monospace">'+u.ip+'</td><td>'+u.duration+'</td><td>'+u.group+'</td>'
        +'<td><button class="btn bd bsm" data-i="'+i+'" title="Kill (قطع اتصال)">⚡ Kill</button></td></tr>';
    });
    html+='</tbody></table></div></div><div style="text-align:left;font-size:10px;color:var(--muted);padding:6px 2px">'+d.total+' کاربر</div>';
    el.innerHTML = html;
    el.querySelectorAll('button[data-i]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var u = d.rows[parseInt(btn.getAttribute('data-i'),10)];
        killUser(u.uid, u.username, function(){ renderResults(url); });
      });
    });
  }).catch(function(){
    el.innerHTML = '<div class="loading">❌ خطا در بارگذاری</div>';
  });
}

function selectIsp(isp, cardEl){
  curIsp = isp;
  document.getElementById('srch').value = '';
  document.querySelectorAll('.isp-card').forEach(function(c){ c.classList.remove('expanded'); });
  if (cardEl) cardEl.classList.add('expanded');
  renderResults('online.php?ajax=isp_users&isp=' + encodeURIComponent(isp));
}

function doSearch(){
  const s = document.getElementById('srch').value.trim();
  curIsp = '';
  document.querySelectorAll('.isp-card').forEach(function(c){ c.classList.remove('expanded'); });
  if (s === '') { document.getElementById('resultsContainer').innerHTML = ''; return; }
  renderResults('online.php?ajax=isp_users&isp=&search=' + encodeURIComponent(s));
}
var srchT=null;
document.getElementById('srch').addEventListener('input',function(){
  clearTimeout(srchT);
  srchT=setTimeout(doSearch,500);
});
function load(){
  fetch('online.php?ajax=isp_stats').then(r=>r.json()).then(d=>{
    document.getElementById('hCnt').textContent='('+d.total_online+')';
    document.getElementById('sTotalOnline').textContent=d.total_online;
    document.getElementById('sTotalIsps').textContent=d.isp_count;
    if(!d.rows||!d.rows.length){
      document.getElementById('ispContainer').innerHTML='<div class="loading">هیچ ISP فعالی یافت نشد</div>';
      return;
    }
    document.getElementById('ispContainer').innerHTML=d.rows.map(function(r){
      var active = (r.isp === curIsp) ? ' expanded' : '';
      return '<div class="isp-card'+active+'" onclick="selectIsp(\''+r.isp.replace(/'/g,"\\'")+'\', this)" style="cursor:pointer">'
        +'<div class="isp-name"><?=svgIcon('globe')?> '+r.isp+'</div>'
        +'<div class="isp-nums"><div class="isp-num"><div class="n n-online">'+r.online+'</div><div class="l">آنلاین</div></div></div>'
        +'</div>';
    }).join('');
  }).catch(e=>{
    document.getElementById('ispContainer').innerHTML='<div class="loading">❌ خطا در بارگذاری</div>';
  });
}
function refresh(){
  fetch('online.php?refresh_cache=1').then(function(){
    load();
    var s = document.getElementById('srch').value.trim();
    if (s !== '') doSearch();
    else if (curIsp !== '') renderResults('online.php?ajax=isp_users&isp=' + encodeURIComponent(curIsp));
  });
}
function toggleAuto(){
  if(autoInt){clearInterval(autoInt);autoInt=null;document.getElementById('autoBtn').textContent='⏱ خودکار';document.getElementById('autoTxt').textContent='';}
  else{autoInt=setInterval(()=>{refresh();},15000);document.getElementById('autoBtn').textContent='⏹ توقف';document.getElementById('autoTxt').textContent='هر ۱۵ ثانیه';}
}

load();
</script>
</body>
</html>

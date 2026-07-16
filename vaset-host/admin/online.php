<?php
require_once '../includes/config.php';
require_once '../includes/ibsng_api.php';
requireAdmin();

// AJAX: آمار ISP
if (isset($_GET['ajax']) && $_GET['ajax'] === 'isp_stats') {
    header('Content-Type: application/json');

    // از ibsng_getOnlineRawResolved استفاده می‌کنیم (isp_name هر رکورد
    // resolve/تصحیح‌شده) - قبلاً اینجا یک کش/فچ جداگانه و تکراری داشت که isp_name
    // رو مستقیم و بدون تصحیح از report.getOnlineUsers می‌خوند؛ چون اون فیلد قابل
    // اعتماد نبود، همه‌چیز توی سطل "نامشخص" می‌ریخت و شمارش هر ISP صفر می‌شد.
    $onlineResult = ibsng_getOnlineRawResolved();
    if (!empty($onlineResult['error'])) {
        echo json_encode(['error' => $onlineResult['error'], 'total_online' => 0, 'isp_count' => 0, 'rows' => []]);
        exit;
    }
    $allOnline = $onlineResult['data'];

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
    $onlineResult = ibsng_getOnlineRawResolved();
    if (!empty($onlineResult['error'])) {
        echo json_encode(['error' => $onlineResult['error'], 'rows' => []]);
        exit;
    }
    $rows = [];
    foreach ($onlineResult['data'] as $u) {
        if ($ispFilter !== '' && ($u['isp_name'] ?? '') !== $ispFilter) continue;
        $dur = (int)($u['duration_secs'] ?? 0);
        $rows[] = [
            'id'       => $u['user_id'] ?? '',
            'username' => $u['normal_username'] ?? $u['username'] ?? '—',
            'ip'       => $u['remote_ip'] ?? $u['framed_ip_address'] ?? '—',
            'ras'      => $u['unique_id'] ?? $u['nas_ip_address'] ?? $u['nas_identifier'] ?? '—',
            'duration' => ibsng_formatDuration($dur),
            'group'    => $u['group_name'] ?? '—',
        ];
    }
    echo json_encode(['rows' => $rows, 'total' => count($rows)]);
    exit;
}

// AJAX: Kick کردن کاربر مستقیم از همین صفحه
if (isset($_GET['ajax']) && $_GET['ajax'] === 'kick') {
    header('Content-Type: application/json');
    if (!verifyCsrf($_GET['csrf_token'] ?? $_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'درخواست نامعتبر است']); exit;
    }
    $uid = trim($_GET['uid'] ?? $_POST['uid'] ?? '');
    if ($uid === '') { echo json_encode(['ok' => false, 'error' => 'کاربر مشخص نشده']); exit; }
    $r = ibsng_kickUser($uid);
    if ($r['error'] ?? null) { echo json_encode(['ok' => false, 'error' => $r['error']]); exit; }
    echo json_encode(['ok' => true, 'method' => $r['method'] ?? null]);
    exit;
}

if (isset($_GET['refresh_cache'])) {
    foreach ([
        sys_get_temp_dir() . '/ibs_online_cache.json', // فایل کش قدیمیِ دیگه‌استفاده‌نشده (برای پاکسازی)
        IBS_CACHE_DIR . 'online_all.json',
        IBS_CACHE_DIR . 'online_all_resolved.json',
        IBS_CACHE_DIR . 'online_isp_map.json',
        IBS_CACHE_DIR . 'online_uid_map.json',
    ] as $cf) {
        if (file_exists($cf)) @unlink($cf);
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
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#080c18;--sb:#0d1424;--card:#131e30;--surf:#0d1a2a;--bor:#1e2d45;--acc:#3b82f6;--acc2:#06b6d4;--pur:#8b5cf6;--txt:#e2e8f0;--txt2:#94a3b8;--muted:#475569;--red:#ef4444;--grn:#10b981;--yel:#f59e0b;--sw:260px}
body{font-family:'Vazirmatn',sans-serif;background:var(--bg);color:var(--txt);display:flex;min-height:100vh}
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
</style>
</head>
<body>
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
    <a href="dashboard.php" class="ni">📊 داشبورد</a>
    <div class="ns">مدیریت</div>
    <a href="resellers.php" class="ni">👥 ریسلرها</a>
    <a href="users.php" class="ni">🧑‍💻 کاربران</a>
    <a href="online.php" class="ni active">🟢 کاربران آنلاین</a>
    <div class="ns">مالی</div>
    <a href="transactions.php" class="ni">💳 تراکنش‌ها</a>
    <a href="debts.php" class="ni">💰 مدیریت موجودی</a>
    <a href="payments.php" class="ni">🧾 فیش پرداخت <?php if($pendingCount>0):?><span class="pb"><?=$pendingCount?></span><?php endif;?></a>
    <div class="ns">فروش مستقیم تلگرام</div>
    <a href="direct_packages.php" class="ni">📦 بسته‌های فروش مستقیم</a>
    <a href="direct_orders.php" class="ni">🛒 سفارش‌های مستقیم</a>
    <a href="telegram.php" class="ni">🤖 ربات تلگرام</a>
    <div class="ns">سیستم</div>
    <a href="settings.php" class="ni">⚙️ تنظیمات</a>
    <a href="logs.php" class="ni">📋 لاگ‌ها</a>
  </nav>
  <div class="sf">
    <div class="ai"><div class="av">🛡️</div>
      <div><div style="font-size:13px;font-weight:600"><?=$_SESSION['admin_username']?></div><div style="font-size:11px;color:var(--muted)">مدیر اصلی</div></div>
    </div>
    <a href="logout.php" class="ni logout">🚪 خروج</a>
  </div>
</aside>

<main>
  <div class="topbar">
    <div class="pg-t">🟢 کاربران آنلاین <span id="hCnt" style="font-size:13px;color:var(--grn)"></span></div>
    <div style="display:flex;gap:7px">
      <span id="autoTxt" style="font-size:12px;color:var(--muted);align-self:center"></span>
      <button class="btn bg bsm" onclick="refresh()" title="بروزرسانی لیست">🔄 بروزرسانی</button>
      <button class="btn bc bsm" id="autoBtn" onclick="toggleAuto()" title="بروزرسانی خودکار">⏱ خودکار</button>
    </div>
  </div>
  <div class="content">
    <div class="stats-top">
      <div class="sc"><div class="sv" style="color:var(--grn)" id="sTotalOnline">—</div><div class="sl">کل آنلاین</div></div>
      <div class="sc"><div class="sv" style="color:var(--acc)" id="sTotalIsps">—</div><div class="sl">تعداد ISP</div></div>
    </div>
    <div id="ispContainer" class="isp-grid"><div class="loading">⏳ در حال بارگذاری...</div></div>
  </div>
</main>

<script>
let autoInt=null;
const CSRF_TOKEN=<?=json_encode(generateCsrf())?>;
function load(){
  fetch('online.php?ajax=isp_stats').then(r=>r.json()).then(d=>{
    document.getElementById('hCnt').textContent='('+d.total_online+')';
    document.getElementById('sTotalOnline').textContent=d.total_online;
    document.getElementById('sTotalIsps').textContent=d.isp_count;
    if(!d.rows||!d.rows.length){
      document.getElementById('ispContainer').innerHTML='<div class="loading">هیچ ISP فعالی یافت نشد</div>';
      return;
    }
    document.getElementById('ispContainer').innerHTML=d.rows.map(r=>`
      <div class="isp-card" onclick="toggleIspUsers('${r.isp.replace(/'/g,"\\'")}',this)" style="cursor:pointer">
        <div class="isp-name">🌐 ${r.isp}</div>
        <div class="isp-nums">
          <div class="isp-num"><div class="n n-online">${r.online}</div><div class="l">آنلاین</div></div>
        </div>
        <div class="isp-users-list" id="ul_${encodeURIComponent(r.isp)}" style="display:none;margin-top:10px"></div>
      </div>`).join('');
  }).catch(e=>{
    document.getElementById('ispContainer').innerHTML='<div class="loading">❌ خطا در بارگذاری</div>';
  });
}
function refresh(){fetch('online.php?refresh_cache=1').then(()=>load());}
function toggleAuto(){
  if(autoInt){clearInterval(autoInt);autoInt=null;document.getElementById('autoBtn').textContent='⏱ خودکار';document.getElementById('autoTxt').textContent='';}
  else{autoInt=setInterval(()=>{refresh();},15000);document.getElementById('autoBtn').textContent='⏹ توقف';document.getElementById('autoTxt').textContent='هر ۱۵ ثانیه';}
}
function toggleIspUsers(isp, card) {
  var key = encodeURIComponent(isp);
  var el  = document.getElementById('ul_'+key);
  if (!el) return;
  var showing = el.style.display !== 'none';
  if (showing) {
    el.style.display = 'none';
    card.classList.remove('expanded');
    return;
  }
  card.classList.add('expanded');
  el.style.display = '';
  el.innerHTML = '<div style="text-align:center;padding:8px;color:var(--muted);font-size:11px">⏳ در حال بارگذاری...</div>';
  fetch('online.php?ajax=isp_users&isp='+encodeURIComponent(isp))
    .then(r=>r.json()).then(d=>{
      if(!d.rows||!d.rows.length){
        el.innerHTML='<div style="text-align:center;padding:8px;color:var(--muted);font-size:11px">📡 هیچ کاربری آنلاین نیست</div>';
        return;
      }
      var html='<table><thead><tr><th>👤 کاربر</th><th>🌐 IP</th><th>⏱ مدت</th><th>📦 گروه</th><th></th></tr></thead><tbody>';
      d.rows.forEach(u=>{
        var kickBtn=u.id?('<button class="btn bd bsm" onclick=\'event.stopPropagation();kickOnlineUser("'+u.id+'","'+u.username.replace(/"/g,'&quot;')+'",this)\' title="Kick">⚡ Kick</button>'):'';
        html+='<tr><td><strong>'+u.username+'</strong></td><td style="font-family:monospace">'+u.ip+'</td><td>'+u.duration+'</td><td>'+u.group+'</td><td onclick="event.stopPropagation()">'+kickBtn+'</td></tr>';
      });
      html+='</tbody></table><div style="text-align:left;font-size:10px;color:var(--muted);padding:3px 6px">'+d.total+' کاربر آنلاین</div>';
      el.innerHTML=html;
    }).catch(()=>{
      el.innerHTML='<div style="text-align:center;color:var(--red);font-size:11px">❌ خطا</div>';
    });
}

function kickOnlineUser(uid,un,btn){
  if(!confirm('اتصال آنلاین کاربر «'+un+'» قطع بشه؟'))return;
  btn.disabled=true; btn.textContent='...';
  fetch('online.php?ajax=kick',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'uid='+encodeURIComponent(uid)+'&csrf_token='+encodeURIComponent(CSRF_TOKEN)})
    .then(r=>r.json()).then(d=>{
      if(d.ok){
        var row=btn.closest('tr'); if(row) row.style.opacity='0.4';
        btn.textContent='✅ شد';
      } else {
        alert('خطا در Kick: '+(d.error||'نامشخص'));
        btn.disabled=false; btn.textContent='⚡ Kick';
      }
    }).catch(()=>{alert('خطا در اتصال');btn.disabled=false;btn.textContent='⚡ Kick';});
}

load();
</script>
</body>
</html>

<?php
require_once '../includes/config.php';
require_once '../includes/ibsng_api.php';
requireReseller();

// ─── اطلاعات ریسلر ───
$rid     = (int)$_SESSION['reseller_id'];
$stmt    = $pdo->prepare("SELECT * FROM resellers WHERE id=?");
$stmt->execute([$rid]);
$resellerRow = $stmt->fetch();
$ispName = trim($resellerRow['isp_name'] ?? '');

// ═══════════════════════════════════════════════════════════
// AJAX: آمار کاربران آنلاین ISP ریسلر — مثل ادمین
// ═══════════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'online_stats') {
    header('Content-Type: application/json');

    // ─── استفاده از تابع بهبودیافته که ISP را فیلتر می‌کند ───
    $onlineResult = ibsng_getOnlineForIsp($ispName);

    if (!empty($onlineResult['error'])) {
        echo json_encode(['error' => $onlineResult['error'], 'rows' => [], 'total_online' => 0]);
        exit;
    }

    $myOnline = $onlineResult['data'];

    // فیلتر جستجو روی همان کاربرهایی که نمایش داده می‌شوند
    $search = trim($_GET['search'] ?? '');
    if ($search !== '') {
        $tmp = [];
        foreach ($myOnline as $u) {
            $un = $u['normal_username'] ?? $u['username'] ?? '';
            if (stripos($un, $search) !== false) $tmp[] = $u;
        }
        $filtered = $tmp;
    } else {
        $filtered = $myOnline;
    }

    // فیلتر گروه روی همان کاربرهایی که نمایش داده می‌شوند
    $grpFilter = trim($_GET['group'] ?? '');
    if ($grpFilter !== '') {
        $tmp = [];
        foreach ($filtered as $u) {
            if (($u['group_name'] ?? '') === $grpFilter) $tmp[] = $u;
        }
        $filtered = $tmp;
    }

    // آمار گروه‌ها (از myOnline نه filtered)
    $grpStats = [];
    foreach ($myOnline as $u) {
        $g = $u['group_name'] ?? 'نامشخص';
        if (!isset($grpStats[$g])) $grpStats[$g] = 0;
        $grpStats[$g]++;
    }
    arsort($grpStats);

    // ساخت ردیف‌ها
    $rows = [];
    foreach ($filtered as $u) {
        $dur    = (int)($u['duration_secs'] ?? 0);
        $durStr = ibsng_formatDuration($dur);
        $rows[] = [
            'username' => $u['normal_username'] ?? $u['username'] ?? '—',
            'ip'       => $u['remote_ip'] ?? $u['framed_ip_address'] ?? '—',
            'ras'      => $u['unique_id'] ?? $u['nas_ip_address'] ?? $u['nas_identifier'] ?? '—',
            'duration' => $durStr,
            'dur_secs' => $dur,
            'group'    => $u['group_name'] ?? '—',
            'isp'      => $u['isp_name']   ?? '—',
        ];
    }

    echo json_encode([
        'total_online' => count($myOnline),
        'total_shown'  => count($rows),
        'grp_stats'    => $grpStats,
        'rows'         => $rows,
    ]);
    exit;
}

if (isset($_GET['refresh_cache'])) {
    $cf = sys_get_temp_dir() . '/ibs_online_cache.json';
    if (file_exists($cf)) @unlink($cf);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}
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
:root{--bg:#08100a;--sb:#0c1810;--card:#111f14;--surf:#0c1810;--bor:#1e3025;--acc:#10b981;--acc2:#06b6d4;--pur:#8b5cf6;--txt:#e2e8f0;--txt2:#94a3b8;--muted:#475569;--red:#ef4444;--grn:#10b981;--yel:#f59e0b;--sw:260px}
body{font-family:'Vazirmatn',sans-serif;background:var(--bg);color:var(--txt);display:flex;min-height:100vh}
aside{width:var(--sw);background:var(--sb);border-left:1px solid var(--bor);position:fixed;right:0;top:0;bottom:0;display:flex;flex-direction:column;z-index:100}
.logo{padding:20px;border-bottom:1px solid var(--bor)}
.logo-t{font-size:17px;font-weight:900;background:linear-gradient(135deg,var(--acc),var(--acc2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.logo-b{font-size:10px;color:var(--muted);-webkit-text-fill-color:var(--muted)}
nav{flex:1;padding:12px 10px;overflow-y:auto}
.ns{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);padding:8px 12px 4px;font-weight:600}
.ni{display:flex;align-items:center;gap:10px;padding:10px 13px;border-radius:9px;color:var(--txt2);text-decoration:none;font-size:13px;font-weight:500;transition:all .2s;margin-bottom:2px}
.ni:hover{background:rgba(16,185,129,.08);color:var(--txt)}
.ni.active{background:rgba(16,185,129,.15);color:var(--acc)}
.logout{color:var(--red)!important}
.sf{padding:12px 10px;border-top:1px solid var(--bor)}
.ai{display:flex;align-items:center;gap:9px;padding:9px 12px;border-radius:9px;background:rgba(255,255,255,.03);margin-bottom:4px}
.av{width:32px;height:32px;background:linear-gradient(135deg,var(--acc),var(--acc2));border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px}
main{margin-right:var(--sw);flex:1;min-width:0}
.topbar{background:var(--sb);border-bottom:1px solid var(--bor);padding:14px 20px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;gap:10px;flex-wrap:wrap}
.pg-t{font-size:17px;font-weight:700}
.content{padding:20px}
.btn{padding:8px 14px;border-radius:9px;font-family:'Vazirmatn';font-size:13px;font-weight:700;cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:6px;text-decoration:none;white-space:nowrap}
.bp{background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff}
.bg{background:rgba(16,185,129,.15);color:var(--grn);border:1px solid rgba(16,185,129,.3)}
.bc{background:rgba(6,182,212,.15);color:#22d3ee;border:1px solid rgba(6,182,212,.3)}
.bsm{padding:5px 8px;font-size:11px;border-radius:7px}
.stats-top{display:flex;gap:14px;margin-bottom:18px;flex-wrap:wrap}
.sc{background:var(--card);border:1px solid var(--bor);border-radius:12px;padding:18px 28px;text-align:center;flex:1;min-width:120px}
.sv{font-size:34px;font-weight:900;line-height:1}
.sl{font-size:11px;color:var(--muted);margin-top:4px}
.isp-bar{background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.25);border-radius:10px;padding:11px 16px;margin-bottom:18px;display:flex;align-items:center;gap:12px}
.sbox{background:var(--card);border:1px solid var(--bor);border-radius:12px;padding:12px 14px;margin-bottom:12px}
.srow{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.si{padding:9px 12px;background:var(--surf);border:1px solid var(--bor);border-radius:8px;color:var(--txt);font-family:'Vazirmatn';font-size:13px;outline:none;transition:border .2s;flex:1;min-width:150px}
.si:focus{border-color:var(--acc)}
.grp-btns{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px;align-items:center}
.gf{padding:5px 11px;border-radius:20px;font-size:11px;font-weight:600;cursor:pointer;border:1px solid var(--bor);background:var(--card);color:var(--txt2);font-family:'Vazirmatn';transition:.2s}
.gf:hover,.gf.on{background:var(--acc);color:#fff;border-color:var(--acc)}
.card{background:var(--card);border:1px solid var(--bor);border-radius:12px;overflow:hidden}
.tw{overflow-x:auto}
table.t{width:100%;border-collapse:collapse;min-width:520px}
table.t th{text-align:right;font-size:11px;font-weight:600;color:var(--muted);padding:10px 11px;border-bottom:1px solid var(--bor);background:rgba(0,0,0,.2);white-space:nowrap}
table.t td{padding:9px 11px;font-size:12px;border-bottom:1px solid rgba(30,48,37,.5);color:var(--txt2);vertical-align:middle}
table.t tr:last-child td{border-bottom:none}
table.t tr:hover td{background:rgba(16,185,129,.02)}
.badge{display:inline-block;padding:3px 7px;border-radius:20px;font-size:10px;font-weight:600}
.bpu2{background:rgba(139,92,246,.1);color:#a78bfa;border:1px solid rgba(139,92,246,.2)}
.ras-b{font-family:monospace;font-size:10px;background:rgba(245,158,11,.07);color:var(--yel);padding:2px 6px;border-radius:4px;border:1px solid rgba(245,158,11,.2)}
.od{display:inline-block;width:7px;height:7px;background:var(--grn);border-radius:50%;margin-left:5px;animation:bk 2s infinite}
@keyframes bk{0%,100%{opacity:1}50%{opacity:.3}}
.loading{text-align:center;padding:40px;color:var(--muted);font-size:14px}
.a-err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5;padding:11px 14px;border-radius:9px;margin-bottom:14px;font-size:13px}
</style>
</head>
<body>
<aside>
  <div class="logo">
    <?php $sL=getSetting('site_logo',''); if($sL&&file_exists(dirname(__DIR__).'/'.$sL)):?>
    <img src="../<?=sanitize($sL)?>" alt="" style="max-height:52px;max-width:180px;object-fit:contain;margin-bottom:4px;display:block">
    <?php else:?><div class="logo-t">🌐 پنل ریسلر</div><?php endif;?>
    <div class="logo-b">کاربران آنلاین</div>
  </div>
  <nav>
    <div class="ns">اصلی</div>
    <a href="dashboard.php" class="ni">📊 داشبورد</a>
    <div class="ns">کاربران</div>
    <a href="users.php" class="ni">👥 مدیریت کاربران</a>
    <a href="online.php" class="ni active">🟢 کاربران آنلاین</a>
    <div class="ns">مالی</div>
    <a href="transactions.php" class="ni">💳 تراکنش‌ها</a>
    <a href="payments.php" class="ni">🧾 ارسال فیش</a>
  </nav>
  <div class="sf">
    <div class="ai">
      <div class="av">👤</div>
      <div>
        <div style="font-size:13px;font-weight:600"><?=sanitize($_SESSION['reseller_username'])?></div>
        <div style="font-size:11px;color:var(--muted)">ریسلر</div>
      </div>
    </div>
    <a href="logout.php" class="ni logout">🚪 خروج</a>
  </div>
</aside>

<main>
  <div class="topbar">
    <div class="pg-t">🟢 کاربران آنلاین <span id="hCnt" style="font-size:13px;color:var(--grn)"></span></div>
    <div style="display:flex;gap:7px;align-items:center">
      <span id="autoTxt" style="font-size:12px;color:var(--muted)"></span>
      <button class="btn bg bsm" onclick="refresh()" title="بروزرسانی لیست">🔄 بروزرسانی</button>
      <button class="btn bc bsm" id="autoBtn" onclick="toggleAuto()" title="بروزرسانی خودکار">⏱ خودکار</button>
    </div>
  </div>
  <div class="content">

    <?php if($ispName!==''):?>
    <div class="isp-bar">
      <span style="font-size:20px">🌐</span>
      <div>
        <div style="font-weight:700;font-size:14px"><?=sanitize($ispName)?></div>
        <div style="font-size:11px;color:var(--muted)">ISP اختصاصی شما</div>
      </div>
    </div>
    <?php endif;?>

    <div class="stats-top">
      <div class="sc"><div class="sv" style="color:var(--grn)" id="sTotalOnline">—</div><div class="sl">کل آنلاین</div></div>

    </div>

    <!-- جستجو -->
    <div class="sbox">
      <div class="srow">
        <input type="text" class="si" id="srch" placeholder="🔍 جستجو نام کاربری...">
        <button class="btn bp bsm" onclick="loadData()" title="جستجو">🔍</button>
        <button class="btn bc bsm" onclick="document.getElementById('srch').value='';curGrp='';loadData()">✕ پاک</button>
      </div>
    </div>

    <!-- فیلتر گروه -->
    <div class="grp-btns" id="grpBtns"></div>

    <!-- خطا -->
    <div id="errBox" style="display:none" class="a-err"></div>

    <!-- جدول -->
    <div id="container"><div class="loading">⏳ در حال بارگذاری...</div></div>
  </div>
</main>

<script>
var autoInt=null, curGrp='';

function loadData(){
  var s=document.getElementById('srch').value.trim();
  var url='online.php?ajax=online_stats&search='+encodeURIComponent(s)+'&group='+encodeURIComponent(curGrp);
  fetch(url).then(function(r){return r.json();}).then(function(d){
    document.getElementById('errBox').style.display='none';
    if(d.error){
      document.getElementById('errBox').style.display='';
      document.getElementById('errBox').textContent='⚠️ خطا: '+d.error;
      document.getElementById('container').innerHTML='';
      return;
    }
    document.getElementById('hCnt').textContent='('+d.total_online+')';
    document.getElementById('sTotalOnline').textContent=d.total_online;


    // دکمه‌های گروه
    var gs=d.grp_stats||{};
    var gHtml='<span style="font-size:11px;color:var(--muted)">📦</span>';
    gHtml+='<button class="gf'+(curGrp===''?' on':'')+'" onclick="setGrp(\'\',this)">همه ('+d.total_online+')</button>';
    Object.keys(gs).forEach(function(g){
      gHtml+='<button class="gf'+(curGrp===g?' on':'')+'" onclick="setGrp(\''+g.replace(/'/g,"\\'")+ '\',this)">'+g+' ('+gs[g]+')</button>';
    });
    document.getElementById('grpBtns').innerHTML=gHtml;

    if(!d.rows||!d.rows.length){
      document.getElementById('container').innerHTML='<div class="loading">📡 هیچ کاربری آنلاین نیست</div>';
      return;
    }
    var html='<div class="card"><div class="tw"><table class="t"><thead><tr>'
      +'<th>👤 کاربر</th><th>🌐 IP</th><th>📡 RAS</th><th>⏱ مدت</th><th>📦 گروه</th><th>🌐 ISP</th>'
      +'</tr></thead><tbody>';
    d.rows.forEach(function(u){
      html+='<tr>'
        +'<td><span class="od"></span><strong style="color:var(--txt)">'+u.username+'</strong></td>'
        +'<td style="font-family:monospace;font-size:11px">'+u.ip+'</td>'
        +'<td><span class="ras-b">'+u.ras+'</span></td>'
        +'<td style="color:var(--acc2);font-weight:600">'+u.duration+'</td>'
        +'<td><span class="badge bpu2">'+u.group+'</span></td>'
        +'<td style="font-size:11px;color:var(--acc2)">'+u.isp+'</td>'
        +'</tr>';
    });
    html+='</tbody></table></div></div>';
    html+='<div style="text-align:center;margin-top:9px;font-size:11px;color:var(--muted)">نمایش '+d.rows.length+' کاربر آنلاین</div>';
    document.getElementById('container').innerHTML=html;
  }).catch(function(e){
    document.getElementById('errBox').style.display='';
    document.getElementById('errBox').textContent='⚠️ خطا در اتصال به سرور';
    document.getElementById('container').innerHTML='';
  });
}

function setGrp(g,el){
  curGrp=g;
  document.querySelectorAll('.gf').forEach(function(b){b.classList.remove('on');});
  if(el) el.classList.add('on');
  loadData();
}
function refresh(){
  fetch('online.php?refresh_cache=1').then(function(){loadData();});
}
function toggleAuto(){
  if(autoInt){
    clearInterval(autoInt); autoInt=null;
    document.getElementById('autoBtn').textContent='⏱ خودکار';
    document.getElementById('autoTxt').textContent='';
  } else {
    autoInt=setInterval(function(){refresh();},15000);
    document.getElementById('autoBtn').textContent='⏹ توقف';
    document.getElementById('autoTxt').textContent='هر ۱۵ ثانیه';
  }
}

// جستجو با debounce
var srchT=null;
document.getElementById('srch').addEventListener('input',function(){
  clearTimeout(srchT);
  srchT=setTimeout(function(){loadData();},500);
});

loadData();
</script>
</body>
</html>

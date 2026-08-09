<?php
require_once '../includes/config.php';
requireReseller();

$rid = (int)$_SESSION['reseller_id'];
$stmt = $pdo->prepare("SELECT * FROM resellers WHERE id=?");
$stmt->execute([$rid]);
$resellerRow = $stmt->fetch();
$balance = (float)($resellerRow['balance'] ?? 0);
$ispName = trim($resellerRow['isp_name'] ?? '');

$from = trim($_GET['from'] ?? date('Y-m-d', strtotime('-30 days')));
$to   = trim($_GET['to']   ?? date('Y-m-d'));
$search = trim($_GET['search'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$page    = max(0, (int)($_GET['page'] ?? 0));
$perPage = 50;

// renewed_by IN ('reseller','telegram') چون تمدیدهایی که از طریق بات تلگرام
// اختصاصی همین ریسلر انجام می‌شن هم با renewed_by='telegram' ثبت می‌شن (تا
// کانال دقیق مشخص بمونه) ولی reseller_id همون ریسلر رو داره - باید توی
// گزارش خودش هم دیده بشن.
$conds = ["renewed_by IN ('reseller','telegram')", 'reseller_id = ?', 'created_at >= ?', 'created_at < ?'];
$params = [$rid, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
if ($search !== '') { $conds[] = 'ibs_username LIKE ?'; $params[] = '%' . $search . '%'; }
$where = implode(' AND ', $conds);

$totalRow = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(price),0) s FROM renewal_logs WHERE $where");
$totalRow->execute($params);
$totalRow = $totalRow->fetch();
$totalCount = (int)$totalRow['c'];
$totalPrice = (float)$totalRow['s'];
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page = min($page, $totalPages - 1);

$stmt = $pdo->prepare("SELECT * FROM renewal_logs WHERE $where ORDER BY created_at DESC LIMIT $perPage OFFSET " . ($page * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>کاربران تمدیدشده - پنل ریسلر</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<link href="../assets/css/theme.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#08100a;--sb:#0c1810;--card:#111f14;--surf:#0c1810;--bor:#1e3025;--acc:#10b981;--acc2:#06b6d4;--pur:#8b5cf6;--txt:#e2e8f0;--txt2:#94a3b8;--muted:#475569;--red:#ef4444;--grn:#10b981;--yel:#f59e0b;--sw:260px}
html,body{overflow-x:hidden}
body{font-family:'Vazirmatn',sans-serif;background:var(--bg);color:var(--txt);min-height:100vh}
aside{width:var(--sw);background:var(--sb);border-left:1px solid var(--bor);position:fixed;right:0;top:0;bottom:0;display:flex;flex-direction:column;z-index:100}
@media(max-width:768px){aside{transform:translateX(100%);transition:.3s} aside.open{transform:none}}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
.hamburger{display:none;position:fixed;top:14px;right:14px;z-index:101;background:var(--sb);border:1px solid var(--bor);border-radius:9px;padding:8px 10px;cursor:pointer;color:var(--txt);font-size:18px}
@media(max-width:768px){.hamburger{display:block} main{margin-right:0!important}}
.logo{padding:20px;border-bottom:1px solid var(--bor)}
.logo-t{font-size:17px;font-weight:900;background:linear-gradient(135deg,var(--acc),var(--acc2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.logo-b{font-size:10px;color:var(--muted);-webkit-text-fill-color:var(--muted)}
.bcard{margin:14px 16px;padding:14px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);border-radius:12px}
.blbl{font-size:11px;color:var(--txt2);margin-bottom:4px}
.bval{font-size:19px;font-weight:900;color:var(--grn)}
.bval.low{color:var(--red)}
nav{flex:1;padding:12px 10px;overflow-y:auto}
.ns{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);padding:8px 12px 4px;font-weight:600}
.ni{display:flex;align-items:center;gap:10px;padding:10px 13px;border-radius:9px;color:var(--txt2);text-decoration:none;font-size:13px;font-weight:500;transition:all .2s;margin-bottom:2px}
.ni:hover{background:rgba(16,185,129,.08);color:var(--txt)}
.ni.active{background:rgba(16,185,129,.15);color:var(--acc)}
.sf{padding:12px 10px;border-top:1px solid var(--bor)}
.ai{display:flex;align-items:center;gap:9px;padding:9px 12px;border-radius:9px;background:rgba(255,255,255,.03);margin-bottom:4px}
.av{width:32px;height:32px;background:linear-gradient(135deg,var(--acc),var(--pur));border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px}
.logout{color:var(--red)!important}
main{margin-right:var(--sw);flex:1;min-width:0}
.topbar{background:var(--sb);border-bottom:1px solid var(--bor);padding:14px 20px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;gap:10px;flex-wrap:wrap}
.pg-t{font-size:17px;font-weight:700}
.content{padding:20px}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px}
@media(max-width:768px){.stats-row{grid-template-columns:1fr}}
.stat-card{background:var(--card);border:1px solid var(--bor);border-radius:14px;padding:16px 18px;display:flex;align-items:center;gap:14px}
.stat-icon{font-size:26px}
.stat-value{font-size:21px;font-weight:900}
.stat-label{font-size:12px;color:var(--txt2)}
.sbox{background:var(--card);border:1px solid var(--bor);border-radius:12px;padding:14px;margin-bottom:14px}
.srow{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
.fg2{display:flex;flex-direction:column;gap:5px}
.lbl{font-size:11px;font-weight:600;color:var(--txt2)}
.si{padding:9px 12px;background:var(--surf);border:1px solid var(--bor);border-radius:8px;color:var(--txt);font-family:'Vazirmatn';font-size:13px;outline:none;transition:border .2s}
.si:focus{border-color:var(--acc)}
.si-wide{flex:1;min-width:150px}
.btn{padding:9px 16px;border-radius:9px;font-family:'Vazirmatn';font-size:13px;font-weight:700;cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:6px;text-decoration:none;white-space:nowrap}
.bp{background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff}
.card{background:var(--card);border:1px solid var(--bor);border-radius:12px;overflow:hidden}
.tw{overflow-x:auto}
table.t{width:100%;border-collapse:collapse;min-width:550px}
table.t th{text-align:right;font-size:11px;font-weight:600;color:var(--muted);padding:10px 11px;border-bottom:1px solid var(--bor);background:rgba(0,0,0,.2);white-space:nowrap}
table.t td{padding:9px 11px;font-size:12px;border-bottom:1px solid rgba(30,45,69,.5);color:var(--txt2);vertical-align:middle}
table.t tr:last-child td{border-bottom:none}
table.t tr:hover td{background:rgba(16,185,129,.02)}
.pag{display:flex;gap:4px;justify-content:center;padding:14px;flex-wrap:wrap}
.pag a,.pag span{padding:6px 11px;border-radius:7px;font-size:12px;background:var(--surf);border:1px solid var(--bor);color:var(--txt2);cursor:pointer;text-decoration:none}
.pag a.active,.pag span.active{background:var(--acc);color:#fff;border-color:var(--acc)}
</style>
</head>
<body class="theme-reseller">
<div class="overlay" id="overlay" onclick="closeSB()"></div>
<button class="hamburger" onclick="toggleSB()" title="منو">☰</button>

<aside id="sidebar">
  <div class="logo">
    <?php $sL=getSetting('site_logo',''); if($sL&&file_exists(dirname(__DIR__).'/'.$sL)):?>
    <img src="../<?=sanitize($sL)?>" alt="" style="max-height:52px;max-width:180px;object-fit:contain;margin-bottom:4px;display:block">
    <?php else:?><div class="logo-t">🌐 پنل ریسلر</div><?php endif;?>
    <div class="logo-b">مدیریت کاربران</div>
  </div>
  <div class="bcard">
    <div class="blbl">💰 موجودی</div>
    <div class="bval <?=$balance<=0?'low':''?>"><?=money($balance)?> <small style="font-size:10px;font-weight:400">تومان</small></div>
    <?php if($ispName!==''):?><div style="font-size:10px;color:var(--muted);margin-top:3px">🌐 ISP: <?=sanitize($ispName)?></div><?php endif;?>
  </div>
  <nav>
    <div class="ns">اصلی</div>
    <a href="dashboard.php" class="ni">📊 داشبورد</a>
    <div class="ns">کاربران</div>
    <a href="users.php" class="ni">🧑‍💻 مدیریت کاربران</a>
    <a href="online.php" class="ni">🟢 کاربران آنلاین</a>
    <div class="ns">مالی</div>
    <a href="transactions.php" class="ni">💳 تراکنش‌ها</a>
    <a href="renewals.php" class="ni active">🔄 کاربران تمدیدشده</a>
    <a href="payments.php" class="ni">🧾 ارسال فیش</a>
    <div class="ns">فروش مستقیم تلگرام</div>
    <a href="direct_orders.php" class="ni">🛒 سفارش‌های مستقیم</a>
    <a href="telegram.php" class="ni">🤖 بات تلگرام من</a>
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
    <div class="pg-t">🔄 کاربران تمدیدشده</div>
  </div>
  <div class="content">
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon">🔄</div>
        <div><div class="stat-value" style="color:#34d399"><?=money($totalCount)?></div><div class="stat-label">تعداد تمدید در بازه</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">💰</div>
        <div><div class="stat-value" style="color:#34d399"><?=money($totalPrice)?></div><div class="stat-label">مجموع مبلغ (تومان)</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">📅</div>
        <div><div class="stat-value" style="color:#f59e0b;font-size:14px"><?=sanitize($from)?> تا <?=sanitize($to)?></div><div class="stat-label">بازه‌ی انتخابی</div></div>
      </div>
    </div>

    <form method="GET" class="sbox">
      <div class="srow">
        <div class="fg2"><label class="lbl">از تاریخ</label><input class="si" type="date" name="from" value="<?=sanitize($from)?>"></div>
        <div class="fg2"><label class="lbl">تا تاریخ</label><input class="si" type="date" name="to" value="<?=sanitize($to)?>"></div>
        <div class="fg2 si-wide"><label class="lbl">جستجوی نام کاربری</label><input class="si" type="text" name="search" placeholder="نام کاربری..." value="<?=sanitize($search)?>"></div>
        <button type="submit" class="btn bp">🔍 اعمال فیلتر</button>
      </div>
    </form>

    <div class="card">
      <div class="tw">
        <table class="t">
          <thead>
            <tr>
              <th>#</th>
              <th>نام کاربری</th>
              <th>گروه</th>
              <th>مبلغ (تومان)</th>
              <th>تاریخ تمدید</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $i => $row): ?>
            <tr>
              <td style="color:var(--muted)"><?=$page*$perPage+$i+1?></td>
              <td style="font-weight:600;color:var(--txt)"><?=sanitize($row['ibs_username'])?></td>
              <td><?=sanitize($row['group_name'])?></td>
              <td><?=$row['price']>0?money($row['price']):'—'?></td>
              <td style="font-size:11px;color:var(--muted)"><?=date('Y/m/d H:i',strtotime($row['created_at']))?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--muted)">در این بازه تمدیدی ثبت نشده</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($totalPages > 1): ?>
      <div class="pag">
        <?php
        $qs = $_GET; unset($qs['page']);
        $base = '?' . http_build_query($qs) . '&page=';
        for ($p = 0; $p < $totalPages; $p++):
        ?>
        <a class="<?=$p===$page?'active':''?>" href="<?=$base.$p?>"><?=$p+1?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>
<script>
function toggleSB(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').style.display='block'}
function closeSB(){document.getElementById('sidebar').classList.remove('open');document.getElementById('overlay').style.display='none'}
</script>
</body></html>

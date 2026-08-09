<?php
require_once '../includes/config.php';
requireAdmin();

$transactions = $pdo->query("SELECT t.*, r.username as reseller_name, u.username as user_name FROM transactions t LEFT JOIN resellers r ON t.reseller_id=r.id LEFT JOIN users u ON t.user_id=u.id ORDER BY t.created_at DESC LIMIT 200")->fetchAll();

$totalDebit = $pdo->query("SELECT SUM(amount) FROM transactions WHERE type IN ('user_create','user_renew','debit')")->fetchColumn() ?: 0;
$totalCredit = $pdo->query("SELECT SUM(amount) FROM transactions WHERE type='credit'")->fetchColumn() ?: 0;
$totalTx = count($transactions);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>تراکنش‌ها - پنل مدیریت</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<link href="../assets/css/theme.css" rel="stylesheet">
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{--bg:#080c18;--sidebar:#0d1424;--card:#131e30;--border:#1e2d45;--accent:#3b82f6;--accent2:#06b6d4;--purple:#8b5cf6;--gold:#f59e0b;--text:#e2e8f0;--text2:#94a3b8;--muted:#475569;--danger:#ef4444;--success:#10b981;--warning:#f59e0b;--sidebar-w:260px}
  html,body{overflow-x:hidden}
  body{font-family:'Vazirmatn',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
  .sidebar{width:var(--sidebar-w);background:var(--sidebar);border-left:1px solid var(--border);position:fixed;right:0;top:0;bottom:0;display:flex;flex-direction:column;z-index:100}
  .sidebar-logo{padding:28px 24px;border-bottom:1px solid var(--border)}
  .logo-text{font-size:20px;font-weight:900;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
  .logo-badge{font-size:10px;color:var(--muted);-webkit-text-fill-color:var(--muted)}
  .sidebar-nav{flex:1;padding:16px 12px;overflow-y:auto}
  .nav-section-label{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);padding:8px 12px 4px;font-weight:600}
  .nav-item{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:10px;color:var(--text2);text-decoration:none;font-size:14px;font-weight:500;transition:all .2s;margin-bottom:2px}
  .nav-item:hover{background:rgba(59,130,246,.08);color:var(--text)}
  .nav-item.active{background:rgba(59,130,246,.15);color:var(--accent)}
  .sidebar-footer{padding:16px 12px;border-top:1px solid var(--border)}
  .admin-info{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;background:rgba(255,255,255,.03);margin-bottom:4px}
  .admin-avatar{width:36px;height:36px;background:linear-gradient(135deg,var(--accent),var(--purple));border-radius:10px;display:flex;align-items:center;justify-content:center}
  .admin-name{font-size:13px;font-weight:600}
  .admin-role{font-size:11px;color:var(--muted)}
  .logout-btn{color:var(--danger)!important}
  .main{margin-right:var(--sidebar-w);flex:1}
  .topbar{background:var(--sidebar);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
  .page-title{font-size:20px;font-weight:700}
  .content{padding:32px}
  .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
  .stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;display:flex;align-items:center;gap:16px}
  .stat-icon{font-size:28px}
  .stat-value{font-size:24px;font-weight:900}
  .stat-label{font-size:13px;color:var(--text2)}
  .card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden}
  .toolbar{display:flex;gap:12px;margin-bottom:20px}
  .search-box{position:relative;flex:1}
  .search-box input{width:100%;padding:11px 42px 11px 16px;background:var(--card);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:'Vazirmatn';font-size:14px;outline:none}
  .search-box input:focus{border-color:var(--accent)}
  .search-icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--muted)}
  .table-wrapper{overflow-x:auto}
  .table{width:100%;border-collapse:collapse}
  .table th{text-align:right;font-size:12px;font-weight:600;color:var(--muted);padding:14px 16px;border-bottom:1px solid var(--border);background:rgba(0,0,0,.2);white-space:nowrap}
  .table td{padding:14px 16px;font-size:13px;border-bottom:1px solid rgba(30,45,69,.5);color:var(--text2);vertical-align:middle}
  .table tr:last-child td{border-bottom:none}
  .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
  .badge-create{background:rgba(6,182,212,.1);color:#22d3ee;border:1px solid rgba(6,182,212,.2)}
  .badge-renew{background:rgba(139,92,246,.1);color:#a78bfa;border:1px solid rgba(139,92,246,.2)}
  .badge-credit{background:rgba(16,185,129,.1);color:var(--success);border:1px solid rgba(16,185,129,.2)}
  .badge-debit{background:rgba(239,68,68,.1);color:var(--danger);border:1px solid rgba(239,68,68,.2)}
  .amount-pos{color:var(--success);font-weight:700}
  .amount-neg{color:var(--danger);font-weight:700}
</style>
</head>
<body class="theme-admin">
<aside class="sidebar">
    <div class="sidebar-logo">
    <?php $siteLogo=getSetting('site_logo',''); if($siteLogo&&file_exists(dirname(__DIR__).'/'.$siteLogo)): ?>
    <img src="../<?=sanitize($siteLogo)?>" alt="لوگو" style="max-height:52px;max-width:180px;object-fit:contain;margin-bottom:4px;display:block">
    <?php else: ?>
    <div class="logo-text">⚡ پنل مدیریت</div>
    <?php endif; ?>
    <div class="logo-badge">سیستم مدیریت اینترنت</div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section-label">اصلی</div>
    <a href="dashboard.php" class="nav-item">📊 داشبورد</a>
    <div class="nav-section-label">مدیریت</div>
    <a href="resellers.php" class="nav-item">👥 ریسلرها</a>
    <a href="users.php" class="nav-item">🧑‍💻 کاربران</a>
    <a href="online.php" class="nav-item">🟢 کاربران آنلاین</a>
    <div class="nav-section-label">مالی</div>
    <a href="transactions.php" class="nav-item active">💳 تراکنش‌ها</a>
    <a href="renewals.php" class="nav-item">🔄 کاربران تمدیدشده</a>
    <a href="debts.php" class="nav-item">💰 مدیریت بدهی</a>
    <div class="nav-section-label">فروش مستقیم تلگرام</div>
    <a href="direct_packages.php" class="nav-item">📦 بسته‌های فروش مستقیم</a>
    <a href="direct_orders.php" class="nav-item">🛒 سفارش‌های مستقیم</a>
    <a href="telegram.php" class="nav-item">🤖 ربات تلگرام</a>
    <div class="nav-section-label">سیستم</div>
    <a href="logs.php" class="nav-item">📋 لاگ فعالیت‌ها</a>
    <a href="settings.php" class="nav-item">⚙️ تنظیمات</a>
  </nav>
  <div class="sidebar-footer">
    <div class="admin-info">
      <div class="admin-avatar">🛡️</div>
      <div><div class="admin-name"><?= $_SESSION['admin_username'] ?></div><div class="admin-role">مدیر اصلی</div></div>
    </div>
    <a href="logout.php" class="nav-item logout-btn">🚪 خروج</a>
  </div>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title">💳 تراکنش‌های مالی</div>
  </div>
  <div class="content">
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon">📊</div>
        <div><div class="stat-value" style="color:#60a5fa"><?= $totalTx ?></div><div class="stat-label">کل تراکنش‌ها</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">📉</div>
        <div><div class="stat-value" style="color:#f87171"><?= money($totalDebit) ?></div><div class="stat-label">کل بدهکاری (تومان)</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">📈</div>
        <div><div class="stat-value" style="color:#34d399"><?= money($totalCredit) ?></div><div class="stat-label">کل پرداختی (تومان)</div></div>
      </div>
    </div>

    <div class="toolbar">
      <div class="search-box">
        <span class="search-icon">🔍</span>
        <input type="text" placeholder="جستجو در تراکنش‌ها..." oninput="filterTable(this.value)">
      </div>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="table" id="txTable">
          <thead>
            <tr>
              <th>#</th>
              <th>ریسلر</th>
              <th>کاربر</th>
              <th>نوع</th>
              <th>توضیح</th>
              <th>مبلغ (تومان)</th>
              <th>تاریخ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($transactions as $i => $t):
              $isPos = $t['type'] === 'credit';
              $typeMap = ['user_create'=>['🆕 ایجاد کاربر','create'],'user_renew'=>['🔄 تمدید','renew'],'credit'=>['✅ پرداخت','credit'],'debit'=>['➖ بدهی','debit'],'charge'=>['💰 شارژ','credit']];
              $typeInfo = $typeMap[$t['type']] ?? [$t['type'],'debit'];
            ?>
            <tr>
              <td style="color:var(--muted)"><?= $i+1 ?></td>
              <td style="color:var(--accent);font-weight:600"><?= sanitize($t['reseller_name'] ?? '—') ?></td>
              <td><?= sanitize($t['user_name'] ?? '—') ?></td>
              <td><span class="badge badge-<?= $typeInfo[1] ?>"><?= $typeInfo[0] ?></span></td>
              <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= sanitize($t['description'] ?? '—') ?></td>
              <td class="<?= $isPos?'amount-pos':'amount-neg' ?>"><?= $isPos?'+':'-' ?><?= money($t['amount']) ?></td>
              <td style="font-size:12px;color:var(--muted)"><?= date('Y/m/d H:i',strtotime($t['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($transactions)): ?>
            <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted)">هنوز تراکنشی ثبت نشده</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
<script>
function filterTable(q){q=q.toLowerCase();document.querySelectorAll('#txTable tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'})}
</script>
</body></html>

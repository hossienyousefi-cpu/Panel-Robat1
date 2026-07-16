<?php
require_once '../includes/config.php';
requireAdmin();

$logs = $pdo->query("SELECT l.*, CASE WHEN l.actor_type='admin' THEN a.username ELSE r.username END as actor_name FROM activity_logs l LEFT JOIN admins a ON l.actor_type='admin' AND l.actor_id=a.id LEFT JOIN resellers r ON l.actor_type='reseller' AND l.actor_id=r.id ORDER BY l.created_at DESC LIMIT 500")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>لاگ فعالیت‌ها - پنل مدیریت</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{--bg:#080c18;--sidebar:#0d1424;--card:#131e30;--border:#1e2d45;--accent:#3b82f6;--accent2:#06b6d4;--purple:#8b5cf6;--text:#e2e8f0;--text2:#94a3b8;--muted:#475569;--danger:#ef4444;--success:#10b981;--sidebar-w:260px}
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
  .toolbar{display:flex;gap:12px;margin-bottom:20px}
  .search-box{position:relative;flex:1}
  .search-box input{width:100%;padding:11px 42px 11px 16px;background:var(--card);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:'Vazirmatn';font-size:14px;outline:none}
  .search-box input:focus{border-color:var(--accent)}
  .search-icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--muted)}
  .card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden}
  .table-wrapper{overflow-x:auto}
  .table{width:100%;border-collapse:collapse}
  .table th{text-align:right;font-size:12px;font-weight:600;color:var(--muted);padding:14px 16px;border-bottom:1px solid var(--border);background:rgba(0,0,0,.2);white-space:nowrap}
  .table td{padding:12px 16px;font-size:13px;border-bottom:1px solid rgba(30,45,69,.5);color:var(--text2);vertical-align:middle}
  .table tr:last-child td{border-bottom:none}
  .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
  .badge-admin{background:rgba(59,130,246,.1);color:#60a5fa;border:1px solid rgba(59,130,246,.2)}
  .badge-reseller{background:rgba(139,92,246,.1);color:#a78bfa;border:1px solid rgba(139,92,246,.2)}
</style>
</head>
<body>
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
    <a href="transactions.php" class="nav-item">💳 تراکنش‌ها</a>
    <a href="debts.php" class="nav-item">💰 مدیریت بدهی</a>
    <div class="nav-section-label">فروش مستقیم تلگرام</div>
    <a href="direct_packages.php" class="nav-item">📦 بسته‌های فروش مستقیم</a>
    <a href="direct_orders.php" class="nav-item">🛒 سفارش‌های مستقیم</a>
    <a href="telegram.php" class="nav-item">🤖 ربات تلگرام</a>
    <div class="nav-section-label">سیستم</div>
    <a href="logs.php" class="nav-item active">📋 لاگ فعالیت‌ها</a>
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
    <div class="page-title">📋 لاگ فعالیت‌ها</div>
    <span style="color:var(--muted);font-size:13px"><?= count($logs) ?> رکورد</span>
  </div>
  <div class="content">
    <div class="toolbar">
      <div class="search-box">
        <span class="search-icon">🔍</span>
        <input type="text" placeholder="جستجو..." oninput="filterTable(this.value)">
      </div>
    </div>
    <div class="card">
      <div class="table-wrapper">
        <table class="table" id="logsTable">
          <thead>
            <tr>
              <th>#</th>
              <th>کاربر</th>
              <th>نوع</th>
              <th>عملیات</th>
              <th>جزئیات</th>
              <th>IP</th>
              <th>زمان</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($logs as $i => $l): ?>
            <tr>
              <td style="color:var(--muted)"><?= $i+1 ?></td>
              <td style="font-weight:600;color:var(--text)"><?= sanitize($l['actor_name'] ?? '—') ?></td>
              <td><span class="badge badge-<?= $l['actor_type'] ?>"><?= $l['actor_type']==='admin'?'🛡️ ادمین':'👤 ریسلر' ?></span></td>
              <td style="color:var(--accent)"><?= sanitize($l['action']) ?></td>
              <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted)"><?= sanitize($l['details'] ?? '—') ?></td>
              <td style="font-family:monospace;font-size:12px"><?= sanitize($l['ip_address'] ?? '—') ?></td>
              <td style="font-size:12px;color:var(--muted)"><?= date('Y/m/d H:i',strtotime($l['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($logs)): ?>
            <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted)">هنوز لاگی ثبت نشده</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
<script>
function filterTable(q){q=q.toLowerCase();document.querySelectorAll('#logsTable tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'})}
</script>
</body></html>

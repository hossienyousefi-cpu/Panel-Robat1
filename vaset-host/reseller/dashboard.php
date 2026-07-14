<?php
require_once '../includes/config.php';
require_once '../includes/ibsng_api.php';
requireReseller();

$rid = $_SESSION['reseller_id'];

// Get reseller info
$reseller = $pdo->prepare("SELECT * FROM resellers WHERE id=?");
$reseller->execute([$rid]);
$reseller = $reseller->fetch();

// Stats
// «کل کاربران من» باید تعداد واقعی کاربرهای این ISP توی IBSng باشه، نه شمارش
// جدول محلی users (که فقط کاربرهایی رو داره که از همین پنل ساخته شدن - برای
// کاربرهایی که قبلاً توی IBSng بودن یا از پنل اصلی ساخته شدن صفر/خیلی کم بود).
$totalUsers = ibsng_getIspUserCount($reseller['isp_name'] ?? '');

$expiredUsers = $pdo->prepare("SELECT COUNT(*) FROM users WHERE reseller_id=? AND (status='expired' OR expire_date < CURDATE())");
$expiredUsers->execute([$rid]); $expiredUsers = $expiredUsers->fetchColumn();

// کاربران رو به اتمام (۷ روز آینده) از خودِ IBSng - نه جدول محلی users (که فقط
// کاربرهای ساخته‌شده از همین پنل رو داره).
$expiringSoon = [];
$ispName_dash0 = $reseller['isp_name'] ?? '';
if ($ispName_dash0 !== '') {
    $now    = date('Y/m/d');
    $future = date('Y/m/d', strtotime('+7 days'));
    $rExp = ibsng_call('user.searchExpiredUsersExtended', [
        'conds' => ['exp_date_from' => $now, 'exp_date_from_unit' => 'gregorian',
                    'exp_date_to'   => $future, 'exp_date_to_unit' => 'gregorian'],
        'from' => 0, 'to' => 200, 'order_by' => 'user_id', 'desc' => false,
    ]);
    $expUsers = $rExp['result'][2] ?? [];
    if (!empty($expUsers)) {
        $infExp = ibsng_call('user.getUserInfo', ['user_id' => implode(',', array_keys($expUsers))]);
        $infosExp = $infExp['result'] ?? [];
        foreach (array_keys($expUsers) as $uidExp) {
            $uExp = $infosExp[$uidExp] ?? null; if (!$uExp) continue;
            $basicExp = $uExp['basic_info'] ?? []; $attrsExp = $uExp['attrs'] ?? [];
            if (($basicExp['isp_name'] ?? '') !== $ispName_dash0) continue;
            $expDate = $basicExp['nearest_exp_date'] ?? '';
            $expiringSoon[] = ['username' => $attrsExp['normal_username'] ?? '—', 'expire_date' => $expDate ? substr($expDate, 0, 10) : '—'];
        }
    }
}

// کاربران آنلاین از IBSng
$onlineUsers = [];
$onlineCount = 0;
try {
    $ispName_dash = $reseller['isp_name'] ?? '';
    $r_online = ibsng_call('report.getOnlineUsers',['normal_sort_by'=>'username','normal_desc'=>false,'voip_sort_by'=>'username','voip_desc'=>false,'conds'=>[]]);
    $raw_online = is_array($r_online['result'][0]??null) ? $r_online['result'][0] : [];
    // دریافت لیست کاربران این ریسلر
    $myUsernames2 = $pdo->prepare("SELECT ibs_username FROM users WHERE reseller_id=?");
    $myUsernames2->execute([$rid]);
    $myUsernames2 = array_column($myUsernames2->fetchAll(), 'ibs_username');
    $seen = [];
    foreach ($raw_online as $ou) {
        $un = $ou['normal_username'] ?? $ou['username'] ?? '';
        if (isset($seen[$un])) continue;
        $seen[$un] = true;
        // فقط کاربران ISP این ریسلر
        if ($ispName_dash && ($ou['isp_name'] ?? '') !== $ispName_dash) continue;
        $onlineUsers[] = $ou;
        $onlineCount++;
    }
} catch(Exception $e) {}

// Recent transactions
$transactions = $pdo->prepare("SELECT t.*, u.username as user_name FROM transactions t LEFT JOIN users u ON t.user_id=u.id WHERE t.reseller_id=? ORDER BY t.created_at DESC LIMIT 8");
$transactions->execute([$rid]); $transactions = $transactions->fetchAll();

// Recent users
$recentUsers = $pdo->prepare("SELECT * FROM users WHERE reseller_id=? ORDER BY created_at DESC LIMIT 5");
$recentUsers->execute([$rid]); $recentUsers = $recentUsers->fetchAll();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>داشبورد ریسلر - پنل ریسلر</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #08100a; --sidebar: #0c1810; --surface: #111f14; --card: #131f17; --border: #1e3025;
    --accent: #10b981; --accent2: #06b6d4; --purple: #8b5cf6; --gold: #f59e0b;
    --text: #e2e8f0; --text2: #94a3b8; --muted: #475569; --danger: #ef4444; --success: #10b981;
    --warning: #f59e0b; --sidebar-w: 260px;
  }
  body { font-family: 'Vazirmatn', sans-serif; background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }
  
  .sidebar { width: var(--sidebar-w); background: var(--sidebar); border-left: 1px solid var(--border); position: fixed; right: 0; top: 0; bottom: 0; display: flex; flex-direction: column; z-index: 100; }
  .sidebar-logo { padding: 28px 24px; border-bottom: 1px solid var(--border); }
  .logo-text { font-size: 20px; font-weight: 900; background: linear-gradient(135deg, var(--accent), var(--accent2)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
  .logo-badge { font-size: 10px; color: var(--muted); -webkit-text-fill-color: var(--muted); }
  
  .debt-banner { margin: 12px 12px 0; padding: 12px 16px; background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.25); border-radius: 10px; }
  .debt-label { font-size: 11px; color: var(--muted); }
  .debt-amount { font-size: 18px; font-weight: 900; color: #f87171; margin-top: 2px; }

  .sidebar-nav { flex: 1; padding: 16px 12px; overflow-y: auto; }
  .nav-section-label { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: var(--muted); padding: 8px 12px 4px; font-weight: 600; }
  .nav-item { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-radius: 10px; color: var(--text2); text-decoration: none; font-size: 14px; font-weight: 500; transition: all 0.2s; margin-bottom: 2px; }
  .nav-item:hover { background: rgba(16,185,129,0.08); color: var(--text); }
  .nav-item.active { background: rgba(16,185,129,0.15); color: var(--accent); }
  
  .sidebar-footer { padding: 16px 12px; border-top: 1px solid var(--border); }
  .reseller-info { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; background: rgba(255,255,255,0.03); margin-bottom: 4px; }
  .reseller-avatar { width: 36px; height: 36px; background: linear-gradient(135deg, var(--accent), var(--accent2)); border-radius: 10px; display: flex; align-items: center; justify-content: center; }
  .reseller-name { font-size: 13px; font-weight: 600; }
  .reseller-role { font-size: 11px; color: var(--muted); }
  .logout-btn { color: var(--danger) !important; }

  .main { margin-right: var(--sidebar-w); flex: 1; }
  .topbar { background: var(--sidebar); border-bottom: 1px solid var(--border); padding: 16px 32px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
  .page-title { font-size: 20px; font-weight: 700; }
  .topbar-right { display: flex; align-items: center; gap: 12px; }
  
  .online-badge { display: flex; align-items: center; gap: 6px; background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: var(--success); padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; }
  .online-dot { width: 6px; height: 6px; background: var(--success); border-radius: 50%; animation: pulse 2s infinite; }
  @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.3; } }
  
  .content { padding: 32px; }
  
  .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 32px; }
  .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 24px; position: relative; overflow: hidden; }
  .stat-glow { position: absolute; top: -30px; left: -30px; width: 120px; height: 120px; border-radius: 50%; filter: blur(40px); opacity: 0.15; }
  .stat-card.green .stat-glow { background: #10b981; }
  .stat-card.cyan .stat-glow { background: #06b6d4; }
  .stat-card.red .stat-glow { background: #ef4444; }
  .stat-card.gold .stat-glow { background: #f59e0b; }
  .stat-icon { font-size: 28px; margin-bottom: 16px; }
  .stat-value { font-size: 32px; font-weight: 900; line-height: 1; margin-bottom: 6px; }
  .stat-card.green .stat-value { color: #34d399; }
  .stat-card.cyan .stat-value { color: #22d3ee; }
  .stat-card.red .stat-value { color: #f87171; }
  .stat-card.gold .stat-value { color: #fbbf24; }
  .stat-label { font-size: 13px; color: var(--text2); font-weight: 500; }
  
  .quick-actions { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 32px; }
  .quick-btn { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 20px; text-align: center; text-decoration: none; color: var(--text2); transition: all 0.2s; display: block; cursor: pointer; }
  .quick-btn:hover { border-color: var(--accent); background: rgba(16,185,129,0.05); color: var(--text); transform: translateY(-2px); }
  .quick-btn-icon { font-size: 28px; margin-bottom: 10px; }
  .quick-btn-text { font-size: 13px; font-weight: 600; }
  
  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; }
  .card-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
  .card-title { font-size: 15px; font-weight: 700; }
  .card-action { font-size: 12px; color: var(--accent); text-decoration: none; font-weight: 600; padding: 4px 10px; border: 1px solid rgba(16,185,129,0.3); border-radius: 8px; }
  .card-action:hover { background: rgba(16,185,129,0.1); }
  .card-body { padding: 20px 24px; }
  
  .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
  .badge-success { background: rgba(16,185,129,0.1); color: var(--success); border: 1px solid rgba(16,185,129,0.2); }
  .badge-danger { background: rgba(239,68,68,0.1); color: var(--danger); border: 1px solid rgba(239,68,68,0.2); }
  .badge-warning { background: rgba(245,158,11,0.1); color: var(--warning); border: 1px solid rgba(245,158,11,0.2); }
  
  .user-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid rgba(30,48,37,0.7); }
  .user-row:last-child { border-bottom: none; }
  .user-avatar { width: 36px; height: 36px; background: linear-gradient(135deg, rgba(16,185,129,0.3), rgba(6,182,212,0.3)); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
  .user-name { font-size: 14px; font-weight: 600; color: var(--text); }
  .user-expire { font-size: 12px; color: var(--muted); }
  
  .tx-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid rgba(30,48,37,0.7); font-size: 13px; }
  .tx-row:last-child { border-bottom: none; }
  .tx-type { color: var(--text2); }
  .tx-amount-pos { color: var(--success); font-weight: 600; }
  .tx-amount-neg { color: var(--danger); font-weight: 600; }
  .tx-date { font-size: 11px; color: var(--muted); }

  .alert-warning { background: rgba(245,158,11,0.08); border: 1px solid rgba(245,158,11,0.25); border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; }
  .alert-warning-title { font-size: 14px; font-weight: 700; color: var(--warning); margin-bottom: 8px; }
  .expiry-list { display: flex; flex-wrap: wrap; gap: 8px; }
  .expiry-chip { background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3); color: #fbbf24; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; }
</style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-logo">
    <?php $siteLogo=getSetting('site_logo',''); if($siteLogo&&file_exists(dirname(__DIR__).'/'.$siteLogo)): ?>
    <img src="../<?=sanitize($siteLogo)?>" alt="لوگو" style="max-height:52px;max-width:180px;object-fit:contain;margin-bottom:4px;display:block">
    <?php else: ?>
    <div class="logo-text">🌐 پنل ریسلر</div>
    <?php endif; ?>
    <div class="logo-badge">پنل ریسلر</div>
  </div>

  <?php if ($reseller['debt'] > 0): ?>
  <div class="debt-banner">
    <div class="debt-label">💳 بدهی شما</div>
    <div class="debt-amount"><?= number_format($reseller['debt']) ?> تومان</div>
  </div>
  <?php endif; ?>

  <nav class="sidebar-nav">
    <div class="nav-section-label">اصلی</div>
    <a href="dashboard.php" class="nav-item active">📊 داشبورد</a>
    <div class="nav-section-label">کاربران</div>
    <a href="users.php" class="nav-item">👥 مدیریت کاربران</a>
    <a href="users.php?action=add" class="nav-item">➕ افزودن کاربر</a>
    <a href="online.php" class="nav-item">🟢 کاربران آنلاین</a>
    <div class="nav-section-label">مالی</div>
    <a href="transactions.php" class="nav-item">💳 تراکنش‌های من</a>
    <a href="payments.php" class="nav-item">🧾 ارسال فیش پرداخت</a>
  </nav>

  <div class="sidebar-footer">
    <div class="reseller-info">
      <div class="reseller-avatar">👤</div>
      <div>
        <div class="reseller-name"><?= sanitize($_SESSION['reseller_username']) ?></div>
        <div class="reseller-role">ریسلر</div>
      </div>
    </div>
    <a href="logout.php" class="nav-item logout-btn">🚪 خروج</a>
  </div>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title">📊 داشبورد من</div>
    <div class="topbar-right">
      <div class="online-badge">
        <div class="online-dot"></div>
        <?= $onlineCount ?> آنلاین
      </div>
      <span style="font-size:13px; color:var(--muted)"><?= date('Y/m/d') ?></span>
    </div>
  </div>

  <div class="content">

    <!-- Expiring Warning -->
    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card green">
        <div class="stat-glow"></div>
        <div class="stat-icon">👥</div>
        <div class="stat-value"><?= $totalUsers ?></div>
        <div class="stat-label">کل کاربران من</div>
      </div>
      <div class="stat-card gold">
        <div class="stat-glow"></div>
        <div class="stat-icon">🟢</div>
        <div class="stat-value"><?= $onlineCount ?></div>
        <div class="stat-label">آنلاین الان</div>
      </div>
      <a href="users.php?tab=exp&days=7" class="stat-card red" style="text-decoration:none;color:inherit;cursor:pointer">
        <div class="stat-glow"></div>
        <div class="stat-icon">⚠️</div>
        <div class="stat-value"><?= count($expiringSoon) ?></div>
        <div class="stat-label">در حال انقضا</div>
      </a>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
      <a href="users.php?action=add" class="quick-btn">
        <div class="quick-btn-icon">➕</div>
        <div class="quick-btn-text">افزودن کاربر جدید</div>
      </a>
      <a href="users.php" class="quick-btn">
        <div class="quick-btn-icon">👁️</div>
        <div class="quick-btn-text">مدیریت کاربران</div>
      </a>
      <a href="online.php" class="quick-btn">
        <div class="quick-btn-icon">📡</div>
        <div class="quick-btn-text">کاربران آنلاین</div>
      </a>
      <a href="payments.php" class="quick-btn">
        <div class="quick-btn-icon">🧾</div>
        <div class="quick-btn-text">ارسال فیش پرداخت</div>
      </a>
    </div>

    <div class="grid-2">
      <!-- Recent Users -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">👥 کاربران اخیر</div>
          <a href="users.php" class="card-action">مشاهده همه</a>
        </div>
        <div class="card-body">
          <?php foreach ($recentUsers as $u): ?>
          <div class="user-row">
            <div class="user-avatar">🧑</div>
            <div style="flex:1">
              <div class="user-name"><?= sanitize($u['username']) ?></div>
              <div class="user-expire">انقضا: <?= $u['expire_date'] ?: '—' ?></div>
            </div>
            <span class="badge badge-<?= $u['status'] === 'active' ? 'success' : 'danger' ?>">
              <?= $u['status'] === 'active' ? 'فعال' : 'منقضی' ?>
            </span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($recentUsers)): ?>
          <div style="text-align:center; color:var(--muted); padding:30px">هنوز کاربری ایجاد نکرده‌اید</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Recent Transactions -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">💳 تراکنش‌های اخیر</div>
          <a href="transactions.php" class="card-action">همه</a>
        </div>
        <div class="card-body">
          <?php foreach ($transactions as $t): ?>
          <div class="tx-row">
            <div>
              <div class="tx-type"><?= sanitize($t['description'] ?? $t['type']) ?></div>
              <div class="tx-date"><?= date('Y/m/d H:i', strtotime($t['created_at'])) ?></div>
            </div>
            <div class="<?= in_array($t['type'], ['credit']) ? 'tx-amount-pos' : 'tx-amount-neg' ?>">
              <?= in_array($t['type'], ['credit']) ? '+' : '-' ?><?= number_format($t['amount']) ?> تومان
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($transactions)): ?>
          <div style="text-align:center; color:var(--muted); padding:30px">هنوز تراکنشی ثبت نشده</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</main>

</body>
</html>

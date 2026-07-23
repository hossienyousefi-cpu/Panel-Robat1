<?php
require_once '../includes/config.php';
require_once '../includes/ibsng_api.php';
requireAdmin();

// ===== آمار از دیتابیس محلی =====
$totalResellers  = $pdo->query("SELECT COUNT(*) FROM resellers")->fetchColumn();
$activeResellers = $pdo->query("SELECT COUNT(*) FROM resellers WHERE status='active'")->fetchColumn();
$totalDebt       = $pdo->query("SELECT SUM(debt) FROM resellers")->fetchColumn() ?: 0;
$totalTrans      = $pdo->query("SELECT SUM(ABS(amount)) FROM transactions")->fetchColumn() ?: 0;

// ===== آمار از  =====
// کاربران آنلاین
$onlineResult = ibsng_call('report.getOnlineUsersCount', []);
$onlineCount  = $onlineResult['result']['internet_onlines'] ?? '?';

// کل کاربران از 
$searchResult = ibsng_call('user.searchUser', [
    'conds'    => [],
    'from'     => 0,
    'to'       => 1,
    'order_by' => 'user_id',
    'desc'     => false,
]);
$totalUsers = $searchResult['result'][0] ?? 0;

// کاربران فعال (Recharged) از 
$activeResult = ibsng_call('user.searchUser', [
    'conds'    => ['status' => 'Recharged', 'status_op' => 'equals'],
    'from'     => 0,
    'to'       => 1,
    'order_by' => 'user_id',
    'desc'     => false,
]);
$activeUsers = $activeResult['result'][0] ?? 0;

// کاربران رو به اتمام 7 روز از 
$now  = date('Y/m/d');
$in7  = date('Y/m/d', strtotime('+7 days'));

// یک call برای تعداد کل + 10 تای اول برای نمایش
$expiringResult = ibsng_call('user.searchExpiredUsersExtended', [
    'conds'    => [
        'exp_date_from'      => $now,
        'exp_date_from_unit' => 'gregorian',
        'exp_date_to'        => $in7,
        'exp_date_to_unit'   => 'gregorian',
    ],
    'from'     => 0,
    'to'       => 200,
    'order_by' => 'user_id',
    'desc'     => false,
]);
$expiringUsers = $expiringResult['result'][2] ?? [];
// تعداد واقعی = count آرایه برگشتی (چون to=200 بیشتر از کل کاربران رو به اتمام است)
$expiringCount = count($expiringUsers);
// فقط 10 تای اول برای نمایش در داشبورد
$expiringUsersDisplay = array_slice($expiringUsers, 0, 10, true);

// جزئیات کاربران رو به اتمام (batch)
$expiringSoon = [];
if (!empty($expiringUsersDisplay)) {
    $allUIDs    = implode(',', array_keys($expiringUsersDisplay));
    $infoResult = ibsng_call('user.getUserInfo', ['user_id' => $allUIDs]);
    $infos      = $infoResult['result'] ?? [];
    foreach ($expiringUsersDisplay as $uid => $expDate) {
        $basic = $infos[$uid]['basic_info'] ?? [];
        $attrs = $infos[$uid]['attrs']      ?? [];
        $expiringSoon[] = [
            'username'   => $attrs['normal_username'] ?? "UserID $uid",
            'isp'        => $basic['isp_name']  ?? '—',
            'group'      => $basic['group_name'] ?? '—',
            'expire_date'=> substr($expDate, 0, 10),
            'days_left'  => (int)((strtotime($expDate) - time()) / 86400),
        ];
    }
    usort($expiringSoon, fn($a,$b) => $a['days_left'] <=> $b['days_left']);
}

// آخرین لاگ‌ها از دیتابیس
$recentLogs = $pdo->query("SELECT l.*,
    CASE WHEN l.actor_type='admin' THEN a.username ELSE r.username END as actor_name
    FROM activity_logs l
    LEFT JOIN admins a ON l.actor_type='admin' AND l.actor_id=a.id
    LEFT JOIN resellers r ON l.actor_type='reseller' AND l.actor_id=r.id
    ORDER BY l.created_at DESC LIMIT 10")->fetchAll();

// ریسلرهای اخیر
$recentResellers = $pdo->query("SELECT * FROM resellers ORDER BY created_at DESC LIMIT 5")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>داشبورد مدیریت - پنل مدیریت</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg:#080c18;--sidebar:#0d1424;--card:#131e30;--border:#1e2d45;
    --accent:#3b82f6;--accent2:#06b6d4;--purple:#8b5cf6;
    --text:#e2e8f0;--text2:#94a3b8;--muted:#475569;
    --danger:#ef4444;--success:#10b981;--warning:#f59e0b;--sidebar-w:260px;
  }
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
  .admin-info{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;background:rgba(255,255,255,.03)}
  .admin-avatar{width:36px;height:36px;background:linear-gradient(135deg,var(--accent),var(--purple));border-radius:10px;display:flex;align-items:center;justify-content:center}
  .admin-name{font-size:13px;font-weight:600}
  .admin-role{font-size:11px;color:var(--muted)}
  .logout-btn{color:var(--danger)!important;margin-top:4px}
  .main{margin-right:var(--sidebar-w);flex:1}
  .topbar{background:var(--sidebar);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
  .page-title{font-size:20px;font-weight:700}
  .online-badge{display:flex;align-items:center;gap:6px;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:var(--success);padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600}
  .online-dot{width:6px;height:6px;background:var(--success);border-radius:50%;animation:pulse 2s infinite}
  @keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
  .content{padding:32px}
  .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-bottom:32px}
  .stat-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px;position:relative;overflow:hidden;transition:transform .2s,border-color .2s}
  .stat-card:hover{transform:translateY(-3px)}
  .stat-card.blue{border-color:rgba(59,130,246,.3)}
  .stat-card.cyan{border-color:rgba(6,182,212,.3)}
  .stat-card.purple{border-color:rgba(139,92,246,.3)}
  .stat-card.gold{border-color:rgba(245,158,11,.3)}
  .stat-card.green{border-color:rgba(16,185,129,.3)}
  .stat-card.red{border-color:rgba(239,68,68,.3)}
  .stat-glow{position:absolute;top:-30px;left:-30px;width:120px;height:120px;border-radius:50%;filter:blur(40px);opacity:.15}
  .blue .stat-glow{background:#3b82f6}.cyan .stat-glow{background:#06b6d4}.purple .stat-glow{background:#8b5cf6}
  .gold .stat-glow{background:#f59e0b}.green .stat-glow{background:#10b981}.red .stat-glow{background:#ef4444}
  .stat-icon{font-size:28px;margin-bottom:16px}
  .stat-value{font-size:32px;font-weight:900;line-height:1;margin-bottom:6px}
  .blue .stat-value{color:#60a5fa}.cyan .stat-value{color:#22d3ee}.purple .stat-value{color:#a78bfa}
  .gold .stat-value{color:#fbbf24}.green .stat-value{color:#34d399}.red .stat-value{color:#f87171}
  .stat-label{font-size:13px;color:var(--text2);font-weight:500}
  .stat-sub{font-size:11px;color:var(--muted);margin-top:4px}
  .stat-card a{text-decoration:none;color:inherit}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden}
  .card-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
  .card-title{font-size:15px;font-weight:700}
  .card-action{font-size:12px;color:var(--accent);text-decoration:none;font-weight:600;padding:4px 10px;border:1px solid rgba(59,130,246,.3);border-radius:8px;transition:all .2s}
  .card-action:hover{background:rgba(59,130,246,.1)}
  .card-body{padding:20px 24px}
  .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
  .badge-success{background:rgba(16,185,129,.1);color:var(--success);border:1px solid rgba(16,185,129,.2)}
  .badge-danger{background:rgba(239,68,68,.1);color:var(--danger);border:1px solid rgba(239,68,68,.2)}
  .badge-warning{background:rgba(245,158,11,.1);color:var(--warning);border:1px solid rgba(245,158,11,.2)}
  .badge-info{background:rgba(59,130,246,.1);color:var(--accent);border:1px solid rgba(59,130,246,.2)}
  .log-item{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid rgba(30,45,69,.5);font-size:13px}
  .log-item:last-child{border-bottom:none}
  .log-avatar{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
  .log-admin{background:rgba(59,130,246,.15)}.log-reseller{background:rgba(139,92,246,.15)}
  .log-action{font-weight:600;color:var(--text)}.log-time{font-size:11px;color:var(--muted)}
  .quick-actions{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:32px}
  .quick-btn{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;text-align:center;text-decoration:none;color:var(--text2);transition:all .2s;cursor:pointer;display:block}
  .quick-btn:hover{border-color:var(--accent);background:rgba(59,130,246,.05);color:var(--text);transform:translateY(-2px)}
  .quick-btn-icon{font-size:28px;margin-bottom:10px}.quick-btn-text{font-size:13px;font-weight:600}
  .table{width:100%;border-collapse:collapse}
  .table th{text-align:right;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;padding:0 0 12px;border-bottom:1px solid var(--border)}
  .table td{padding:12px 0;font-size:13px;border-bottom:1px solid rgba(30,45,69,.5);color:var(--text2)}
  .table tr:last-child td{border-bottom:none}
  .exp-days-red{color:var(--danger);font-weight:700}
  .exp-days-yellow{color:var(--warning);font-weight:700}
  .exp-days-green{color:var(--success);font-weight:700}
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
    <a href="dashboard.php" class="nav-item active">📊 داشبورد</a>
    <div class="nav-section-label">مدیریت</div>
    <a href="resellers.php" class="nav-item">👥 ریسلرها</a>
    <a href="users.php" class="nav-item">🧑‍💻 کاربران</a>
    <a href="online.php" class="nav-item">🟢 کاربران آنلاین</a>
    <a href="users.php?tab=expiring" class="nav-item">⚠️ رو به اتمام</a>
    <div class="nav-section-label">مالی</div>
    <a href="transactions.php" class="nav-item">💳 تراکنش‌ها</a>
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
      <div><div class="admin-name"><?= $_SESSION['admin_username'] ?></div><div class="admin-role">مدیر اصلی سیستم</div></div>
    </div>
    <a href="logout.php" class="nav-item logout-btn">🚪 خروج</a>
  </div>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title">📊 داشبورد</div>
    <div style="display:flex;align-items:center;gap:16px;font-size:13px;color:var(--text2)">
      <div class="online-badge">
        <div class="online-dot"></div>
        <?= money($onlineCount) ?> آنلاین
      </div>
      <span><?= date('Y/m/d') ?></span>
    </div>
  </div>

  <div class="content">
    <!-- Stats Grid -->
    <div class="stats-grid">

      <a href="resellers.php" class="stat-card blue" style="text-decoration:none">
        <div class="stat-glow"></div>
        <div class="stat-icon">👥</div>
        <div class="stat-value"><?= money($totalResellers) ?></div>
        <div class="stat-label">کل ریسلرها</div>
        <div class="stat-sub"><?= money($activeResellers) ?> فعال</div>
      </a>

      <a href="users.php" class="stat-card cyan" style="text-decoration:none">
        <div class="stat-glow"></div>
        <div class="stat-icon">🧑‍💻</div>
        <div class="stat-value"><?= money($totalUsers) ?></div>
        <div class="stat-label">کل کاربران</div>
        <div class="stat-sub"><?= money($activeUsers) ?> فعال</div>
      </a>

      <a href="online.php" class="stat-card green" style="text-decoration:none">
        <div class="stat-glow"></div>
        <div class="stat-icon">🟢</div>
        <div class="stat-value"><?= money($onlineCount) ?></div>
        <div class="stat-label">آنلاین</div>
        <div class="stat-sub">همین الان</div>
      </a>

      <a href="users.php?tab=expiring" class="stat-card gold" style="text-decoration:none">
        <div class="stat-glow"></div>
        <div class="stat-icon">⚠️</div>
        <div class="stat-value"><?= money($expiringCount) ?></div>
        <div class="stat-label">در حال انقضا</div>
        <div class="stat-sub">۷ روز آینده</div>
      </a>

      <a href="debts.php" class="stat-card red" style="text-decoration:none">
        <div class="stat-glow"></div>
        <div class="stat-icon">💰</div>
        <div class="stat-value"><?= money($totalDebt) ?></div>
        <div class="stat-label">کل بدهی</div>
        <div class="stat-sub">تومان</div>
      </a>

      <a href="transactions.php" class="stat-card purple" style="text-decoration:none">
        <div class="stat-glow"></div>
        <div class="stat-icon">📈</div>
        <div class="stat-value"><?= money($totalTrans) ?></div>
        <div class="stat-label">کل تراکنش‌ها</div>
        <div class="stat-sub">تومان</div>
      </a>

    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
      <a href="resellers.php?action=add" class="quick-btn"><div class="quick-btn-icon">➕</div><div class="quick-btn-text">افزودن ریسلر</div></a>
      <a href="users.php" class="quick-btn"><div class="quick-btn-icon">👁️</div><div class="quick-btn-text">مشاهده کاربران</div></a>
      <a href="debts.php" class="quick-btn"><div class="quick-btn-icon">💳</div><div class="quick-btn-text">مدیریت بدهی</div></a>
      <a href="online.php" class="quick-btn"><div class="quick-btn-icon">📡</div><div class="quick-btn-text">کاربران آنلاین</div></a>
    </div>

    <!-- Tables -->
    <div class="grid-2">
      <!-- Recent Logs -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">📋 آخرین فعالیت‌ها</div>
          <a href="logs.php" class="card-action">مشاهده همه</a>
        </div>
        <div class="card-body">
          <?php foreach ($recentLogs as $log): ?>
          <div class="log-item">
            <div class="log-avatar log-<?= $log['actor_type'] ?>"><?= $log['actor_type']==='admin'?'🛡️':'👤' ?></div>
            <div>
              <div class="log-action"><?= sanitize($log['action']) ?></div>
              <div class="log-time"><?= sanitize($log['actor_name']) ?> · <?= date('H:i', strtotime($log['created_at'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($recentLogs)): ?>
          <div style="text-align:center;color:var(--muted);padding:30px">هنوز فعالیتی ثبت نشده</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Expiring Soon - از  -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">⚠️ کاربران در حال انقضا</div>
          <a href="users.php?tab=expiring" class="card-action">مشاهده همه</a>
        </div>
        <div class="card-body">
          <?php if (!empty($expiringSoon)): ?>
          <?php foreach ($expiringSoon as $user): ?>
          <div class="log-item">
            <div class="log-avatar" style="background:rgba(245,158,11,.15)">⏰</div>
            <div style="flex:1">
              <div class="log-action"><?= sanitize($user['username']) ?></div>
              <div class="log-time">
                <span class="badge badge-info"><?= sanitize($user['group']) ?></span>
                <?= sanitize($user['isp']) ?>
              </div>
            </div>
            <div style="text-align:left">
              <div style="font-size:11px;color:var(--muted)"><?php
                $d = new DateTime($user['expire_date']);
                echo $d->format('Y/m/d');
              ?></div>
              <div class="<?= $user['days_left']<=1?'exp-days-red':($user['days_left']<=3?'exp-days-yellow':'exp-days-green') ?>">
                <?= $user['days_left'] ?> روز
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php else: ?>
          <div style="text-align:center;color:var(--muted);padding:30px">هیچ کاربری در حال انقضا نیست ✅</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Resellers Table -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">👥 ریسلرهای اخیر</div>
        <a href="resellers.php" class="card-action">مدیریت ریسلرها</a>
      </div>
      <div class="card-body">
        <table class="table">
          <thead>
            <tr><th>نام کاربری</th><th>نام کامل</th><th>تعداد کاربر</th><th>بدهی</th><th>وضعیت</th></tr>
          </thead>
          <tbody>
            <?php foreach ($recentResellers as $r):
              // تعداد واقعی کاربرهای ISP این ریسلر توی IBSng (نه فقط کاربرهایی که از همین پنل ساخته شدن)
              $userCount = $r['isp_name'] ? ibsng_getIspUserCount($r['isp_name']) : 0;
            ?>
            <tr>
              <td style="color:var(--text);font-weight:600"><?= sanitize($r['username']) ?></td>
              <td><?= sanitize($r['full_name'] ?? '—') ?></td>
              <td><?= $userCount ?> کاربر</td>
              <td style="color:<?= $r['debt']>0?'var(--danger)':'var(--success)' ?>"><?= money($r['debt']) ?> تومان</td>
              <td><span class="badge badge-<?= $r['status']==='active'?'success':'danger' ?>"><?= $r['status']==='active'?'فعال':'غیرفعال' ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</main>
</body>
</html>

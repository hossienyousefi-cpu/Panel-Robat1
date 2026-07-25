<?php
require_once '../includes/config.php';
requireReseller();
$rid = $_SESSION['reseller_id'];
$reseller = $pdo->prepare("SELECT * FROM resellers WHERE id=?");
$reseller->execute([$rid]); $reseller = $reseller->fetch();

$transactions = $pdo->prepare("SELECT t.*, u.username as user_name FROM transactions t LEFT JOIN users u ON t.user_id=u.id WHERE t.reseller_id=? ORDER BY t.created_at DESC LIMIT 100");
$transactions->execute([$rid]); $transactions = $transactions->fetchAll();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تراکنش‌ها - پنل ریسلر</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root { --bg: #08100a; --sidebar: #0c1810; --card: #131f17; --border: #1e3025; --accent: #10b981; --accent2: #06b6d4; --text: #e2e8f0; --text2: #94a3b8; --muted: #475569; --danger: #ef4444; --success: #10b981; --warning: #f59e0b; --sidebar-w: 260px; }
  html, body { overflow-x: hidden; }
  body { font-family: 'Vazirmatn', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
  .sidebar { width: var(--sidebar-w); background: var(--sidebar); border-left: 1px solid var(--border); position: fixed; right: 0; top: 0; bottom: 0; display: flex; flex-direction: column; z-index: 100; }
  .overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 99; }
  .hamburger { display: none; position: fixed; top: 14px; right: 14px; z-index: 101; background: var(--sidebar); border: 1px solid var(--border); border-radius: 9px; padding: 8px 10px; cursor: pointer; color: var(--text); font-size: 18px; }
  @media (max-width: 768px) {
    .sidebar { transform: translateX(100%); transition: .3s; }
    .sidebar.open { transform: none; }
    .overlay.open { display: block; }
    .hamburger { display: block; }
    .main { margin-right: 0 !important; }
    .content { padding: 20px 16px !important; }
    .topbar { padding: 14px 16px !important; padding-right: 60px !important; flex-wrap: wrap; gap: 8px; }
    .page-title { font-size: 16px !important; }
    .summary { grid-template-columns: 1fr 1fr !important; gap: 10px !important; }
    .sum-card { padding: 14px !important; }
    .sum-value { font-size: 18px !important; }
  }
  .sidebar-logo { padding: 28px 24px; border-bottom: 1px solid var(--border); }
  .logo-text { font-size: 20px; font-weight: 900; background: linear-gradient(135deg, var(--accent), var(--accent2)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
  .logo-badge { font-size: 10px; color: var(--muted); -webkit-text-fill-color: var(--muted); }
  .debt-banner { margin: 12px 12px 0; padding: 12px 16px; background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.25); border-radius: 10px; }
  .debt-label { font-size: 11px; color: var(--muted); }
  .debt-amount { font-size: 18px; font-weight: 900; color: #f87171; }
  .sidebar-nav { flex: 1; padding: 16px 12px; }
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
  .content { padding: 32px; }
  .summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 28px; }
  .sum-card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 20px; text-align: center; }
  .sum-value { font-size: 24px; font-weight: 900; }
  .sum-label { font-size: 13px; color: var(--muted); margin-top: 6px; }
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; }
  .table-wrapper { overflow-x: auto; }
  .table { width: 100%; border-collapse: collapse; }
  .table th { text-align: right; font-size: 12px; font-weight: 600; color: var(--muted); padding: 14px 16px; border-bottom: 1px solid var(--border); background: rgba(0,0,0,0.2); }
  .table td { padding: 14px 16px; font-size: 13px; border-bottom: 1px solid rgba(30,48,37,0.5); color: var(--text2); }
  .table tr:last-child td { border-bottom: none; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
  .badge-create { background: rgba(6,182,212,0.1); color: #22d3ee; border: 1px solid rgba(6,182,212,0.2); }
  .badge-renew { background: rgba(139,92,246,0.1); color: #a78bfa; border: 1px solid rgba(139,92,246,0.2); }
  .badge-credit { background: rgba(16,185,129,0.1); color: var(--success); border: 1px solid rgba(16,185,129,0.2); }
  .badge-debit { background: rgba(239,68,68,0.1); color: var(--danger); border: 1px solid rgba(239,68,68,0.2); }
  .amount-pos { color: var(--success); font-weight: 700; }
  .amount-neg { color: var(--danger); font-weight: 700; }
</style>
</head>
<body>
<div class="overlay" id="overlay" onclick="closeSB()"></div>
<button class="hamburger" onclick="toggleSB()" title="منو">☰</button>
<aside class="sidebar" id="sidebar">
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
    <div class="debt-amount"><?= money($reseller['debt']) ?> تومان</div>
  </div>
  <?php endif; ?>
  <nav class="sidebar-nav">
    <div class="nav-section-label">اصلی</div>
    <a href="dashboard.php" class="nav-item">📊 داشبورد</a>
    <div class="nav-section-label">کاربران</div>
    <a href="users.php" class="nav-item">👥 مدیریت کاربران</a>
    <a href="users.php?action=add" class="nav-item">➕ افزودن کاربر</a>
    <a href="online.php" class="nav-item">🟢 کاربران آنلاین</a>
    <div class="nav-section-label">مالی</div>
    <a href="transactions.php" class="nav-item active">💳 تراکنش‌های من</a>
    <a href="renewals.php" class="nav-item">🔄 کاربران تمدیدشده</a>
    <a href="payments.php" class="nav-item">🧾 ارسال فیش پرداخت</a>
    <div class="nav-section-label">فروش مستقیم تلگرام</div>
    <a href="direct_orders.php" class="nav-item">🛒 سفارش‌های مستقیم</a>
    <a href="telegram.php" class="nav-item">🤖 بات تلگرام من</a>
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
    <div class="page-title">💳 تراکنش‌های من</div>
  </div>
  <div class="content">
    <?php
    $totalDebit = array_sum(array_column(array_filter($transactions, fn($t) => in_array($t['type'], ['user_create','user_renew','debit'])), 'amount'));
    $totalCredit = array_sum(array_column(array_filter($transactions, fn($t) => $t['type'] === 'credit'), 'amount'));
    ?>
    <div class="summary">
      <div class="sum-card">
        <div class="sum-value" style="color:#f87171"><?= money($reseller['debt']) ?> ت</div>
        <div class="sum-label">بدهی فعلی</div>
      </div>
      <div class="sum-card">
        <div class="sum-value" style="color:#f87171"><?= money($totalDebit) ?> ت</div>
        <div class="sum-label">کل هزینه‌ها</div>
      </div>
      <div class="sum-card">
        <div class="sum-value" style="color:#34d399"><?= money($totalCredit) ?> ت</div>
        <div class="sum-label">کل پرداختی‌ها</div>
      </div>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>نوع</th>
              <th>کاربر</th>
              <th>توضیح</th>
              <th>مبلغ</th>
              <th>تاریخ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($transactions as $t): 
              $isPositive = $t['type'] === 'credit';
              $typeLabels = ['user_create' => '🆕 ایجاد کاربر', 'user_renew' => '🔄 تمدید', 'credit' => '✅ پرداخت', 'debit' => '➖ بدهی'];
              $typeClasses = ['user_create' => 'create', 'user_renew' => 'renew', 'credit' => 'credit', 'debit' => 'debit'];
            ?>
            <tr>
              <td>
                <span class="badge badge-<?= $typeClasses[$t['type']] ?? 'debit' ?>">
                  <?= $typeLabels[$t['type']] ?? $t['type'] ?>
                </span>
              </td>
              <td><?= sanitize($t['user_name'] ?? '—') ?></td>
              <td><?= sanitize($t['description'] ?? '—') ?></td>
              <td class="<?= $isPositive ? 'amount-pos' : 'amount-neg' ?>">
                <?= $isPositive ? '+' : '-' ?><?= money($t['amount']) ?> ت
              </td>
              <td style="font-size:12px; color:var(--muted)"><?= date('Y/m/d H:i', strtotime($t['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($transactions)): ?>
            <tr><td colspan="5" style="text-align:center; padding:40px; color:var(--muted)">هنوز تراکنشی ثبت نشده</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
<script>
function toggleSB(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('open')}
function closeSB(){document.getElementById('sidebar').classList.remove('open');document.getElementById('overlay').classList.remove('open')}
</script>
</body>
</html>

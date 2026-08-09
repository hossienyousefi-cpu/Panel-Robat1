<?php
require_once '../includes/config.php';
require_once '../includes/icons.php';
require_once '../includes/ibsng_api.php';
requireAdmin();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('درخواست نامعتبر است (احتمالاً صفحه قدیمی شده). لطفاً صفحه را رفرش کرده و دوباره امتحان کنید.');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rid = (int)($_POST['reseller_id'] ?? 0);
    $amount = parseMoney($_POST['amount'] ?? 0);
    $type = $_POST['type'] ?? 'credit';
    $note = sanitize($_POST['note'] ?? '');

    if ($rid && $amount > 0) {
        if ($type === 'credit') {
            $pdo->prepare("UPDATE resellers SET debt = GREATEST(0, debt - ?), balance = balance + ? WHERE id=?")->execute([$amount, $amount, $rid]);
            $pdo->prepare("INSERT INTO transactions (reseller_id, type, amount, description, created_by_admin) VALUES (?,?,?,?,?)")
                ->execute([$rid, 'credit', $amount, $note ?: 'شارژ حساب توسط ادمین', $_SESSION['admin_id']]);
        } else {
            $pdo->prepare("UPDATE resellers SET debt = debt + ?, balance = GREATEST(0, balance - ?) WHERE id=?")->execute([$amount, $amount, $rid]);
            $pdo->prepare("INSERT INTO transactions (reseller_id, type, amount, description, created_by_admin) VALUES (?,?,?,?,?)")
                ->execute([$rid, 'debit', $amount, $note ?: 'کسر موجودی توسط ادمین', $_SESSION['admin_id']]);
        }
        logActivity('admin', $_SESSION['admin_id'], 'update_debt', "موجودی ریسلر #$rid تغییر کرد: $amount تومان");
        $message = 'موجودی ریسلر با موفقیت بروز شد';
    }
}

$pendingCount = $pdo->query("SELECT COUNT(*) FROM payment_requests WHERE status='pending'")->fetchColumn();
$resellers = $pdo->query("SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.reseller_id=r.id) as user_count FROM resellers r ORDER BY r.username ASC")->fetchAll();
// user_count از جدول محلی users فقط کاربرهای ساخته‌شده از همین پنل رو می‌شمرد؛
// تعداد واقعی کاربرهای هر ISP توی IBSng باید جایگزینش بشه.
foreach ($resellers as &$rDebt) {
    $rDebt['user_count'] = $rDebt['isp_name'] ? ibsng_getIspUserCount($rDebt['isp_name']) : 0;
}
unset($rDebt);
$totalDebt = $pdo->query("SELECT SUM(debt) FROM resellers")->fetchColumn() ?: 0;
$totalBalance = $pdo->query("SELECT SUM(balance) FROM resellers")->fetchColumn() ?: 0;
$debtors = $pdo->query("SELECT COUNT(*) FROM resellers WHERE debt > 0")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>مدیریت موجودی - پنل مدیریت</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<link href="../assets/css/theme.css" rel="stylesheet">
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{--bg:#080c18;--sidebar:#0d1424;--card:#131e30;--border:#1e2d45;--accent:#3b82f6;--accent2:#06b6d4;--purple:#8b5cf6;--text:#e2e8f0;--text2:#94a3b8;--muted:#475569;--danger:#ef4444;--success:#10b981;--warning:#f59e0b;--sidebar-w:260px}
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
  .pending-badge{background:rgba(245,158,11,.15);color:var(--warning);border:1px solid rgba(245,158,11,.4);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;margin-right:8px}
  .main{margin-right:var(--sidebar-w);flex:1}
  .topbar{background:var(--sidebar);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
  .page-title{font-size:20px;font-weight:700}
  .content{padding:32px}
  .stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
  .stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;display:flex;align-items:center;gap:16px}
  .stat-icon{font-size:28px}
  .stat-value{font-size:22px;font-weight:900}
  .stat-label{font-size:12px;color:var(--text2)}
  .alert{padding:14px 18px;border-radius:10px;margin-bottom:20px;font-size:14px}
  .alert-success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:#6ee7b7}
  .card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden}
  .table-wrapper{overflow-x:auto}
  .table{width:100%;border-collapse:collapse}
  .table th{text-align:right;font-size:12px;font-weight:600;color:var(--muted);padding:14px 16px;border-bottom:1px solid var(--border);background:rgba(0,0,0,.2);white-space:nowrap}
  .table td{padding:14px 16px;font-size:13px;border-bottom:1px solid rgba(30,45,69,.5);color:var(--text2);vertical-align:middle}
  .table tr:last-child td{border-bottom:none}
  .btn{padding:8px 14px;border-radius:8px;font-family:'Vazirmatn';font-size:12px;font-weight:600;cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:5px}
  .btn-success{background:rgba(16,185,129,.15);color:var(--success);border:1px solid rgba(16,185,129,.3)}
  .btn-danger{background:rgba(239,68,68,.1);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
  .btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border:none}
  .balance-pos{color:#34d399;font-weight:700;font-size:15px}
  .balance-zero{color:var(--muted);font-weight:600}
  .debt-high{color:#f87171;font-weight:700}
  .debt-zero{color:#34d399}
  .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px}
  .modal{background:var(--card);border:1px solid var(--border);border-radius:20px;width:100%;max-width:460px;overflow:hidden}
  .modal-header{padding:24px 28px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
  .modal-title{font-size:17px;font-weight:700}
  .modal-close{background:none;border:none;color:var(--muted);font-size:20px;cursor:pointer}
  .modal-body{padding:28px}
  .modal-footer{padding:16px 28px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end}
  .form-group{margin-bottom:18px}
  label{display:block;font-size:13px;font-weight:500;color:var(--muted);margin-bottom:8px}
  input,select{width:100%;padding:12px 14px;background:var(--bg);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:'Vazirmatn';font-size:14px;outline:none;transition:border .2s}
  input:focus{border-color:var(--accent)}
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
    <a href="dashboard.php" class="nav-item"><?=svgIcon('dashboard')?> داشبورد</a>
    <div class="nav-section-label">مدیریت</div>
    <a href="resellers.php" class="nav-item"><?=svgIcon('users')?> ریسلرها</a>
    <a href="users.php" class="nav-item"><?=svgIcon('user')?> کاربران</a>
    <a href="online.php" class="nav-item">🟢 کاربران آنلاین</a>
    <div class="nav-section-label">مالی</div>
    <a href="transactions.php" class="nav-item"><?=svgIcon('card')?> تراکنش‌ها</a>
    <a href="renewals.php" class="nav-item"><?=svgIcon('refresh')?> کاربران تمدیدشده</a>
    <a href="debts.php" class="nav-item active"><?=svgIcon('wallet')?> مدیریت موجودی</a>
    <a href="payments.php" class="nav-item">
      🧾 فیش‌های پرداخت
      <?php if ($pendingCount > 0): ?>
      <span class="pending-badge"><?= $pendingCount ?></span>
      <?php endif; ?>
    </a>
    <div class="nav-section-label">فروش مستقیم تلگرام</div>
    <a href="direct_packages.php" class="nav-item"><?=svgIcon('box')?> بسته‌های فروش مستقیم</a>
    <a href="direct_orders.php" class="nav-item"><?=svgIcon('cart')?> سفارش‌های مستقیم</a>
    <a href="telegram.php" class="nav-item"><?=svgIcon('bot')?> ربات تلگرام</a>
    <div class="nav-section-label">سیستم</div>
    <a href="logs.php" class="nav-item"><?=svgIcon('list')?> لاگ فعالیت‌ها</a>
    <a href="settings.php" class="nav-item"><?=svgIcon('gear')?> تنظیمات</a>
  </nav>
  <div class="sidebar-footer">
    <div class="admin-info">
      <div class="admin-avatar"><?=svgIcon('shield')?></div>
      <div><div class="admin-name"><?= $_SESSION['admin_username'] ?></div><div class="admin-role">مدیر اصلی</div></div>
    </div>
    <a href="logout.php" class="nav-item logout-btn"><?=svgIcon('logout')?> خروج</a>
  </div>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title"><?=svgIcon('wallet')?> مدیریت موجودی ریسلرها</div>
  </div>
  <div class="content">
    <?php if ($message): ?><div class="alert alert-success">✅ <?= $message ?></div><?php endif; ?>

    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon">💚</div>
        <div><div class="stat-value" style="color:#34d399"><?= money($totalBalance) ?></div><div class="stat-label">کل موجودی ریسلرها (تومان)</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon"><?=svgIcon('wallet')?></div>
        <div><div class="stat-value" style="color:#f87171"><?= money($totalDebt) ?></div><div class="stat-label">کل بدهی (تومان)</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon"><?=svgIcon('warning')?></div>
        <div><div class="stat-value" style="color:#fbbf24"><?= $debtors ?></div><div class="stat-label">ریسلر بدهکار</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon"><?=svgIcon('users')?></div>
        <div><div class="stat-value" style="color:#60a5fa"><?= count($resellers) ?></div><div class="stat-label">کل ریسلرها</div></div>
      </div>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>ریسلر</th>
              <th>کاربران</th>
              <th>💚 موجودی (تومان)</th>
              <th>🔴 بدهی (تومان)</th>
              <th>وضعیت</th>
              <th>عملیات</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($resellers as $r):
              $balance = $r['balance'] ?? 0;
            ?>
            <tr>
              <td style="font-weight:700;color:var(--text)">
                <?= sanitize($r['username']) ?>
                <?php if ($r['full_name']): ?><br><small style="color:var(--muted)"><?= sanitize($r['full_name']) ?></small><?php endif; ?>
              </td>
              <td><?= $r['user_count'] ?> کاربر</td>
              <td>
                <span class="<?= $balance > 0 ? 'balance-pos' : 'balance-zero' ?>">
                  <?= money($balance) ?>
                </span>
              </td>
              <td><span class="<?= $r['debt']>0?'debt-high':'debt-zero' ?>"><?= money($r['debt']) ?></span></td>
              <td><span style="color:<?= $r['status']==='active'?'var(--success)':'var(--danger)' ?>"><?= $r['status']==='active'?'✅ فعال':'❌ غیرفعال' ?></span></td>
              <td style="display:flex;gap:6px;flex-wrap:wrap">
                <button class="btn btn-success" onclick="openModal(<?= $r['id'] ?>,'<?= sanitize($r['username']) ?>',<?= $balance ?>,<?= $r['debt'] ?>,'credit')"><?=svgIcon('plus')?> شارژ</button>
                <button class="btn btn-danger" onclick="openModal(<?= $r['id'] ?>,'<?= sanitize($r['username']) ?>',<?= $balance ?>,<?= $r['debt'] ?>,'debit')">➖ کسر</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<div class="modal-backdrop" id="debtModal" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="modalTitle">مدیریت موجودی</div>
      <button class="modal-close" onclick="document.getElementById('debtModal').style.display='none'" title="بستن">✕</button>
    </div>
    <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
      <input type="hidden" name="reseller_id" id="modalResellerId">
      <input type="hidden" name="type" id="modalType">
      <div class="modal-body">
        <p style="color:var(--text2);margin-bottom:6px">ریسلر: <strong id="modalResellerName" style="color:var(--accent)"></strong></p>
        <p style="color:var(--text2);margin-bottom:6px">موجودی فعلی: <strong id="modalBalance" style="color:#34d399"></strong> تومان</p>
        <p style="color:var(--text2);margin-bottom:20px">بدهی: <strong id="modalDebt" style="color:var(--danger)"></strong> تومان</p>
        <div class="form-group">
          <label>مبلغ (تومان)</label>
          <input type="text" inputmode="numeric" name="amount" placeholder="مثلاً: 500.000" oninput="fmtMoneyInput(this)" required>
        </div>
        <div class="form-group">
          <label>توضیح (اختیاری)</label>
          <input type="text" name="note" placeholder="پرداخت نقدی / ...">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn" style="background:rgba(255,255,255,.05);color:var(--text2)" onclick="document.getElementById('debtModal').style.display='none'">انصراف</button>
        <button type="submit" class="btn btn-primary">✅ ثبت</button>
      </div>
    </form>
  </div>
</div>

<script>
function fmtMoneyInput(el){var v=el.value.replace(/\D/g,'');el.value=v?v.replace(/\B(?=(\d{3})+(?!\d))/g,'.'):'';}
function openModal(id, name, balance, debt, type) {
  document.getElementById('modalResellerId').value = id;
  document.getElementById('modalResellerName').textContent = name;
  document.getElementById('modalBalance').textContent = balance.toLocaleString('de-DE');
  document.getElementById('modalDebt').textContent = debt.toLocaleString('de-DE');
  document.getElementById('modalType').value = type;
  document.getElementById('modalTitle').textContent = type === 'credit' ? '➕ شارژ حساب ریسلر' : '➖ کسر از موجودی';
  document.getElementById('debtModal').style.display = 'flex';
}
</script>
</body></html>

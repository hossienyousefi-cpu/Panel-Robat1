<?php
require_once '../includes/config.php';
require_once '../includes/ibsng_api.php';
require_once '../includes/telegram_api.php';
require_once '../includes/reseller_bot.php';
require_once '../telegram/bot.php';
requireReseller();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('درخواست نامعتبر است (احتمالاً صفحه قدیمی شده). لطفاً صفحه را رفرش کرده و دوباره امتحان کنید.');
}

$rid = (int)$_SESSION['reseller_id'];
$success = ''; $error = '';

// بدون این، پیام‌های موفقیت/رد (که از همین‌جا برای مشتری فرستاده می‌شن) با
// توکن بات پیش‌فرض/سراسری پنل ارسال می‌شدن (نه بات اختصاصی خودِ همین
// ریسلر) - یعنی یا اصلاً به دست مشتری نمی‌رسیدن یا (بدتر) توی یک بات دیگه
// (مثلاً بات اصلی ادمین) ظاهر می‌شدن.
$myBot = rb_getBot($rid);
if ($myBot) tg_setActiveBotToken($myBot['bot_token']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($orderId && in_array($action, ['approve', 'reject'], true)) {
        // reseller_id=? : یک ریسلر فقط می‌تونه سفارش‌های بات خودش رو تأیید/رد
        // کنه، نه بقیه رو - وگرنه هر ریسلری می‌تونست با حدس زدن order_id، سفارش
        // ریسلر دیگه‌ای رو با موجودی خودش تأیید کنه یا رد کنه.
        $order = $pdo->prepare("SELECT * FROM telegram_orders WHERE id=? AND status='pending' AND reseller_id=?");
        $order->execute([$orderId, $rid]);
        $order = $order->fetch();

        if (!$order) {
            $error = 'سفارش یافت نشد یا قبلاً بررسی شده';
        } else {
            $customerStmt = $pdo->prepare("SELECT * FROM telegram_customers WHERE id=?");
            $customerStmt->execute([$order['telegram_customer_id']]);
            $customer = $customerStmt->fetch();

            $reviewerName = 'ریسلر (پنل وب): ' . $_SESSION['reseller_username'];

            if ($action === 'reject') {
                $pdo->prepare("UPDATE telegram_orders SET status='rejected', reviewed_by_name=?, reviewed_at=NOW() WHERE id=?")->execute([$reviewerName, $orderId]);
                if ($customer) tg_sendMessage($customer['chat_id'], '❌ متأسفانه رسید پرداخت شما تأیید نشد. برای پیگیری با پشتیبانی تماس بگیرید.');
                logActivity('reseller', $rid, 'reject_direct_order', "سفارش تلگرام #$orderId توسط {$reviewerName} رد شد");
                $success = 'سفارش رد شد ❌';
            } else {
                $result = $order['order_type'] === 'new'
                    ? tg_provision_new_order($order, $customer, $rid)
                    : tg_provision_renew_order($order, $customer, $rid);

                if (!$result['ok']) {
                    $error = 'خطا: ' . $result['error'];
                } else {
                    $pdo->prepare("UPDATE telegram_orders SET status='approved', ibs_uid=?, reviewed_by_name=?, reviewed_at=NOW() WHERE id=?")
                        ->execute([$result['ibs_uid'] ?? $order['ibs_uid'], $reviewerName, $orderId]);
                    logActivity('reseller', $rid, 'approve_direct_order', "سفارش تلگرام #$orderId توسط {$reviewerName} تأیید شد");
                    $success = 'سفارش تأیید شد و روی IBSng اعمال گردید ✅';
                }
            }
        }
    }
}

$pendingCount = $pdo->prepare("SELECT COUNT(*) FROM telegram_orders WHERE status='pending' AND reseller_id=?");
$pendingCount->execute([$rid]); $pendingCount = $pendingCount->fetchColumn();
$approvedTotal = $pdo->prepare("SELECT SUM(amount) FROM telegram_orders WHERE status='approved' AND reseller_id=?");
$approvedTotal->execute([$rid]); $approvedTotal = $approvedTotal->fetchColumn() ?: 0;
$rejectedCount = $pdo->prepare("SELECT COUNT(*) FROM telegram_orders WHERE status='rejected' AND reseller_id=?");
$rejectedCount->execute([$rid]); $rejectedCount = $rejectedCount->fetchColumn();

$filter = $_GET['filter'] ?? 'pending';
$statusWhere = in_array($filter, ['pending', 'approved', 'rejected'], true) ? "AND o.status='$filter'" : '';

$orders = $pdo->prepare("
    SELECT o.*, c.tg_username, c.full_name AS customer_name, c.chat_id
    FROM telegram_orders o
    JOIN telegram_customers c ON o.telegram_customer_id = c.id
    WHERE o.reseller_id = ? $statusWhere
    ORDER BY o.created_at DESC
    LIMIT 100
");
$orders->execute([$rid]);
$orders = $orders->fetchAll();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>سفارش‌های مستقیم - پنل ریسلر</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{--bg:#08100a;--sidebar:#0c1810;--card:#131f17;--border:#1e3025;--accent:#10b981;--accent2:#06b6d4;--purple:#8b5cf6;--text:#e2e8f0;--text2:#94a3b8;--muted:#475569;--danger:#ef4444;--success:#10b981;--warning:#f59e0b;--sidebar-w:260px}
  html,body{overflow-x:hidden}
  body{font-family:'Vazirmatn',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
  .sidebar{width:var(--sidebar-w);background:var(--sidebar);border-left:1px solid var(--border);position:fixed;right:0;top:0;bottom:0;display:flex;flex-direction:column;z-index:100}
  .overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
  .hamburger{display:none;position:fixed;top:14px;right:14px;z-index:101;background:var(--sidebar);border:1px solid var(--border);border-radius:9px;padding:8px 10px;cursor:pointer;color:var(--text);font-size:18px}
  @media(max-width:768px){.sidebar{transform:translateX(100%);transition:.3s}.sidebar.open{transform:none}.overlay.open{display:block}.hamburger{display:block}.main{margin-right:0!important}.content{padding:20px 16px!important}.topbar{padding:14px 16px!important;padding-right:60px!important}.stats-row{grid-template-columns:1fr!important}}
  .sidebar-logo{padding:28px 24px;border-bottom:1px solid var(--border)}
  .logo-text{font-size:20px;font-weight:900;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
  .logo-badge{font-size:10px;color:var(--muted);-webkit-text-fill-color:var(--muted)}
  .sidebar-nav{flex:1;padding:16px 12px;overflow-y:auto}
  .nav-section-label{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);padding:8px 12px 4px;font-weight:600}
  .nav-item{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:10px;color:var(--text2);text-decoration:none;font-size:14px;font-weight:500;transition:all .2s;margin-bottom:2px}
  .nav-item:hover{background:rgba(16,185,129,.08);color:var(--text)}
  .nav-item.active{background:rgba(16,185,129,.15);color:var(--accent)}
  .pending-badge{background:rgba(245,158,11,.2);color:var(--warning);padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;margin-right:auto}
  .sidebar-footer{padding:16px 12px;border-top:1px solid var(--border)}
  .reseller-info{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;background:rgba(255,255,255,.03);margin-bottom:4px}
  .reseller-avatar{width:36px;height:36px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:10px;display:flex;align-items:center;justify-content:center}
  .reseller-name{font-size:13px;font-weight:600}
  .reseller-role{font-size:11px;color:var(--muted)}
  .logout-btn{color:var(--danger)!important}
  .main{margin-right:var(--sidebar-w);flex:1}
  .topbar{background:var(--sidebar);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
  .page-title{font-size:20px;font-weight:700}
  .content{padding:32px}
  .alert{padding:14px 18px;border-radius:10px;margin-bottom:20px;font-size:14px}
  .alert-success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:#6ee7b7}
  .alert-danger{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
  .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:28px}
  .stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;display:flex;align-items:center;gap:16px}
  .stat-icon{font-size:30px}
  .stat-value{font-size:26px;font-weight:900}
  .stat-label{font-size:13px;color:var(--text2);margin-top:2px}
  .filter-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
  .tab{padding:8px 20px;border-radius:10px;font-size:13px;font-weight:600;text-decoration:none;color:var(--text2);border:1px solid var(--border);transition:all .2s}
  .tab:hover{border-color:var(--accent);color:var(--accent)}
  .tab.active{background:rgba(16,185,129,.15);border-color:var(--accent);color:var(--accent)}
  .requests-list{display:flex;flex-direction:column;gap:16px}
  .req-card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden}
  .req-card.pending{border-color:rgba(245,158,11,.4)}
  .req-card.approved{border-color:rgba(16,185,129,.3)}
  .req-card.rejected{border-color:rgba(239,68,68,.3)}
  .req-header{padding:18px 24px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:10px}
  .req-info{display:flex;align-items:center;gap:16px}
  .req-avatar{width:44px;height:44px;border-radius:12px;background:rgba(16,185,129,.15);display:flex;align-items:center;justify-content:center;font-size:20px}
  .req-name{font-size:15px;font-weight:700;color:var(--text)}
  .req-meta{font-size:12px;color:var(--muted);margin-top:3px}
  .req-amount{font-size:24px;font-weight:900;color:var(--success)}
  .req-body{padding:18px 24px;display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap}
  .receipt-thumb{width:120px;height:90px;border-radius:10px;border:1px solid var(--border);object-fit:cover;cursor:pointer;transition:transform .2s}
  .receipt-thumb:hover{transform:scale(1.05)}
  .req-details{flex:1;min-width:200px}
  .req-actions{padding:18px 24px;border-top:1px solid var(--border);display:flex;gap:10px;align-items:center;background:rgba(0,0,0,.15);flex-wrap:wrap}
  .btn{padding:10px 20px;border-radius:9px;font-family:'Vazirmatn';font-size:13px;font-weight:700;cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:6px}
  .btn-approve{background:rgba(16,185,129,.15);color:var(--success);border:1px solid rgba(16,185,129,.4)}
  .btn-approve:hover{background:rgba(16,185,129,.3);transform:translateY(-1px)}
  .btn-reject{background:rgba(239,68,68,.1);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
  .btn-reject:hover{background:rgba(239,68,68,.2);transform:translateY(-1px)}
  .badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700}
  .badge-pending{background:rgba(245,158,11,.1);color:var(--warning);border:1px solid rgba(245,158,11,.3)}
  .badge-approved{background:rgba(16,185,129,.1);color:var(--success);border:1px solid rgba(16,185,129,.3)}
  .badge-rejected{background:rgba(239,68,68,.1);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
  .badge-type{background:rgba(139,92,246,.1);color:#a78bfa;border:1px solid rgba(139,92,246,.2)}
  .reviewed-info{font-size:12px;color:var(--muted)}
  .lightbox{position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:1000;display:none;align-items:center;justify-content:center;padding:20px}
  .lightbox.open{display:flex}
  .lightbox img{max-width:90vw;max-height:90vh;border-radius:12px;object-fit:contain}
  .empty-state{text-align:center;padding:60px;color:var(--muted)}
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
  <nav class="sidebar-nav">
    <div class="nav-section-label">اصلی</div>
    <a href="dashboard.php" class="nav-item">📊 داشبورد</a>
    <div class="nav-section-label">کاربران</div>
    <a href="users.php" class="nav-item">👥 مدیریت کاربران</a>
    <a href="online.php" class="nav-item">🟢 کاربران آنلاین</a>
    <div class="nav-section-label">مالی</div>
    <a href="transactions.php" class="nav-item">💳 تراکنش‌های من</a>
    <a href="renewals.php" class="nav-item">🔄 کاربران تمدیدشده</a>
    <a href="payments.php" class="nav-item">🧾 ارسال فیش پرداخت</a>
    <div class="nav-section-label">فروش مستقیم تلگرام</div>
    <a href="direct_orders.php" class="nav-item active">
      🛒 سفارش‌های مستقیم
      <?php if ($pendingCount > 0): ?><span class="pending-badge"><?= $pendingCount ?></span><?php endif; ?>
    </a>
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
    <div class="page-title">🛒 سفارش‌های مستقیم بات من</div>
    <?php if ($pendingCount > 0): ?>
    <span style="background:rgba(245,158,11,.15);color:var(--warning);border:1px solid rgba(245,158,11,.3);padding:6px 16px;border-radius:20px;font-size:13px;font-weight:700">
      ⏳ <?= $pendingCount ?> سفارش در انتظار تأیید
    </span>
    <?php endif; ?>
  </div>

  <div class="content">
    <?php if ($success): ?><div class="alert alert-success"><?= sanitize($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger">❌ <?= sanitize($error) ?></div><?php endif; ?>

    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon">⏳</div>
        <div><div class="stat-value" style="color:var(--warning)"><?= $pendingCount ?></div><div class="stat-label">در انتظار تأیید</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">✅</div>
        <div><div class="stat-value" style="color:var(--success)"><?= money($approvedTotal) ?></div><div class="stat-label">کل مبالغ تأیید شده (تومان)</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">❌</div>
        <div><div class="stat-value" style="color:var(--danger)"><?= $rejectedCount ?></div><div class="stat-label">رد شده</div></div>
      </div>
    </div>

    <div class="filter-tabs">
      <a href="?filter=pending" class="tab <?= $filter==='pending'?'active':'' ?>">⏳ در انتظار (<?= $pendingCount ?>)</a>
      <a href="?filter=approved" class="tab <?= $filter==='approved'?'active':'' ?>">✅ تأیید شده</a>
      <a href="?filter=rejected" class="tab <?= $filter==='rejected'?'active':'' ?>">❌ رد شده</a>
      <a href="?filter=all" class="tab <?= $filter==='all'?'active':'' ?>">📋 همه</a>
    </div>

    <?php if (empty($orders)): ?>
    <div class="empty-state">
      <div style="font-size:50px;margin-bottom:16px">🛒</div>
      <div style="font-size:16px">سفارشی در این دسته‌بندی وجود ندارد</div>
    </div>
    <?php else: ?>
    <div class="requests-list">
      <?php foreach ($orders as $o): ?>
      <div class="req-card <?= $o['status'] ?>">
        <div class="req-header">
          <div class="req-info">
            <div class="req-avatar"><?= $o['order_type']==='new' ? '🛒' : '🔄' ?></div>
            <div>
              <div class="req-name"><?= sanitize($o['tg_username'] ? '@'.$o['tg_username'] : ($o['customer_name'] ?: $o['chat_id'])) ?></div>
              <div class="req-meta">
                سفارش #<?= $o['id'] ?> &nbsp;·&nbsp;
                <span class="badge badge-type"><?= $o['order_type']==='new' ? 'خرید جدید' : 'تمدید' ?></span>
                &nbsp;·&nbsp; <?= date('Y/m/d H:i', strtotime($o['created_at'])) ?>
              </div>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:16px">
            <div class="req-amount"><?= money($o['amount']) ?> ت</div>
            <?php if ($o['status']==='pending'): ?>
              <span class="badge badge-pending">⏳ در انتظار</span>
            <?php elseif ($o['status']==='approved'): ?>
              <span class="badge badge-approved">✅ تأیید شده</span>
            <?php else: ?>
              <span class="badge badge-rejected">❌ رد شده</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="req-body">
          <?php
          $file = $o['receipt_file'];
          $ext = strtolower(pathinfo((string)$file, PATHINFO_EXTENSION));
          $url = '../uploads/receipts/' . $file;
          ?>
          <div>
            <?php if ($ext === 'pdf'): ?>
              <a href="<?= $url ?>" target="_blank" class="receipt-thumb" style="display:flex;align-items:center;justify-content:center;text-decoration:none;color:var(--danger)">📄 PDF</a>
            <?php else: ?>
              <img src="<?= $url ?>" class="receipt-thumb" onclick="openLightbox(this.src)" alt="رسید">
            <?php endif; ?>
          </div>
          <div class="req-details">
            <div style="font-size:13px;color:var(--text2);margin-bottom:6px">یوزرنیم: <strong style="color:var(--text)"><?= sanitize($o['target_username']) ?></strong></div>
            <?php if ($o['status'] !== 'pending' && $o['reviewed_at']): ?>
            <div class="reviewed-info" style="margin-top:8px">
              بررسی توسط <?= sanitize($o['reviewed_by_name'] ?? 'نامشخص') ?> در <?= date('Y/m/d H:i', strtotime($o['reviewed_at'])) ?>
              <?php if ($o['status']==='approved' && $o['ibs_uid']): ?> · UID: <?= sanitize($o['ibs_uid']) ?><?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($o['status'] === 'pending'): ?>
        <div class="req-actions">
          <form method="POST" onsubmit="return confirm('این سفارش روی IBSng اعمال و از موجودی شما کسر می‌شود. تأیید می‌کنید؟')"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
            <input type="hidden" name="action" value="approve">
            <button type="submit" class="btn btn-approve">✅ تأیید و ایجاد/تمدید سرویس</button>
          </form>
          <form method="POST" onsubmit="return confirm('این سفارش رد شود؟')"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
            <input type="hidden" name="action" value="reject">
            <button type="submit" class="btn btn-reject">❌ رد کردن</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</main>

<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <img id="lightboxImg" src="" alt="رسید">
</div>
<script>
function toggleSB(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('open')}
function closeSB(){document.getElementById('sidebar').classList.remove('open');document.getElementById('overlay').classList.remove('open')}
function openLightbox(src){document.getElementById('lightboxImg').src=src;document.getElementById('lightbox').classList.add('open');}
function closeLightbox(){document.getElementById('lightbox').classList.remove('open');}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });
</script>
</body>
</html>

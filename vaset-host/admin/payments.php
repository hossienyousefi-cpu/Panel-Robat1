<?php
require_once '../includes/config.php';
requireAdmin();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('درخواست نامعتبر است (احتمالاً صفحه قدیمی شده). لطفاً صفحه را رفرش کرده و دوباره امتحان کنید.');
}

$success = ''; $error = '';

// تأیید یا رد درخواست
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reqId  = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note   = sanitize($_POST['admin_note'] ?? '');

    if ($reqId && in_array($action, ['approve','reject'])) {
        $req = $pdo->prepare("SELECT * FROM payment_requests WHERE id=? AND status='pending'");
        $req->execute([$reqId]); $req = $req->fetch();

        if (!$req) {
            $error = 'درخواست یافت نشد یا قبلاً بررسی شده';
        } elseif ($action === 'approve') {
            // کاهش بدهی ریسلر و شارژ حساب (مثل شارژ دستی در debts.php)
            $pdo->prepare("UPDATE resellers SET debt = GREATEST(0, debt - ?), balance = balance + ? WHERE id=?")->execute([$req['amount'], $req['amount'], $req['reseller_id']]);
            // ثبت تراکنش
            $pdo->prepare("INSERT INTO transactions (reseller_id, type, amount, description, created_by_admin) VALUES (?,?,?,?,?)")
                ->execute([$req['reseller_id'], 'credit', $req['amount'], 'تأیید فیش پرداخت #'.$reqId.($note?" - $note":''), $_SESSION['admin_id']]);
            // آپدیت وضعیت درخواست
            $pdo->prepare("UPDATE payment_requests SET status='approved', admin_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                ->execute([$note, $_SESSION['admin_id'], $reqId]);
            logActivity('admin', $_SESSION['admin_id'], 'approve_payment', "تأیید فیش #$reqId مبلغ {$req['amount']} تومان - ریسلر #{$req['reseller_id']}");
            $success = 'فیش پرداخت تأیید شد و مبلغ به حساب ریسلر اضافه گردید ✅';
        } else {
            $pdo->prepare("UPDATE payment_requests SET status='rejected', admin_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                ->execute([$note, $_SESSION['admin_id'], $reqId]);
            logActivity('admin', $_SESSION['admin_id'], 'reject_payment', "رد فیش #$reqId - ریسلر #{$req['reseller_id']}");
            $success = 'فیش پرداخت رد شد ❌';
        }
    }
}

// آمار
$pendingCount  = $pdo->query("SELECT COUNT(*) FROM payment_requests WHERE status='pending'")->fetchColumn();
$approvedTotal = $pdo->query("SELECT SUM(amount) FROM payment_requests WHERE status='approved'")->fetchColumn() ?: 0;
$rejectedCount = $pdo->query("SELECT COUNT(*) FROM payment_requests WHERE status='rejected'")->fetchColumn();

// لیست درخواست‌ها
$filter = $_GET['filter'] ?? 'pending';
$where  = in_array($filter, ['pending','approved','rejected']) ? "WHERE pr.status='$filter'" : '';

$requests = $pdo->query("
    SELECT pr.*, r.username as reseller_name, r.debt as reseller_debt,
           a.username as reviewer_name
    FROM payment_requests pr
    JOIN resellers r ON pr.reseller_id = r.id
    LEFT JOIN admins a ON pr.reviewed_by = a.id
    $where
    ORDER BY pr.created_at DESC
    LIMIT 100
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>مدیریت فیش‌های پرداخت - پنل مدیریت</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{--bg:#080c18;--sidebar:#0d1424;--card:#131e30;--card2:#162030;--border:#1e2d45;--accent:#3b82f6;--accent2:#06b6d4;--purple:#8b5cf6;--text:#e2e8f0;--text2:#94a3b8;--muted:#475569;--danger:#ef4444;--success:#10b981;--warning:#f59e0b;--sidebar-w:260px}
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
  .alert{padding:14px 18px;border-radius:10px;margin-bottom:20px;font-size:14px}
  .alert-success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:#6ee7b7}
  .alert-danger{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
  .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:28px}
  .stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;display:flex;align-items:center;gap:16px}
  .stat-icon{font-size:30px}
  .stat-value{font-size:26px;font-weight:900}
  .stat-label{font-size:13px;color:var(--text2);margin-top:2px}
  .filter-tabs{display:flex;gap:8px;margin-bottom:20px}
  .tab{padding:8px 20px;border-radius:10px;font-size:13px;font-weight:600;text-decoration:none;color:var(--text2);border:1px solid var(--border);transition:all .2s}
  .tab:hover{border-color:var(--accent);color:var(--accent)}
  .tab.active{background:rgba(59,130,246,.15);border-color:var(--accent);color:var(--accent)}
  .tab.pending{color:var(--warning);border-color:rgba(245,158,11,.3)}
  .tab.pending.active{background:rgba(245,158,11,.1)}
  .requests-list{display:flex;flex-direction:column;gap:16px}
  .req-card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;transition:border-color .2s}
  .req-card.pending{border-color:rgba(245,158,11,.4)}
  .req-card.approved{border-color:rgba(16,185,129,.3)}
  .req-card.rejected{border-color:rgba(239,68,68,.3)}
  .req-header{padding:18px 24px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)}
  .req-info{display:flex;align-items:center;gap:16px}
  .req-avatar{width:44px;height:44px;border-radius:12px;background:rgba(59,130,246,.15);display:flex;align-items:center;justify-content:center;font-size:20px}
  .req-name{font-size:15px;font-weight:700;color:var(--text)}
  .req-meta{font-size:12px;color:var(--muted);margin-top:3px}
  .req-amount{font-size:24px;font-weight:900;color:var(--success)}
  .req-body{padding:18px 24px;display:flex;gap:20px;align-items:flex-start}
  .req-receipt{flex-shrink:0}
  .receipt-thumb{width:120px;height:90px;border-radius:10px;border:1px solid var(--border);object-fit:cover;cursor:pointer;transition:transform .2s}
  .receipt-thumb:hover{transform:scale(1.05)}
  .receipt-pdf{width:120px;height:90px;border-radius:10px;border:1px solid var(--border);background:rgba(239,68,68,.1);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:6px;text-decoration:none;color:var(--danger);font-size:12px;font-weight:600}
  .req-details{flex:1}
  .req-desc{font-size:14px;color:var(--text2);margin-bottom:12px}
  .req-debt{font-size:13px;padding:8px 14px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:8px;display:inline-block;color:#f87171}
  .req-actions{padding:18px 24px;border-top:1px solid var(--border);display:flex;gap:10px;align-items:center;background:rgba(0,0,0,.15)}
  .req-note{flex:1}
  .req-note input{width:100%;padding:10px 14px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:'Vazirmatn';font-size:13px;outline:none}
  .req-note input:focus{border-color:var(--accent)}
  .btn{padding:10px 20px;border-radius:9px;font-family:'Vazirmatn';font-size:13px;font-weight:700;cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:6px}
  .btn-approve{background:rgba(16,185,129,.15);color:var(--success);border:1px solid rgba(16,185,129,.4)}
  .btn-approve:hover{background:rgba(16,185,129,.3);transform:translateY(-1px)}
  .btn-reject{background:rgba(239,68,68,.1);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
  .btn-reject:hover{background:rgba(239,68,68,.2);transform:translateY(-1px)}
  .badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700}
  .badge-pending{background:rgba(245,158,11,.1);color:var(--warning);border:1px solid rgba(245,158,11,.3)}
  .badge-approved{background:rgba(16,185,129,.1);color:var(--success);border:1px solid rgba(16,185,129,.3)}
  .badge-rejected{background:rgba(239,68,68,.1);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
  .reviewed-info{font-size:12px;color:var(--muted)}
  /* Lightbox */
  .lightbox{position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:1000;display:none;align-items:center;justify-content:center;padding:20px}
  .lightbox.open{display:flex}
  .lightbox img{max-width:90vw;max-height:90vh;border-radius:12px;object-fit:contain}
  .lightbox-close{position:absolute;top:20px;left:20px;color:#fff;font-size:32px;cursor:pointer;background:rgba(255,255,255,.1);width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center}
  .empty-state{text-align:center;padding:60px;color:var(--muted)}
  .pending-badge{background:rgba(245,158,11,.15);color:var(--warning);border:1px solid rgba(245,158,11,.4);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;margin-right:8px}
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
    <a href="renewals.php" class="nav-item">🔄 کاربران تمدیدشده</a>
    <a href="debts.php" class="nav-item">💰 مدیریت بدهی</a>
    <a href="payments.php" class="nav-item active">
      🧾 فیش‌های پرداخت
      <?php if ($pendingCount > 0): ?>
      <span class="pending-badge"><?= $pendingCount ?></span>
      <?php endif; ?>
    </a>
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
    <div class="page-title">🧾 فیش‌های پرداخت ریسلرها</div>
    <?php if ($pendingCount > 0): ?>
    <span style="background:rgba(245,158,11,.15);color:var(--warning);border:1px solid rgba(245,158,11,.3);padding:6px 16px;border-radius:20px;font-size:13px;font-weight:700">
      ⏳ <?= $pendingCount ?> فیش در انتظار تأیید
    </span>
    <?php endif; ?>
  </div>

  <div class="content">
    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger">❌ <?= $error ?></div><?php endif; ?>

    <!-- آمار -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon">⏳</div>
        <div><div class="stat-value" style="color:var(--warning)"><?= $pendingCount ?></div><div class="stat-label">در انتظار تأیید</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">✅</div>
        <div><div class="stat-value" style="color:var(--success)"><?= number_format($approvedTotal) ?></div><div class="stat-label">کل مبالغ تأیید شده (تومان)</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">❌</div>
        <div><div class="stat-value" style="color:var(--danger)"><?= $rejectedCount ?></div><div class="stat-label">رد شده</div></div>
      </div>
    </div>

    <!-- فیلتر -->
    <div class="filter-tabs">
      <a href="?filter=pending"  class="tab pending <?= $filter==='pending' ?'active':'' ?>">⏳ در انتظار (<?= $pendingCount ?>)</a>
      <a href="?filter=approved" class="tab <?= $filter==='approved'?'active':'' ?>">✅ تأیید شده</a>
      <a href="?filter=rejected" class="tab <?= $filter==='rejected'?'active':'' ?>">❌ رد شده</a>
      <a href="?filter=all"      class="tab <?= $filter==='all'?'active':'' ?>">📋 همه</a>
    </div>

    <!-- لیست فیش‌ها -->
    <?php if (empty($requests)): ?>
    <div class="empty-state">
      <div style="font-size:50px;margin-bottom:16px">🧾</div>
      <div style="font-size:16px">فیشی در این دسته‌بندی وجود ندارد</div>
    </div>
    <?php else: ?>
    <div class="requests-list">
      <?php foreach ($requests as $req): ?>
      <div class="req-card <?= $req['status'] ?>">
        <div class="req-header">
          <div class="req-info">
            <div class="req-avatar">👤</div>
            <div>
              <div class="req-name"><?= sanitize($req['reseller_name']) ?></div>
              <div class="req-meta">
                فیش #<?= $req['id'] ?> &nbsp;·&nbsp;
                <?= date('Y/m/d H:i', strtotime($req['created_at'])) ?>
              </div>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:16px">
            <div class="req-amount"><?= number_format($req['amount']) ?> ت</div>
            <?php if ($req['status']==='pending'): ?>
              <span class="badge badge-pending">⏳ در انتظار</span>
            <?php elseif ($req['status']==='approved'): ?>
              <span class="badge badge-approved">✅ تأیید شده</span>
            <?php else: ?>
              <span class="badge badge-rejected">❌ رد شده</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="req-body">
          <!-- تصویر فیش -->
          <div class="req-receipt">
            <?php
            $file = $req['receipt_file'];
            $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $url  = '../uploads/receipts/' . $file;
            ?>
            <?php if ($ext === 'pdf'): ?>
              <a href="<?= $url ?>" target="_blank" class="receipt-pdf">
                <span style="font-size:28px">📄</span>
                <span>مشاهده PDF</span>
              </a>
            <?php else: ?>
              <img src="<?= $url ?>" class="receipt-thumb" onclick="openLightbox(this.src)" alt="فیش پرداخت">
            <?php endif; ?>
          </div>

          <!-- جزئیات -->
          <div class="req-details">
            <?php if ($req['description']): ?>
            <div class="req-desc">💬 <?= sanitize($req['description']) ?></div>
            <?php endif; ?>
            <div class="req-debt">
              بدهی فعلی ریسلر: <strong><?= number_format($req['reseller_debt']) ?> تومان</strong>
              <?php if ($req['status']==='approved'): ?>
              &nbsp;← پس از تأیید: <strong><?= number_format(max(0, $req['reseller_debt'] - $req['amount'])) ?> تومان</strong>
              <?php endif; ?>
            </div>
            <?php if ($req['status'] !== 'pending' && $req['admin_note']): ?>
            <div style="margin-top:12px;font-size:13px;color:var(--text2)">
              📝 یادداشت ادمین: <?= sanitize($req['admin_note']) ?>
            </div>
            <?php endif; ?>
            <?php if ($req['reviewed_at']): ?>
            <div class="reviewed-info" style="margin-top:8px">
              بررسی توسط <?= sanitize($req['reviewer_name'] ?? 'ادمین') ?> در <?= date('Y/m/d H:i', strtotime($req['reviewed_at'])) ?>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($req['status'] === 'pending'): ?>
        <div class="req-actions">
          <div class="req-note">
            <input type="text" id="note_<?= $req['id'] ?>" placeholder="یادداشت (اختیاری)...">
          </div>
          <form method="POST" onsubmit="return fillNote(<?= $req['id'] ?>, 'approve')">
            <input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="admin_note" id="note_approve_<?= $req['id'] ?>">
            <button type="submit" class="btn btn-approve" onclick="fillNoteApprove(<?= $req['id'] ?>)">✅ تأیید و شارژ حساب</button>
          </form>
          <form method="POST" onsubmit="return confirm('آیا مطمئن هستید؟')"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="admin_note" id="note_reject_<?= $req['id'] ?>">
            <button type="submit" class="btn btn-reject" onclick="document.getElementById('note_reject_<?= $req['id'] ?>').value=document.getElementById('note_<?= $req['id'] ?>').value">❌ رد کردن</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</main>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <div class="lightbox-close" onclick="closeLightbox()">✕</div>
  <img id="lightboxImg" src="" alt="فیش پرداخت">
</div>

<script>
function fillNoteApprove(id) {
  document.getElementById('note_approve_' + id).value = document.getElementById('note_' + id).value;
}
function openLightbox(src) {
  document.getElementById('lightboxImg').src = src;
  document.getElementById('lightbox').classList.add('open');
}
function closeLightbox() {
  document.getElementById('lightbox').classList.remove('open');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });
</script>
</body>
</html>

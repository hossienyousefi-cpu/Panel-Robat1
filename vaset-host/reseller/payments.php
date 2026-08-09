<?php
require_once '../includes/config.php';
require_once '../includes/icons.php';
requireReseller();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('درخواست نامعتبر است (احتمالاً صفحه قدیمی شده). لطفاً صفحه را رفرش کرده و دوباره امتحان کنید.');
}

$rid = $_SESSION['reseller_id'];
$reseller = $pdo->prepare("SELECT * FROM resellers WHERE id=?");
$reseller->execute([$rid]); $reseller = $reseller->fetch();

$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = parseMoney($_POST['amount'] ?? 0);
    $desc   = sanitize($_POST['description'] ?? '');

    if ($amount <= 0) {
        $error = 'مبلغ باید بیشتر از صفر باشد';
    } elseif (empty($_FILES['receipt']['name'])) {
        $error = 'لطفاً فیش پرداخت را آپلود کنید';
    } else {
        $file    = $_FILES['receipt'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','pdf','webp'];
        $maxSize = 5 * 1024 * 1024;

        // بررسی نوع واقعی فایل (نه فقط پسوند اسمی) تا کسی نتونه یه فایل اجرایی رو
        // با پسوند jpg/png آپلود کنه
        $realMime = @mime_content_type($file['tmp_name']);
        $mimeOk = in_array($realMime, ['image/jpeg','image/png','image/webp','application/pdf'], true);

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'خطا در آپلود فایل: ' . $file['error'];
        } elseif (!in_array($ext, $allowed)) {
            $error = 'فرمت فایل مجاز نیست (jpg, png, pdf)';
        } elseif (!$mimeOk) {
            $error = 'محتوای فایل با فرمت مجاز (تصویر یا PDF) مطابقت ندارد';
        } elseif ($file['size'] > $maxSize) {
            $error = 'حجم فایل نباید بیشتر از ۵ مگابایت باشد';
        } else {
            $uploadDir = __DIR__ . '/../uploads/receipts/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            $filename = 'receipt_' . $rid . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $filepath = $uploadDir . $filename;

            if (!is_dir($uploadDir)) {
                $error = 'پوشه uploads/receipts وجود ندارد. لطفاً آن را بسازید.';
            } elseif (move_uploaded_file($file['tmp_name'], $filepath)) {
                $stmt = $pdo->prepare("INSERT INTO payment_requests (reseller_id, amount, description, receipt_file) VALUES (?,?,?,?)");
                $stmt->execute([$rid, $amount, $desc, $filename]);
                logActivity('reseller', $rid, 'payment_request', "درخواست پرداخت $amount تومان");
                $success = 'فیش پرداخت با موفقیت ارسال شد. پس از تأیید ادمین، مبلغ به حساب شما اضافه می‌شود.';
            } else {
                $error = 'خطا در ذخیره فایل. مطمئن شوید پوشه uploads/receipts قابل نوشتن است.';
            }
        }
    }
}

$requests = $pdo->prepare("SELECT * FROM payment_requests WHERE reseller_id=? ORDER BY created_at DESC LIMIT 50");
$requests->execute([$rid]); $requests = $requests->fetchAll();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ارسال فیش پرداخت - پنل ریسلر</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<link href="../assets/css/theme.css" rel="stylesheet">
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{--bg:#08100a;--sidebar:#0c1810;--card:#131f17;--border:#1e3025;--accent:#10b981;--accent2:#06b6d4;--text:#e2e8f0;--text2:#94a3b8;--muted:#475569;--danger:#ef4444;--success:#10b981;--warning:#f59e0b;--sidebar-w:260px}
  html,body{overflow-x:hidden}
  body{font-family:'Vazirmatn',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
  .sidebar{width:var(--sidebar-w);background:var(--sidebar);border-left:1px solid var(--border);position:fixed;right:0;top:0;bottom:0;display:flex;flex-direction:column;z-index:100}
  .overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
  .hamburger{display:none;position:fixed;top:14px;right:14px;z-index:101;background:var(--sidebar);border:1px solid var(--border);border-radius:9px;padding:8px 10px;cursor:pointer;color:var(--text);font-size:18px}
  @media(max-width:768px){
    .sidebar{transform:translateX(100%);transition:.3s}
    .sidebar.open{transform:none}
    .overlay.open{display:block}
    .hamburger{display:block}
    .main{margin-right:0!important}
    .content{padding:20px 16px!important;max-width:100%!important}
    .topbar{padding:14px 16px!important;padding-right:60px!important;flex-wrap:wrap;gap:8px}
    .page-title{font-size:16px!important}
    .upload-zone{padding:24px!important}
  }
  .sidebar-logo{padding:28px 24px;border-bottom:1px solid var(--border)}
  .logo-text{font-size:20px;font-weight:900;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
  .logo-badge{font-size:10px;color:var(--muted);-webkit-text-fill-color:var(--muted)}
  .debt-banner{margin:12px 12px 0;padding:12px 16px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:10px}
  .debt-label{font-size:11px;color:var(--muted)}
  .debt-amount{font-size:18px;font-weight:900;color:#f87171}
  .sidebar-nav{flex:1;padding:16px 12px;overflow-y:auto}
  .nav-section-label{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);padding:8px 12px 4px;font-weight:600}
  .nav-item{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:10px;color:var(--text2);text-decoration:none;font-size:14px;font-weight:500;transition:all .2s;margin-bottom:2px}
  .nav-item:hover{background:rgba(16,185,129,.08);color:var(--text)}
  .nav-item.active{background:rgba(16,185,129,.15);color:var(--accent)}
  .sidebar-footer{padding:16px 12px;border-top:1px solid var(--border)}
  .reseller-info{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;background:rgba(255,255,255,.03);margin-bottom:4px}
  .reseller-avatar{width:36px;height:36px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:10px;display:flex;align-items:center;justify-content:center}
  .reseller-name{font-size:13px;font-weight:600}
  .reseller-role{font-size:11px;color:var(--muted)}
  .logout-btn{color:var(--danger)!important}
  .main{margin-right:var(--sidebar-w);flex:1}
  .topbar{background:var(--sidebar);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
  .page-title{font-size:20px;font-weight:700}
  .content{padding:32px;max-width:860px}
  .alert{padding:14px 18px;border-radius:10px;margin-bottom:20px;font-size:14px}
  .alert-success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:#6ee7b7}
  .alert-danger{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
  .card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:24px}
  .card-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
  .card-title{font-size:16px;font-weight:700}
  .card-body{padding:28px 24px}
  .form-group{margin-bottom:20px}
  label{display:block;font-size:13px;font-weight:500;color:var(--text2);margin-bottom:8px}
  input[type=text],input[type=number]{width:100%;padding:12px 14px;background:var(--bg);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:'Vazirmatn';font-size:14px;outline:none;transition:border .2s}
  input:focus{border-color:var(--accent)}
  .upload-zone{border:2px dashed var(--border);border-radius:12px;padding:40px;text-align:center;cursor:pointer;transition:all .2s;position:relative;overflow:hidden}
  .upload-zone:hover{border-color:var(--accent);background:rgba(16,185,129,.05)}
  .upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
  .upload-icon{font-size:40px;margin-bottom:12px}
  .upload-text{font-size:14px;color:var(--text2)}
  .upload-sub{font-size:12px;color:var(--muted);margin-top:6px}
  .upload-preview{margin-top:12px;font-size:13px;color:var(--accent);font-weight:600;display:none}
  .btn{padding:12px 28px;border-radius:10px;font-family:'Vazirmatn';font-size:15px;font-weight:700;cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:8px}
  .btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff}
  .btn-primary:hover{transform:translateY(-2px);box-shadow:0 10px 25px rgba(16,185,129,.3)}
  .table-wrapper{overflow-x:auto}
  .table{width:100%;border-collapse:collapse;min-width:560px}
  .table th{text-align:right;font-size:12px;font-weight:600;color:var(--muted);padding:12px 16px;border-bottom:1px solid var(--border);background:rgba(0,0,0,.2)}
  .table td{padding:14px 16px;font-size:13px;border-bottom:1px solid rgba(30,48,37,.5);color:var(--text2);vertical-align:middle}
  .table tr:last-child td{border-bottom:none}
  .badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700}
  .badge-pending{background:rgba(245,158,11,.1);color:var(--warning);border:1px solid rgba(245,158,11,.3)}
  .badge-approved{background:rgba(16,185,129,.1);color:var(--success);border:1px solid rgba(16,185,129,.3)}
  .badge-rejected{background:rgba(239,68,68,.1);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
</style>
</head>
<body class="theme-reseller">
<div class="overlay" id="overlay" onclick="closeSB()"></div>
<button class="hamburger" onclick="toggleSB()" title="منو">☰</button>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
    <?php $siteLogo=getSetting('site_logo',''); if($siteLogo&&file_exists(dirname(__DIR__).'/'.$siteLogo)): ?>
    <img src="../<?=sanitize($siteLogo)?>" alt="لوگو" style="max-height:52px;max-width:180px;object-fit:contain;margin-bottom:4px;display:block">
    <?php else: ?>
    <div class="logo-text"><?=svgIcon('globe')?> پنل ریسلر</div>
    <?php endif; ?>
    <div class="logo-badge">پنل ریسلر</div>
  </div>
  <?php if ($reseller['debt'] > 0): ?>
  <div class="debt-banner">
    <div class="debt-label"><?=svgIcon('card')?> بدهی شما</div>
    <div class="debt-amount"><?= money($reseller['debt']) ?> تومان</div>
  </div>
  <?php endif; ?>
  <nav class="sidebar-nav">
    <div class="nav-section-label">اصلی</div>
    <a href="dashboard.php" class="nav-item"><?=svgIcon('dashboard')?> داشبورد</a>
    <div class="nav-section-label">کاربران</div>
    <a href="users.php" class="nav-item"><?=svgIcon('users')?> مدیریت کاربران</a>
    <a href="online.php" class="nav-item">🟢 کاربران آنلاین</a>
    <div class="nav-section-label">مالی</div>
    <a href="transactions.php" class="nav-item"><?=svgIcon('card')?> تراکنش‌های من</a>
    <a href="renewals.php" class="nav-item"><?=svgIcon('refresh')?> کاربران تمدیدشده</a>
    <a href="payments.php" class="nav-item active"><?=svgIcon('receipt')?> ارسال فیش پرداخت</a>
    <div class="nav-section-label">فروش مستقیم تلگرام</div>
    <a href="direct_orders.php" class="nav-item"><?=svgIcon('cart')?> سفارش‌های مستقیم</a>
    <a href="telegram.php" class="nav-item"><?=svgIcon('bot')?> بات تلگرام من</a>
  </nav>
  <div class="sidebar-footer">
    <div class="reseller-info">
      <div class="reseller-avatar"><?=svgIcon('user')?></div>
      <div>
        <div class="reseller-name"><?= sanitize($_SESSION['reseller_username']) ?></div>
        <div class="reseller-role">ریسلر</div>
      </div>
    </div>
    <a href="logout.php" class="nav-item logout-btn"><?=svgIcon('logout')?> خروج</a>
  </div>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title"><?=svgIcon('receipt')?> ارسال فیش پرداخت</div>
    <?php if ($reseller['debt'] > 0): ?>
    <span style="color:var(--danger);font-size:14px;font-weight:600">بدهی: <?= money($reseller['debt']) ?> تومان</span>
    <?php endif; ?>
  </div>
  <div class="content">
    <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger">❌ <?= $error ?></div><?php endif; ?>

    <div class="card">
      <div class="card-header">
        <span style="font-size:22px"><?=svgIcon('upload')?></span>
        <div class="card-title">ارسال فیش پرداخت جدید</div>
      </div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
          <div class="form-group">
            <label>مبلغ پرداختی (تومان) *</label>
            <input type="text" inputmode="numeric" name="amount" placeholder="مثلاً: 500.000" oninput="fmtMoneyInput(this)" required>
          </div>
          <div class="form-group">
            <label>توضیح (اختیاری)</label>
            <input type="text" name="description" placeholder="مثلاً: واریز به شماره حساب ...">
          </div>
          <div class="form-group">
            <label>تصویر یا PDF فیش پرداخت *</label>
            <div class="upload-zone">
              <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.pdf,.webp" onchange="showPreview(this)" required>
              <div class="upload-icon"><?=svgIcon('receipt')?></div>
              <div class="upload-text">فایل را اینجا بکشید یا کلیک کنید</div>
              <div class="upload-sub">JPG، PNG، PDF - حداکثر ۵ مگابایت</div>
              <div class="upload-preview" id="uploadPreview"></div>
            </div>
          </div>
          <button type="submit" class="btn btn-primary"><?=svgIcon('upload')?> ارسال فیش پرداخت</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <span style="font-size:22px"><?=svgIcon('list')?></span>
        <div class="card-title">تاریخچه فیش‌های ارسالی</div>
      </div>
      <div class="table-wrapper">
      <table class="table">
        <thead>
          <tr><th>مبلغ</th><th>توضیح</th><th>وضعیت</th><th>یادداشت ادمین</th><th>تاریخ</th></tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $req): ?>
          <tr>
            <td style="font-weight:700;color:var(--success)"><?= money($req['amount']) ?> ت</td>
            <td><?= sanitize($req['description'] ?: '—') ?></td>
            <td>
              <?php if ($req['status']==='pending'): ?>
                <span class="badge badge-pending">⏳ در انتظار تأیید</span>
              <?php elseif ($req['status']==='approved'): ?>
                <span class="badge badge-approved">✅ تأیید شده</span>
              <?php else: ?>
                <span class="badge badge-rejected">❌ رد شده</span>
              <?php endif; ?>
            </td>
            <td style="color:var(--muted);font-size:12px"><?= sanitize($req['admin_note'] ?: '—') ?></td>
            <td style="font-size:12px;color:var(--muted)"><?= date('Y/m/d H:i',strtotime($req['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($requests)): ?>
          <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--muted)">هنوز فیشی ارسال نشده</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</main>
<script>
function showPreview(input) {
  const p = document.getElementById('uploadPreview');
  if (input.files && input.files[0]) {
    p.textContent = '✅ ' + input.files[0].name + ' (' + (input.files[0].size/1024).toFixed(0) + ' KB)';
    p.style.display = 'block';
  }
}
function fmtMoneyInput(el){var v=el.value.replace(/\D/g,'');el.value=v?v.replace(/\B(?=(\d{3})+(?!\d))/g,'.'):'';}
function toggleSB(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('open')}
function closeSB(){document.getElementById('sidebar').classList.remove('open');document.getElementById('overlay').classList.remove('open')}
</script>
</body>
</html>

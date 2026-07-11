<?php
require_once '../includes/config.php';
requireAdmin();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Change admin password
    if ($action === 'change_admin_pass') {
        $current = $_POST['current_pass'] ?? '';
        $new = $_POST['new_pass'] ?? '';
        $confirm = $_POST['confirm_pass'] ?? '';

        $admin = $pdo->prepare("SELECT * FROM admins WHERE id=?");
        $admin->execute([$_SESSION['admin_id']]);
        $admin = $admin->fetch();

        if (!password_verify($current, $admin['password'])) {
            $error = 'رمز عبور فعلی اشتباه است';
        } elseif ($new !== $confirm) {
            $error = 'رمز عبور جدید و تکرار آن یکسان نیستند';
        } elseif (strlen($new) < 6) {
            $error = 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            $pdo->prepare("UPDATE admins SET password=? WHERE id=?")->execute([$hash, $_SESSION['admin_id']]);
            logActivity('admin', $_SESSION['admin_id'], 'change_own_password', 'ادمین رمز خود را تغییر داد');
            $message = 'رمز عبور ادمین با موفقیت تغییر کرد';
        }
    }

    // Change reseller password
    if ($action === 'change_reseller_pass') {
        $rid = (int)($_POST['reseller_id'] ?? 0);
        $new = $_POST['new_pass'] ?? '';
        $confirm = $_POST['confirm_pass'] ?? '';

        if ($new !== $confirm) {
            $error = 'رمز عبور جدید و تکرار آن یکسان نیستند';
        } elseif (strlen($new) < 6) {
            $error = 'رمز عبور باید حداقل ۶ کاراکتر باشد';
        } elseif ($rid) {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            $pdo->prepare("UPDATE resellers SET password=? WHERE id=?")->execute([$hash, $rid]);
            $r = $pdo->prepare("SELECT username FROM resellers WHERE id=?"); $r->execute([$rid]);
            $rname = $r->fetchColumn();
            logActivity('admin', $_SESSION['admin_id'], 'change_reseller_password', "رمز ریسلر $rname تغییر کرد");
            $message = "رمز عبور ریسلر با موفقیت تغییر کرد";
        }
    }

    // Save  settings
    if ($action === 'save_ibs_settings') {
        $ibs_api_url = sanitize($_POST['ibs_api_url'] ?? '');
        $ibs_user = sanitize($_POST['ibs_user'] ?? '');
        $ibs_pass = $_POST['ibs_pass'] ?? '';
        $site_name = sanitize($_POST['site_name'] ?? '');
        $create_price = (float)($_POST['create_price'] ?? 5000);
        $renew_price = (float)($_POST['renew_price'] ?? 200);

        $updates = [
            'ibs_api_url' => $ibs_api_url,
            'ibs_admin_user' => $ibs_user,
            'site_name' => $site_name,
            'user_create_price' => $create_price,
            'user_renew_price_per_day' => $renew_price,
        ];
        if ($ibs_pass) $updates['ibs_admin_pass'] = $ibs_pass;

        foreach ($updates as $k => $v) {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
        }
        logActivity('admin', $_SESSION['admin_id'], 'update_settings', 'تنظیمات سیستم بروز شد');
        $message = 'تنظیمات با موفقیت ذخیره شد. تغییرات همین الان روی اتصال  اعمال می‌شوند.';
    }

    // آپلود لوگو
    if ($action === 'upload_logo') {
        $uploadDir = dirname(__DIR__) . '/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        if (!empty($_FILES['logo']['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['logo']['tmp_name']);
            finfo_close($finfo);
            $allowed = ['image/png','image/jpeg','image/gif','image/svg+xml','image/webp'];
            if (!in_array($mime, $allowed)) {
                $error = 'فرمت فایل مجاز نیست (PNG, JPG, GIF, SVG, WEBP)';
            } elseif ($_FILES['logo']['size'] > 2*1024*1024) {
                $error = 'حداکثر حجم فایل 2 مگابایت است';
            } else {
                $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                $logoPath = $uploadDir . 'logo.' . $ext;
                foreach (glob($uploadDir . 'logo.*') as $old2) unlink($old2);
                move_uploaded_file($_FILES['logo']['tmp_name'], $logoPath);
                $stmt = $pdo->prepare("SELECT id FROM settings WHERE setting_key='site_logo'");
                $stmt->execute();
                if ($stmt->fetch()) {
                    $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='site_logo'")->execute(['uploads/logo.'.$ext]);
                } else {
                    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('site_logo',?)")->execute(['uploads/logo.'.$ext]);
                }
                $message = 'لوگو با موفقیت آپلود شد';
            }
        } else {
            $error = 'لطفاً یک فایل انتخاب کنید';
        }
    }
}

$resellers = $pdo->query("SELECT id, username, full_name FROM resellers ORDER BY username")->fetchAll();
$ibsApiUrl = getSetting('ibs_api_url', 'http://194.59.214.84/ibs-api/');
$ibsUser = getSetting('ibs_admin_user', IBS_ADMIN);
$siteName = getSetting('site_name', SITE_NAME);
$createPrice = getSetting('user_create_price', '5000');
$renewPrice = getSetting('user_renew_price_per_day', '200');
$siteLogo = getSetting('site_logo', '');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>تنظیمات - پنل مدیریت</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{--bg:#080c18;--sidebar:#0d1424;--surface:#111827;--card:#131e30;--border:#1e2d45;--accent:#3b82f6;--accent2:#06b6d4;--purple:#8b5cf6;--gold:#f59e0b;--text:#e2e8f0;--text2:#94a3b8;--muted:#475569;--danger:#ef4444;--success:#10b981;--warning:#f59e0b;--sidebar-w:260px}
  body{font-family:'Vazirmatn',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh}
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
  .content{padding:32px;max-width:900px}
  .alert{padding:14px 18px;border-radius:10px;margin-bottom:20px;font-size:14px}
  .alert-success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:#6ee7b7}
  .alert-danger{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
  .settings-section{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:24px}
  .section-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
  .section-icon{font-size:20px}
  .section-title{font-size:16px;font-weight:700}
  .section-desc{font-size:13px;color:var(--muted);margin-top:2px}
  .section-body{padding:28px 24px}
  .form-row{display:grid;grid-template-columns:1fr 1fr;gap:20px}
  .form-group{margin-bottom:20px}
  label{display:block;font-size:13px;font-weight:500;color:var(--muted);margin-bottom:8px}
  input,select{width:100%;padding:12px 14px;background:var(--surface);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:'Vazirmatn';font-size:14px;outline:none;transition:border .2s}
  input:focus,select:focus{border-color:var(--accent)}
  .btn{padding:12px 24px;border-radius:10px;font-family:'Vazirmatn';font-size:14px;font-weight:700;cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:8px}
  .btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff}
  .btn-primary:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(59,130,246,.3)}
  .btn-purple{background:linear-gradient(135deg,var(--purple),var(--accent));color:#fff}
  .btn-purple:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(139,92,246,.3)}
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
    <a href="logs.php" class="nav-item">📋 لاگ فعالیت‌ها</a>
    <a href="settings.php" class="nav-item active">⚙️ تنظیمات</a>
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
    <div class="page-title">⚙️ تنظیمات سیستم</div>
  </div>
  <div class="content">
    <?php if ($message): ?><div class="alert alert-success">✅ <?= $message ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger">❌ <?= $error ?></div><?php endif; ?>

    <!-- Change Admin Password -->
    <div class="settings-section">
      <div class="section-header">
        <div class="section-icon">🔑</div>
        <div>
          <div class="section-title">تغییر رمز عبور ادمین</div>
          <div class="section-desc">رمز عبور حساب مدیریت خود را تغییر دهید</div>
        </div>
      </div>
      <div class="section-body">
        <form method="POST">
          <input type="hidden" name="action" value="change_admin_pass">
          <div class="form-row">
            <div class="form-group">
              <label>رمز عبور فعلی</label>
              <input type="password" name="current_pass" placeholder="••••••••" required>
            </div>
            <div></div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>رمز عبور جدید</label>
              <input type="password" name="new_pass" placeholder="••••••••" required minlength="6">
            </div>
            <div class="form-group">
              <label>تکرار رمز عبور جدید</label>
              <input type="password" name="confirm_pass" placeholder="••••••••" required minlength="6">
            </div>
          </div>
          <button type="submit" class="btn btn-primary">🔑 تغییر رمز ادمین</button>
        </form>
      </div>
    </div>

    <!-- Change Reseller Password -->
    <div class="settings-section">
      <div class="section-header">
        <div class="section-icon">👤</div>
        <div>
          <div class="section-title">تغییر رمز عبور ریسلر</div>
          <div class="section-desc">رمز عبور هر ریسلر را از اینجا تغییر دهید</div>
        </div>
      </div>
      <div class="section-body">
        <form method="POST">
          <input type="hidden" name="action" value="change_reseller_pass">
          <div class="form-group">
            <label>انتخاب ریسلر</label>
            <select name="reseller_id" required>
              <option value="">-- انتخاب کنید --</option>
              <?php foreach ($resellers as $r): ?>
              <option value="<?= $r['id'] ?>"><?= sanitize($r['username']) ?><?= $r['full_name'] ? ' - '.$r['full_name'] : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>رمز عبور جدید</label>
              <input type="password" name="new_pass" placeholder="••••••••" required minlength="6">
            </div>
            <div class="form-group">
              <label>تکرار رمز عبور</label>
              <input type="password" name="confirm_pass" placeholder="••••••••" required minlength="6">
            </div>
          </div>
          <button type="submit" class="btn btn-purple">👤 تغییر رمز ریسلر</button>
        </form>
      </div>
    </div>

    <!--  Settings -->
    <div class="settings-section">
      <div class="section-header">
        <div class="section-icon">🔌</div>
        <div>
          <div class="section-title">تنظیمات </div>
          <div class="section-desc">اطلاعات اتصال به سرور </div>
        </div>
      </div>
      <div class="section-body">
        <form method="POST">
          <input type="hidden" name="action" value="save_ibs_settings">
          <div class="form-group">
            <label>آدرس API سرور  <span style="font-size:11px;color:var(--muted);font-weight:400">(همان که برنامه واقعاً استفاده می‌کند)</span></label>
            <input type="text" name="ibs_api_url" value="<?= sanitize($ibsApiUrl) ?>" placeholder="http://ip/ibs-api/">
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>نام کاربری ادمین </label>
              <input type="text" name="ibs_user" value="<?= sanitize($ibsUser) ?>">
            </div>
            <div class="form-group">
              <label>رمز عبور ادمین </label>
              <input type="password" name="ibs_pass" placeholder="برای تغییر وارد کنید">
            </div>
          </div>
          <div class="form-group">
            <label>نام سایت</label>
            <input type="text" name="site_name" value="<?= sanitize($siteName) ?>">
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>قیمت ایجاد هر کاربر (تومان)</label>
              <input type="number" name="create_price" value="<?= $createPrice ?>">
            </div>
            <div class="form-group">
              <label>قیمت تمدید به ازای هر روز (تومان)</label>
              <input type="number" name="renew_price" value="<?= $renewPrice ?>">
            </div>
          </div>
          <button type="submit" class="btn btn-primary">💾 ذخیره تنظیمات</button>
        </form>
      </div>
    </div>

  <!-- لوگو -->
    <div class="settings-section">
      <div class="section-header">
        <div class="section-icon">🖼️</div>
        <div>
          <div class="section-title">لوگوی پنل</div>
          <div class="section-desc">لوگوی دلخواه خود را آپلود کنید</div>
        </div>
      </div>
      <div class="section-body">
        <?php if($siteLogo && file_exists(dirname(__DIR__).'/'.$siteLogo)): ?>
        <div style="margin-bottom:16px;padding:14px;background:rgba(0,0,0,.2);border-radius:10px;display:inline-block">
          <img src="../<?=sanitize($siteLogo)?>" alt="لوگو" style="max-height:80px;max-width:300px;object-fit:contain">
          <div style="font-size:11px;color:var(--muted);margin-top:6px">لوگوی فعلی</div>
        </div>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="action" value="upload_logo">
          <div class="form-group">
            <label>انتخاب فایل لوگو (PNG، JPG، SVG، WEBP — حداکثر ۲MB)</label>
            <input type="file" name="logo" accept="image/*" required style="padding:8px">
          </div>
          <button type="submit" class="btn btn-primary">📤 آپلود لوگو</button>
        </form>
      </div>
    </div>

  </div>
</main>
</body></html>

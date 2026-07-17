<?php
require_once '../includes/config.php';
require_once '../includes/telegram_api.php';
require_once '../includes/db_backup.php';
requireAdmin();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('درخواست نامعتبر است (احتمالاً صفحه قدیمی شده). لطفاً صفحه را رفرش کرده و دوباره امتحان کنید.');
}

$message = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_bot_settings') {
        $token = trim($_POST['bot_token'] ?? '');
        $secret = trim($_POST['webhook_secret'] ?? '');
        $proxy = trim($_POST['telegram_proxy'] ?? '');
        if ($token !== '') {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('telegram_bot_token', ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$token]);
        }
        if ($secret !== '') {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('telegram_webhook_secret', ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$secret]);
        }
        // برخلاف توکن/secret، پراکسی باید بشه با خالی گذاشتن هم پاک/غیرفعال بشه
        // (مثلاً موقع تعویض یا رفع اشکال پراکسی)
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('telegram_proxy', ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$proxy]);
        logActivity('admin', $_SESSION['admin_id'], 'update_telegram_settings', 'تنظیمات ربات تلگرام بروز شد');
        $message = 'تنظیمات ذخیره شد.';
    }

    if ($action === 'save_direct_isp') {
        $isp = sanitize($_POST['direct_isp_name'] ?? '');
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('direct_isp_name', ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$isp]);
        $message = 'ISP فروش مستقیم ذخیره شد.';
    }

    if ($action === 'save_texts') {
        $support = $_POST['support_contact_message'] ?? '';
        $card = $_POST['payment_card_info'] ?? '';
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('support_contact_message', ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$support]);
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('payment_card_info', ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$card]);
        $message = 'متن‌ها ذخیره شد.';
    }

    if ($action === 'set_webhook') {
        $secret = getSetting('telegram_webhook_secret', '');
        // اگر ادمین دستی secret تنظیم نکرده، بدون اون وبهوک بدون هیچ احراز هویتی
        // در معرض دید عمومی می‌مونه (هرکسی می‌تونه با POST جعلی به webhook.php
        // خودش رو جای تلگرام جا بزنه) - برای همین همیشه یک مقدار تصادفی قوی
        // خودکار می‌سازیم و ذخیره می‌کنیم تا این حالت هیچ‌وقت باز نمونه.
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('telegram_webhook_secret', ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$secret]);
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $base = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/telegram.php'));
        $base = $base === '/' || $base === '\\' ? '' : $base;
        $webhookUrl = $scheme . '://' . $host . $base . '/telegram/webhook.php';

        $res = tg_setWebhook($webhookUrl, $secret);
        if ($res['ok'] ?? false) {
            $message = 'Webhook با موفقیت روی آدرس زیر تنظیم شد: ' . $webhookUrl;
        } else {
            $error = 'خطا در تنظیم Webhook: ' . ($res['description'] ?? 'نامشخص');
        }
        logActivity('admin', $_SESSION['admin_id'], 'set_telegram_webhook', $webhookUrl);
    }

    if ($action === 'delete_webhook') {
        tg_deleteWebhook();
        $message = 'Webhook حذف شد.';
    }

    if ($action === 'save_my_chat_id') {
        $chatId = sanitize($_POST['my_chat_id'] ?? '');
        $pdo->prepare("UPDATE admins SET telegram_chat_id=? WHERE id=?")->execute([$chatId ?: null, $_SESSION['admin_id']]);
        logActivity('admin', $_SESSION['admin_id'], 'set_telegram_chat_id', 'ثبت Chat ID تلگرام ادمین');
        $message = 'Chat ID ذخیره شد. برای تست، دستور /stats را به ربات بفرستید.';
    }

    if ($action === 'export_db') {
        $dir = dirname(__DIR__) . '/uploads/backups/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . 'backup_' . date('Ymd_His') . '.sql';
        if (db_export_to_file($pdo, $file)) {
            logActivity('admin', $_SESSION['admin_id'], 'export_db', basename($file));
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . basename($file) . '"');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            @unlink($file);
            exit;
        }
        $error = 'ساخت فایل Export ناموفق بود.';
    }

    if ($action === 'import_db') {
        if (empty($_FILES['sql_file']['tmp_name']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'فایل .sql انتخاب نشده یا در آپلود خطا رخ داد.';
        } elseif (strtolower(pathinfo($_FILES['sql_file']['name'], PATHINFO_EXTENSION)) !== 'sql') {
            $error = 'فقط فایل با پسوند .sql پذیرفته می‌شود.';
        } else {
            $result = db_import_from_file($pdo, $_FILES['sql_file']['tmp_name']);
            if ($result['ok']) {
                logActivity('admin', $_SESSION['admin_id'], 'import_db', "اجرا شد: {$result['executed']}, خطا: {$result['failed']}");
                $message = "بازگردانی انجام شد. دستورهای موفق: {$result['executed']} | خطاها: {$result['failed']}";
                if (!empty($result['errors'])) {
                    $message .= '<br><small>' . implode('<br>', array_map('sanitize', array_slice($result['errors'], 0, 5))) . '</small>';
                }
            } else {
                $error = $result['error'] ?? 'خطای نامشخص در بازگردانی';
            }
        }
    }
}

$botToken = getSetting('telegram_bot_token', '');
$webhookSecret = getSetting('telegram_webhook_secret', '');
$telegramProxy = getSetting('telegram_proxy', '');
$directIsp = getSetting('direct_isp_name', '');
$supportMsg = getSetting('support_contact_message', '');
$cardInfo = getSetting('payment_card_info', '');
$myChatId = $pdo->prepare("SELECT telegram_chat_id FROM admins WHERE id=?");
$myChatId->execute([$_SESSION['admin_id']]);
$myChatId = $myChatId->fetchColumn();
$ibsIsps = [];
if ($botToken !== '') {
    require_once '../includes/ibsng_api.php';
    $ibsIsps = ibsng_getIsps();
}
$pendingOrders = $pdo->query("SELECT COUNT(*) FROM telegram_orders WHERE status='pending'")->fetchColumn();
$webhookInfo = $botToken !== '' ? tg_getWebhookInfo() : null;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>ربات تلگرام - پنل مدیریت</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
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
  .pending-badge{background:rgba(245,158,11,.2);color:var(--gold);padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;margin-right:auto}
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
  input,select,textarea{width:100%;padding:12px 14px;background:var(--bg);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:'Vazirmatn';font-size:14px;outline:none;transition:border .2s}
  textarea{resize:vertical;min-height:80px}
  input:focus,textarea:focus{border-color:var(--accent)}
  .btn{padding:12px 24px;border-radius:10px;font-family:'Vazirmatn';font-size:14px;font-weight:700;cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:8px}
  .btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff}
  .btn-primary:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(59,130,246,.3)}
  .btn-purple{background:linear-gradient(135deg,var(--purple),var(--accent));color:#fff}
  .btn-danger{background:rgba(239,68,68,.1);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
  .mono{font-family:monospace;font-size:12px;background:rgba(0,0,0,.25);padding:8px 12px;border-radius:8px;color:var(--text2);overflow-x:auto;white-space:pre-wrap;word-break:break-all}
  .badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700}
  .badge-success{background:rgba(16,185,129,.1);color:var(--success);border:1px solid rgba(16,185,129,.3)}
  .badge-danger{background:rgba(239,68,68,.1);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
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
    <a href="debts.php" class="nav-item">💰 مدیریت موجودی</a>
    <a href="payments.php" class="nav-item">🧾 فیش پرداخت</a>
    <div class="nav-section-label">فروش مستقیم تلگرام</div>
    <a href="direct_packages.php" class="nav-item">📦 بسته‌های فروش مستقیم</a>
    <a href="direct_orders.php" class="nav-item">🛒 سفارش‌های مستقیم <?php if($pendingOrders>0):?><span class="pending-badge"><?=$pendingOrders?></span><?php endif;?></a>
    <a href="telegram.php" class="nav-item active">🤖 ربات تلگرام</a>
    <div class="nav-section-label">سیستم</div>
    <a href="logs.php" class="nav-item">📋 لاگ‌ها</a>
    <a href="settings.php" class="nav-item">⚙️ تنظیمات</a>
  </nav>
  <div class="sidebar-footer">
    <div class="admin-info">
      <div class="admin-avatar">🛡️</div>
      <div><div class="admin-name"><?= sanitize($_SESSION['admin_username']) ?></div><div class="admin-role">مدیر اصلی</div></div>
    </div>
    <a href="logout.php" class="nav-item logout-btn">🚪 خروج</a>
  </div>
</aside>

<main class="main">
  <div class="topbar">
    <div class="page-title">🤖 ربات تلگرام</div>
  </div>
  <div class="content">
    <?php if ($message): ?><div class="alert alert-success">✅ <?= $message ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger">❌ <?= sanitize($error) ?></div><?php endif; ?>

    <div class="settings-section">
      <div class="section-header">
        <div class="section-icon">🔑</div>
        <div>
          <div class="section-title">توکن ربات</div>
          <div class="section-desc">از @BotFather در تلگرام یک ربات بسازید و توکن را اینجا وارد کنید</div>
        </div>
      </div>
      <div class="section-body">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
          <input type="hidden" name="action" value="save_bot_settings">
          <div class="form-group">
            <label>توکن ربات (<?= $botToken !== '' ? 'تنظیم شده' : 'تنظیم نشده' ?>)</label>
            <input type="text" name="bot_token" placeholder="123456:ABC-DEF..." value="<?= sanitize($botToken) ?>">
          </div>
          <div class="form-group">
            <label>Secret Token وبهوک <span style="font-size:11px;color:var(--muted);font-weight:400">(یک رشته تصادفی دلخواه - برای اطمینان از این‌که فقط تلگرام می‌تواند به webhook.php پیام بفرستد)</span></label>
            <input type="text" name="webhook_secret" placeholder="یک رشته تصادفی طولانی" value="<?= sanitize($webhookSecret) ?>">
          </div>
          <div class="form-group">
            <label>پراکسی برای اتصال به تلگرام <span style="font-size:11px;color:var(--muted);font-weight:400">(اگه سرور مستقیم به api.telegram.org وصل نمی‌شه - خطای Connection timed out - یک پراکسی خارج از ایران اینجا بدید. فرمت: socks5://user:pass@host:port یا http://user:pass@host:port. برای غیرفعال کردن، خالی بذارید و ذخیره کنید)</span></label>
            <input type="text" name="telegram_proxy" placeholder="socks5://user:pass@1.2.3.4:1080" value="<?= sanitize($telegramProxy) ?>">
          </div>
          <button type="submit" class="btn btn-primary">💾 ذخیره</button>
        </form>

        <?php if ($botToken !== ''): ?>
        <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)">
          <p style="font-size:13px;color:var(--text2);margin-bottom:12px">
            وضعیت فعلی Webhook:
            <?php if (!empty($webhookInfo['result']['url'])): ?>
              <span class="badge badge-success">فعال</span>
              <div class="mono" style="margin-top:8px"><?= sanitize($webhookInfo['result']['url']) ?></div>
              <?php if (!empty($webhookInfo['result']['last_error_message'])): ?>
                <div style="margin-top:8px;color:var(--danger);font-size:12px">آخرین خطا: <?= sanitize($webhookInfo['result']['last_error_message']) ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span class="badge badge-danger">تنظیم نشده</span>
            <?php endif; ?>
          </p>
          <form method="POST" style="display:inline-block;margin-left:8px"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
            <input type="hidden" name="action" value="set_webhook">
            <button type="submit" class="btn btn-primary">🔗 تنظیم Webhook روی این آدرس</button>
          </form>
          <form method="POST" style="display:inline-block"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
            <input type="hidden" name="action" value="delete_webhook">
            <button type="submit" class="btn btn-danger">حذف Webhook</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="settings-section">
      <div class="section-header">
        <div class="section-icon">🛡️</div>
        <div>
          <div class="section-title">دریافت اعلان و کنترل از تلگرام</div>
          <div class="section-desc">با ثبت Chat ID خودتان، سفارش‌های جدید با دکمه تأیید/رد برای شما ارسال می‌شوند و دستورهای /export و /import و /stats را می‌توانید مستقیم به ربات بفرستید</div>
        </div>
      </div>
      <div class="section-body">
        <p style="font-size:13px;color:var(--text2);margin-bottom:16px">برای گرفتن Chat ID خودتان: ابتدا توکن بالا را ذخیره و Webhook را تنظیم کنید، بعد به ربات پیام <code>/start</code> بدهید و آیدی چت خودتان را از ربات‌هایی مثل @userinfobot بگیرید.</p>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
          <input type="hidden" name="action" value="save_my_chat_id">
          <div class="form-group">
            <label>Chat ID فعلی: <?= sanitize((string)($myChatId ?: 'ثبت نشده')) ?></label>
            <input type="text" name="my_chat_id" placeholder="مثلاً 123456789" value="<?= sanitize((string)($myChatId ?: '')) ?>">
          </div>
          <button type="submit" class="btn btn-primary">💾 ذخیره</button>
        </form>
      </div>
    </div>

    <div class="settings-section">
      <div class="section-header">
        <div class="section-icon">🌐</div>
        <div>
          <div class="section-title">ISP فروش مستقیم</div>
          <div class="section-desc">کاربرانی که مستقیم از تلگرام (بدون ریسلر) خرید می‌کنند به این ISP بایند می‌شوند</div>
        </div>
      </div>
      <div class="section-body">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
          <input type="hidden" name="action" value="save_direct_isp">
          <div class="form-group">
            <label>ISP</label>
            <?php if ($ibsIsps): ?>
            <select name="direct_isp_name">
              <option value="">— انتخاب کنید —</option>
              <?php foreach ($ibsIsps as $isp): ?>
              <option value="<?= sanitize($isp) ?>" <?= $isp === $directIsp ? 'selected' : '' ?>><?= sanitize($isp) ?></option>
              <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="text" name="direct_isp_name" value="<?= sanitize($directIsp) ?>" placeholder="نام ISP">
            <?php endif; ?>
          </div>
          <button type="submit" class="btn btn-primary">💾 ذخیره</button>
        </form>
      </div>
    </div>

    <div class="settings-section">
      <div class="section-header">
        <div class="section-icon">💬</div>
        <div>
          <div class="section-title">متن‌های ربات</div>
          <div class="section-desc">پیام پشتیبانی و اطلاعات کارت برای پرداخت مشتریان مستقیم</div>
        </div>
      </div>
      <div class="section-body">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
          <input type="hidden" name="action" value="save_texts">
          <div class="form-group">
            <label>پیام پشتیبانی</label>
            <textarea name="support_contact_message"><?= sanitize($supportMsg) ?></textarea>
          </div>
          <div class="form-group">
            <label>اطلاعات کارت پرداخت</label>
            <textarea name="payment_card_info"><?= sanitize($cardInfo) ?></textarea>
          </div>
          <button type="submit" class="btn btn-primary">💾 ذخیره</button>
        </form>
      </div>
    </div>

    <div class="settings-section">
      <div class="section-header">
        <div class="section-icon">💾</div>
        <div>
          <div class="section-title">Export / Import دیتابیس سیستم</div>
          <div class="section-desc">فقط دیتابیس اختصاصی همین پنل (ریسلرها، سفارش‌ها، تراکنش‌ها) - نه دیتابیس </div>
        </div>
      </div>
      <div class="section-body">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
          <input type="hidden" name="action" value="export_db">
          <button type="submit" class="btn btn-primary">📥 دانلود فایل Export (.sql)</button>
        </form>
        <form method="POST" enctype="multipart/form-data" style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
          <input type="hidden" name="action" value="import_db">
          <div class="form-group">
            <label>فایل .sql برای بازگردانی <span style="font-size:11px;color:var(--danger);font-weight:400">(جدول‌های داخل فایل کامل جایگزین می‌شوند)</span></label>
            <input type="file" name="sql_file" accept=".sql" required>
          </div>
          <button type="submit" class="btn btn-purple">📤 Import</button>
        </form>
        <p style="font-size:12px;color:var(--muted);margin-top:16px">از داخل خود ربات تلگرام هم می‌توانید با دستور <code>/export</code> فایل را دریافت، یا با ارسال فایل .sql با کپشن <code>/import</code> آن را بازگردانی کنید (برای فایل‌های زیر ۲۰ مگابایت).</p>
      </div>
    </div>

  </div>
</main>
</body>
</html>

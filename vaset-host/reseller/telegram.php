<?php
require_once '../includes/config.php';
require_once '../includes/telegram_api.php';
require_once '../includes/reseller_bot.php';
requireReseller();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('درخواست نامعتبر است (احتمالاً صفحه قدیمی شده). لطفاً صفحه را رفرش کرده و دوباره امتحان کنید.');
}

$rid = (int)$_SESSION['reseller_id'];
$reseller = $pdo->prepare("SELECT * FROM resellers WHERE id=?");
$reseller->execute([$rid]);
$reseller = $reseller->fetch();

$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_bot_token') {
        $token = trim($_POST['bot_token'] ?? '');
        if ($token === '') {
            $error = 'توکن بات را وارد کنید';
        } else {
            rb_saveBotToken($rid, $token);
            logActivity('reseller', $rid, 'save_bot_token', 'توکن بات تلگرام اختصاصی ذخیره شد');
            $success = 'توکن ذخیره شد.';
        }
    }

    if ($action === 'save_texts') {
        rb_saveTexts($rid, $_POST['payment_card_info'] ?? '', $_POST['support_message'] ?? '');
        $success = 'متن‌ها ذخیره شد.';
    }

    if ($action === 'save_branding') {
        rb_saveBranding($rid, trim($_POST['shop_name'] ?? ''), trim($_POST['welcome_message'] ?? ''));
        logActivity('reseller', $rid, 'save_bot_branding', 'برندینگ بات تلگرام اختصاصی به‌روز شد');
        $success = 'برندینگ بات ذخیره شد.';
    }

    if ($action === 'save_username_prefix') {
        rb_saveUsernamePrefix($rid, $_POST['username_prefix'] ?? '');
        logActivity('reseller', $rid, 'save_username_prefix', 'پیشوند یوزرنیم خودکار به‌روز شد');
        $success = 'پیشوند یوزرنیم ذخیره شد.';
    }

    if ($action === 'toggle_enabled') {
        $bot = rb_getBot($rid);
        rb_setEnabled($rid, empty($bot['enabled']));
        $success = empty($bot['enabled']) ? 'بات فعال شد.' : 'بات غیرفعال شد.';
    }

    if ($action === 'set_webhook') {
        $bot = rb_getBot($rid);
        if (!$bot) {
            $error = 'ابتدا توکن بات را ذخیره کنید.';
        } else {
            tg_setActiveBotToken($bot['bot_token']);
            $webhookUrl = rb_computeWebhookUrl($rid);
            $res = tg_setWebhook($webhookUrl, $bot['webhook_secret']);
            if ($res['ok'] ?? false) {
                $success = 'Webhook با موفقیت روی آدرس زیر تنظیم شد: ' . $webhookUrl;
            } else {
                $error = 'خطا در تنظیم Webhook: ' . ($res['description'] ?? 'نامشخص') . ' - مطمئن شوید توکن بات درست است.';
            }
            logActivity('reseller', $rid, 'set_telegram_webhook', $webhookUrl);
        }
    }

    if ($action === 'delete_webhook') {
        $bot = rb_getBot($rid);
        if ($bot) {
            tg_setActiveBotToken($bot['bot_token']);
            tg_deleteWebhook();
            $success = 'Webhook حذف شد.';
        }
    }

    if ($action === 'save_chat_id') {
        $chatId = sanitize($_POST['my_chat_id'] ?? '');
        $pdo->prepare("UPDATE resellers SET telegram_chat_id=? WHERE id=?")->execute([$chatId ?: null, $rid]);
        logActivity('reseller', $rid, 'set_telegram_chat_id', 'ثبت Chat ID تلگرام ریسلر');
        $success = 'Chat ID ذخیره شد. برای تست، دستور /start را به بات خودتان بفرستید.';
    }

    // برای پیدا کردن اینکه چرا کارت تأیید سفارش به Chat ID نمی‌رسه: مستقیم
    // یک پیام تست با همون بات/همون Chat ID می‌فرستیم و پاسخ خامِ خودِ تلگرام
    // رو نشون می‌دیم - اگه Chat ID اشتباه باشه یا هنوز به بات /start نزده
    // باشید، تلگرام همینجا دقیقاً می‌گه چرا (مثلاً "chat not found").
    if ($action === 'send_test_message') {
        $bot = rb_getBot($rid);
        $rInfo = $pdo->prepare("SELECT telegram_chat_id FROM resellers WHERE id=?");
        $rInfo->execute([$rid]);
        $myChatId = $rInfo->fetchColumn();
        if (!$bot) {
            $error = 'ابتدا توکن بات را ذخیره کنید.';
        } elseif (!$myChatId) {
            $error = 'ابتدا Chat ID خودتان را ذخیره کنید.';
        } else {
            tg_setActiveBotToken($bot['bot_token']);
            $res = tg_sendMessage($myChatId, '✅ این یک پیام تستی است. اگه این رو می‌بینید، اتصال بات به Chat ID شما درست کار می‌کنه.');
            if ($res['ok'] ?? false) {
                $success = 'پیام تست با موفقیت ارسال شد ✅ (توی تلگرام چک کنید)';
            } else {
                $error = 'پیام تست ارسال نشد: ' . ($res['description'] ?? json_encode($res, JSON_UNESCAPED_UNICODE)) . ' — یعنی همین دلیل باعث نرسیدن کارت تأیید سفارش هم می‌شه. معمولاً یعنی یا Chat ID اشتباهه یا هنوز به بات /start نزدید.';
            }
        }
    }
}

$bot = rb_getBot($rid);
$webhookInfo = null;
if ($bot) {
    tg_setActiveBotToken($bot['bot_token']);
    $webhookInfo = tg_getWebhookInfo();
}
$grpStmt = $pdo->prepare("SELECT COUNT(*) FROM reseller_groups WHERE reseller_id=?");
$grpStmt->execute([$rid]);
$groupCount = (int)$grpStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>بات تلگرام اختصاصی - پنل ریسلر</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{--bg:#08100a;--sidebar:#0c1810;--card:#131f17;--border:#1e3025;--accent:#10b981;--accent2:#06b6d4;--purple:#8b5cf6;--gold:#f59e0b;--text:#e2e8f0;--text2:#94a3b8;--muted:#475569;--danger:#ef4444;--success:#10b981;--sidebar-w:260px}
  html,body{overflow-x:hidden}
  body{font-family:'Vazirmatn',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
  .sidebar{width:var(--sidebar-w);background:var(--sidebar);border-left:1px solid var(--border);position:fixed;right:0;top:0;bottom:0;display:flex;flex-direction:column;z-index:100}
  .overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
  .hamburger{display:none;position:fixed;top:14px;right:14px;z-index:101;background:var(--sidebar);border:1px solid var(--border);border-radius:9px;padding:8px 10px;cursor:pointer;color:var(--text);font-size:18px}
  @media(max-width:768px){.sidebar{transform:translateX(100%);transition:.3s}.sidebar.open{transform:none}.overlay.open{display:block}.hamburger{display:block}.main{margin-right:0!important}.content{padding:20px 16px!important}.topbar{padding:14px 16px!important;padding-right:60px!important}}
  .sidebar-logo{padding:28px 24px;border-bottom:1px solid var(--border)}
  .logo-text{font-size:20px;font-weight:900;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
  .logo-badge{font-size:10px;color:var(--muted);-webkit-text-fill-color:var(--muted)}
  .sidebar-nav{flex:1;padding:16px 12px;overflow-y:auto}
  .nav-section-label{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);padding:8px 12px 4px;font-weight:600}
  .nav-item{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:10px;color:var(--text2);text-decoration:none;font-size:14px;font-weight:500;transition:all .2s;margin-bottom:2px}
  .nav-item:hover{background:rgba(16,185,129,.08);color:var(--text)}
  .nav-item.active{background:rgba(16,185,129,.15);color:var(--accent)}
  .pending-badge{background:rgba(245,158,11,.2);color:var(--gold);padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;margin-right:auto}
  .sidebar-footer{padding:16px 12px;border-top:1px solid var(--border)}
  .reseller-info{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;background:rgba(255,255,255,.03);margin-bottom:4px}
  .reseller-avatar{width:36px;height:36px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:10px;display:flex;align-items:center;justify-content:center}
  .reseller-name{font-size:13px;font-weight:600}
  .reseller-role{font-size:11px;color:var(--muted)}
  .logout-btn{color:var(--danger)!important}
  .main{margin-right:var(--sidebar-w);flex:1}
  .topbar{background:var(--sidebar);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
  .page-title{font-size:20px;font-weight:700}
  .content{padding:32px;max-width:900px}
  .alert{padding:14px 18px;border-radius:10px;margin-bottom:20px;font-size:14px;word-break:break-all}
  .alert-success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:#6ee7b7}
  .alert-danger{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
  .card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:24px}
  .card-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
  .card-title{font-size:16px;font-weight:700}
  .card-desc{font-size:13px;color:var(--muted);margin-top:2px}
  .card-body{padding:28px 24px}
  .form-group{margin-bottom:20px}
  label{display:block;font-size:13px;font-weight:500;color:var(--text2);margin-bottom:8px}
  input,textarea{width:100%;padding:12px 14px;background:var(--bg);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:'Vazirmatn';font-size:14px;outline:none;transition:border .2s}
  textarea{resize:vertical;min-height:80px}
  input:focus,textarea:focus{border-color:var(--accent)}
  .btn{padding:12px 24px;border-radius:10px;font-family:'Vazirmatn';font-size:14px;font-weight:700;cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:8px}
  .btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff}
  .btn-primary:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(16,185,129,.3)}
  .btn-danger{background:rgba(239,68,68,.1);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
  .btn-warn{background:rgba(245,158,11,.1);color:var(--gold);border:1px solid rgba(245,158,11,.3)}
  .mono{font-family:monospace;font-size:12px;background:rgba(0,0,0,.25);padding:8px 12px;border-radius:8px;color:var(--text2);overflow-x:auto;white-space:pre-wrap;word-break:break-all}
  .badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700}
  .badge-success{background:rgba(16,185,129,.1);color:var(--success);border:1px solid rgba(16,185,129,.3)}
  .badge-danger{background:rgba(239,68,68,.1);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
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
    <a href="direct_orders.php" class="nav-item">🛒 سفارش‌های مستقیم</a>
    <a href="telegram.php" class="nav-item active">🤖 بات تلگرام من</a>
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
    <div class="page-title">🤖 بات تلگرام اختصاصی من</div>
  </div>
  <div class="content">
    <?php if ($success): ?><div class="alert alert-success">✅ <?= sanitize($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger">❌ <?= sanitize($error) ?></div><?php endif; ?>

    <div class="card">
      <div class="card-header">
        <div style="font-size:20px">💡</div>
        <div>
          <div class="card-title">این بات چیست؟</div>
          <div class="card-desc">با ساختن یک بات مخصوص خودتان در تلگرام، مشتری‌های شما می‌توانند مستقیم از تلگرام سرویس بخرند یا تمدید کنند - قیمت‌ها همان قیمت‌هایی است که برای گروه‌های خودتان تنظیم کرده‌اید (<?=$groupCount?> گروه فعال).</div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div style="font-size:20px">🔑</div>
        <div>
          <div class="card-title">توکن بات</div>
          <div class="card-desc">در تلگرام به <b>@BotFather</b> پیام دهید، دستور <code>/newbot</code> را بزنید، یک نام برای بات انتخاب کنید و توکنی که می‌دهد را اینجا وارد کنید.</div>
        </div>
      </div>
      <div class="card-body">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
          <input type="hidden" name="action" value="save_bot_token">
          <div class="form-group">
            <label>توکن بات (<?= $bot ? 'تنظیم شده' : 'تنظیم نشده' ?>)</label>
            <input type="text" name="bot_token" placeholder="123456:ABC-DEF..." value="<?= sanitize($bot['bot_token'] ?? '') ?>">
          </div>
          <button type="submit" class="btn btn-primary">💾 ذخیره</button>
        </form>

        <?php if ($bot): ?>
        <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)">
          <p style="font-size:13px;color:var(--text2);margin-bottom:12px">
            وضعیت بات:
            <span class="badge <?= $bot['enabled'] ? 'badge-success' : 'badge-danger' ?>"><?= $bot['enabled'] ? 'فعال' : 'غیرفعال' ?></span>
          </p>
          <p style="font-size:13px;color:var(--text2);margin-bottom:12px">
            وضعیت Webhook:
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
            <button type="submit" class="btn btn-primary">🔗 تنظیم Webhook</button>
          </form>
          <form method="POST" style="display:inline-block;margin-left:8px"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
            <input type="hidden" name="action" value="delete_webhook">
            <button type="submit" class="btn btn-danger">حذف Webhook</button>
          </form>
          <form method="POST" style="display:inline-block"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
            <input type="hidden" name="action" value="toggle_enabled">
            <button type="submit" class="btn btn-warn"><?= $bot['enabled'] ? '⏸ غیرفعال کردن بات' : '▶️ فعال کردن بات' ?></button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($bot): ?>
    <div class="card">
      <div class="card-header">
        <div style="font-size:20px">🔢</div>
        <div>
          <div class="card-title">یوزرنیم خودکار مشتری‌ها</div>
          <div class="card-desc">اگه یک پیشوند اینجا بذارید (مثلاً <code>ars</code>)، دیگه مشتری خودش یوزرنیم انتخاب نمی‌کنه - بات به‌ترتیب می‌سازه: <code><?=sanitize($bot['username_prefix'] ?: 'ars')?>1</code>، <code><?=sanitize($bot['username_prefix'] ?: 'ars')?>2</code> و... خالی بذارید تا مثل قبل خودِ مشتری یوزرنیم دلخواهش رو تایپ کنه.</div>
        </div>
      </div>
      <div class="card-body">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
          <input type="hidden" name="action" value="save_username_prefix">
          <div class="form-group">
            <label>پیشوند یوزرنیم (فقط حروف/عدد انگلیسی)</label>
            <input type="text" name="username_prefix" placeholder="مثلاً: ars" value="<?= sanitize($bot['username_prefix'] ?? '') ?>">
          </div>
          <button type="submit" class="btn btn-primary">💾 ذخیره</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div style="font-size:20px">🎨</div>
        <div>
          <div class="card-title">برندینگ فروشگاه</div>
          <div class="card-desc">اسم مغازه/کسب‌وکار خودتان را وارد کنید - همه‌جای بات (پیام خوش‌آمد و غیره) با همین اسم نشون داده می‌شه. پیام خوش‌آمد اختیاریه؛ اگه خالی بذارید، یک متن پیش‌فرض خوشگل با همین اسم فروشگاه ساخته می‌شه.</div>
        </div>
      </div>
      <div class="card-body">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
          <input type="hidden" name="action" value="save_branding">
          <div class="form-group">
            <label>اسم فروشگاه</label>
            <input type="text" name="shop_name" placeholder="مثلاً: اینترنت پرسرعت آرشیا" value="<?= sanitize($bot['shop_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>پیام خوش‌آمد اختصاصی (اختیاری)</label>
            <textarea name="welcome_message" placeholder="مثلاً: با ما بهترین اینترنت رو با بهترین قیمت تجربه کن 🚀"><?= sanitize($bot['welcome_message'] ?? '') ?></textarea>
          </div>
          <button type="submit" class="btn btn-primary">💾 ذخیره</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div style="font-size:20px">🛡️</div>
        <div>
          <div class="card-title">دریافت اعلان سفارش‌های جدید در تلگرام</div>
          <div class="card-desc">با ثبت Chat ID خودتان، هر وقت مشتری‌ای رسید پرداخت بفرستد، همان‌جا در تلگرام با دکمه‌ی تأیید/رد برای شما ارسال می‌شود. بدون این هم می‌توانید از صفحه‌ی «سفارش‌های مستقیم» بررسی کنید.</div>
        </div>
      </div>
      <div class="card-body">
        <p style="font-size:13px;color:var(--text2);margin-bottom:16px">برای گرفتن Chat ID خودتان: به بات خودتان پیام <code>/start</code> بدهید و آیدی چت خودتان را از ربات‌هایی مثل @userinfobot بگیرید.</p>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
          <input type="hidden" name="action" value="save_chat_id">
          <div class="form-group">
            <label>Chat ID فعلی: <?= sanitize((string)($reseller['telegram_chat_id'] ?: 'ثبت نشده')) ?></label>
            <input type="text" name="my_chat_id" placeholder="مثلاً 123456789" value="<?= sanitize((string)($reseller['telegram_chat_id'] ?: '')) ?>">
          </div>
          <button type="submit" class="btn btn-primary">💾 ذخیره</button>
        </form>
        <form method="POST" style="margin-top:12px">
          <input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
          <input type="hidden" name="action" value="send_test_message">
          <button type="submit" class="btn btn-warn">🧪 ارسال پیام تست</button>
          <p style="font-size:12px;color:var(--text2);margin-top:8px">اگه پیام تست نرسید، دقیقاً همون دلیلیه که کارت تأیید سفارش هم نمی‌رسه. اول توکن و Chat ID رو ذخیره کنید و به بات <code>/start</code> بزنید، بعد این دکمه رو بزنید.</p>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div style="font-size:20px">💬</div>
        <div>
          <div class="card-title">متن‌های بات</div>
          <div class="card-desc">پیام پشتیبانی و اطلاعات کارت برای پرداخت مشتریان شما</div>
        </div>
      </div>
      <div class="card-body">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?=generateCsrf()?>">
          <input type="hidden" name="action" value="save_texts">
          <div class="form-group">
            <label>پیام پشتیبانی</label>
            <textarea name="support_message"><?= sanitize($bot['support_message'] ?? '') ?></textarea>
          </div>
          <div class="form-group">
            <label>اطلاعات کارت پرداخت</label>
            <textarea name="payment_card_info"><?= sanitize($bot['payment_card_info'] ?? '') ?></textarea>
          </div>
          <button type="submit" class="btn btn-primary">💾 ذخیره</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </div>
</main>
<script>
function toggleSB(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('open')}
function closeSB(){document.getElementById('sidebar').classList.remove('open');document.getElementById('overlay').classList.remove('open')}
</script>
</body>
</html>

<?php

declare(strict_types=1);

/**
 * Temporary web-based .env creator for hosts where File Manager's "New File" flow
 * has been unreliable during first-time setup. Writes vaset-host/.env directly via
 * PHP (file_put_contents), sidestepping the file manager entirely. Gated by a
 * hardcoded setup key (can't use INSTALL_TOKEN from .env - that file doesn't exist
 * yet, which is the whole problem this page solves). Delete this file, along with
 * install.php and debug_env.php, once setup is confirmed working.
 */

const SETUP_KEY = 'db0bc9594b8b46707b1bde566c993b2ea835fea60983bb5f';

header('Content-Type: text/html; charset=utf-8');

$providedKey = $_GET['key'] ?? $_POST['key'] ?? '';
if (!hash_equals(SETUP_KEY, (string) $providedKey)) {
    http_response_code(403);
    echo 'دسترسی غیرمجاز.';
    exit;
}

$envPath = dirname(__DIR__) . '/.env';
$message = null;
$success = false;

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $force = ($_POST['force'] ?? '') === '1';

    if (is_file($envPath) && !$force) {
        $message = 'فایل .env از قبل وجود دارد. اگر می‌خواهید بازنویسی شود، تیک «بازنویسی فایل موجود» را بزنید و دوباره ارسال کنید.';
    } else {
        $dbHost = trim((string) ($_POST['db_host'] ?? '127.0.0.1'));
        $dbName = trim((string) ($_POST['db_name'] ?? ''));
        $dbUser = trim((string) ($_POST['db_user'] ?? ''));
        $dbPass = (string) ($_POST['db_pass'] ?? '');
        $installToken = trim((string) ($_POST['install_token'] ?? ''));

        if ($dbName === '' || $dbUser === '' || $installToken === '') {
            $message = 'نام دیتابیس، یوزر دیتابیس و INSTALL_TOKEN الزامی است.';
        } else {
            $content = <<<ENV
DB_HOST={$dbHost}
DB_NAME={$dbName}
DB_USER={$dbUser}
DB_PASS={$dbPass}

INSTALL_TOKEN={$installToken}

IBSNG_AGENT_URL=http://127.0.0.1:9091
IBSNG_AGENT_API_KEY=CHANGE_ME_LONG_RANDOM_STRING

MYSQLDUMP_PATH=mysqldump
MYSQL_CLI_PATH=mysql

TELEGRAM_BOT_TOKEN=123456:ABC-DEF...
TELEGRAM_WEBHOOK_SECRET=CHANGE_ME_RANDOM_STRING
SUPPORT_CONTACT_MESSAGE="برای پشتیبانی به آیدی @your_support_id در تلگرام پیام دهید."
PAYMENT_CARD_INFO="شماره کارت: 6037-XXXX-XXXX-XXXX به نام ..."

IBSNG_DIRECT_ISP=default

IBSNG_DEFAULT_CREDIT1=100
IBSNG_DEFAULT_CREDIT2=0
IBSNG_RENEW_CREDIT1=100

ENV;

            $written = file_put_contents($envPath, $content);
            if ($written === false) {
                $message = 'نوشتن فایل .env ناموفق بود (احتمالاً پرمیشن پوشه vaset-host اجازه‌ی نوشتن نمی‌ده).';
            } else {
                @chmod($envPath, 0644);
                $success = true;
                $message = '.env با موفقیت ساخته شد (' . $written . ' بایت). حالا می‌تونی بری سراغ install.php برای ساخت اولین ادمین.';
            }
        }
    }
}

$existingSize = is_file($envPath) ? filesize($envPath) : null;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ساخت .env</title>
<style>
body{font-family:Tahoma,sans-serif;background:#0f1420;color:#e8ecf6;display:flex;justify-content:center;padding:40px 16px}
.box{max-width:480px;width:100%;background:#161d2e;border:1px solid #2a3550;border-radius:12px;padding:24px}
label{display:block;margin:14px 0 4px;color:#9aa5c0;font-size:13px}
input[type=text],input[type=password]{width:100%;padding:9px 10px;border-radius:6px;border:1px solid #2a3550;background:#1d2740;color:#e8ecf6;box-sizing:border-box}
button{margin-top:18px;padding:10px 16px;border:none;border-radius:6px;background:#4f7cff;color:#fff;cursor:pointer;width:100%}
.msg{padding:10px 14px;border-radius:8px;margin-bottom:16px}
.ok{background:rgba(47,191,113,.15);color:#2fbf71}
.err{background:rgba(239,83,80,.15);color:#ef5350}
</style>
</head>
<body>
<div class="box">
<h2>ساخت فایل .env</h2>
<p style="color:#9aa5c0;font-size:13px">وضعیت فعلی: <?= is_file($envPath) ? ('فایل موجود است، ' . $existingSize . ' بایت') : 'فایل وجود ندارد' ?></p>
<?php if ($message): ?><div class="msg <?= $success ? 'ok' : 'err' ?>"><?= e($message) ?></div><?php endif; ?>
<?php if (!$success): ?>
<form method="post">
<input type="hidden" name="key" value="<?= e((string) $providedKey) ?>">
<label>DB_HOST</label>
<input type="text" name="db_host" value="127.0.0.1">
<label>DB_NAME</label>
<input type="text" name="db_name" value="csdfacij_Panel-VIP">
<label>DB_USER</label>
<input type="text" name="db_user" value="csdfacij_Panel-VIP">
<label>DB_PASS (پسورد جدیدی که ساختی)</label>
<input type="password" name="db_pass">
<label>INSTALL_TOKEN</label>
<input type="text" name="install_token" value="4b098fa15d4864bf5ffce32307ed07850575e1918563d626">
<label><input type="checkbox" name="force" value="1"> بازنویسی فایل موجود (اگر از قبل .env دارید)</label>
<button type="submit">ساخت .env</button>
</form>
<?php endif; ?>
</div>
</body>
</html>

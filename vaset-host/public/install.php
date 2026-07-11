<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Config;
use App\Core\Csrf;
use App\Core\View;
use App\Domain\Repositories\AdminRepository;

/**
 * One-time, no-terminal bootstrap for creating the first admin account - for cPanel
 * setups without Terminal access (bin/create_admin.php is the CLI equivalent when a
 * shell is available). Gated by INSTALL_TOKEN in .env so it can't be triggered by
 * anyone else; delete this file via File Manager right after you use it.
 */
$expectedToken = Config::get('INSTALL_TOKEN', '');
$providedToken = $_GET['token'] ?? $_POST['token'] ?? '';

if ($expectedToken === '' || !hash_equals($expectedToken, (string) $providedToken)) {
    http_response_code(403);
    echo 'دسترسی غیرمجاز. مقدار INSTALL_TOKEN را در .env تنظیم کنید و همان مقدار را در آدرس به‌صورت ?token=... بدهید.';
    exit;
}

$message = null;
$success = false;
$repo = new AdminRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $name = trim((string) ($_POST['name'] ?? '')) ?: $username;

    if ($username === '' || strlen($password) < 8) {
        $message = 'نام کاربری و رمز عبور (حداقل ۸ کاراکتر) الزامی است.';
    } elseif ($repo->findByUsername($username) !== null) {
        $message = 'این نام کاربری از قبل وجود دارد. اگر می‌خواهید دوباره وارد شوید، از /admin/login.php استفاده کنید.';
    } else {
        $repo->create($username, $password, $name);
        $message = 'ادمین با موفقیت ساخته شد. حالا از /admin/login.php وارد شوید و بلافاصله همین فایل (public/install.php) را از File Manager حذف کنید.';
        $success = true;
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>نصب اولیه - ساخت ادمین</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="guest-body">
<div class="guest-box">
  <h1>ساخت اولین ادمین</h1>
  <?php if ($message): ?>
    <div class="alert <?= $success ? 'alert-success' : 'alert-error' ?>"><?= View::e($message) ?></div>
  <?php endif; ?>
  <?php if (!$success): ?>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="token" value="<?= View::e((string) $providedToken) ?>">
      <label>نام کاربری</label>
      <input type="text" name="username" required autofocus>
      <label>رمز عبور (حداقل ۸ کاراکتر)</label>
      <input type="password" name="password" required minlength="8">
      <label>نام کامل</label>
      <input type="text" name="name">
      <button class="btn mt" type="submit" style="width:100%">ساخت ادمین</button>
    </form>
  <?php endif; ?>
  <p class="muted mt">این صفحه فقط برای اولین راه‌اندازی است؛ بعد از ساخت ادمین حتماً از File Manager حذفش کنید.</p>
</div>
</body>
</html>

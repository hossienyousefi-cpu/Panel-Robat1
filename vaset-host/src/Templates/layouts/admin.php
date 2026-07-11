<?php
/** @var string $content */
/** @var string $pageTitle */
use App\Core\Session;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= App\Core\View::e($pageTitle ?? 'پنل ادمین') ?> | پنل ادمین</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <div class="brand">پنل ادمین</div>
    <nav>
      <a href="/admin/dashboard.php">داشبورد</a>
      <a href="/admin/resellers.php">Resellerها</a>
      <a href="/admin/receipts.php">تأیید فیش‌ها</a>
      <a href="/admin/users.php">یوزرهای سیستم</a>
      <a href="/admin/catalog.php">قیمت‌گذاری ربات تلگرام</a>
      <a href="/admin/settings.php">تنظیمات</a>
      <a href="/admin/logout.php" class="danger">خروج</a>
    </nav>
  </aside>
  <main class="content">
    <?php $success = Session::flash('success'); $error = Session::flash('error'); ?>
    <?php if ($success): ?><div class="alert alert-success"><?= App\Core\View::e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= App\Core\View::e($error) ?></div><?php endif; ?>
    <?= $content ?>
  </main>
</div>
</body>
</html>

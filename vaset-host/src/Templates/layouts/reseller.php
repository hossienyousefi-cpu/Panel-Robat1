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
<title><?= App\Core\View::e($pageTitle ?? 'پنل نمایندگی') ?> | پنل نمایندگی</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <div class="brand">پنل نمایندگی</div>
    <nav>
      <a href="/reseller/dashboard.php">داشبورد</a>
      <a href="/reseller/users.php">یوزرها</a>
      <a href="/reseller/receipts.php">شارژ کیف‌پول</a>
      <a href="/reseller/account.php">حساب من</a>
      <a href="/reseller/logout.php" class="danger">خروج</a>
    </nav>
  </aside>
  <main class="content">
    <?php $success = Session::flash('success'); $error = Session::flash('error'); ?>
    <?php if ($success): ?><div class="alert alert-success"><?= nl2br(App\Core\View::e($success)) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= App\Core\View::e($error) ?></div><?php endif; ?>
    <?= $content ?>
  </main>
</div>
</body>
</html>

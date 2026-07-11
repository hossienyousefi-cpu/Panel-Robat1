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
<title><?= App\Core\View::e($pageTitle ?? 'ورود') ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="guest-body">
<div class="guest-box">
  <?php $error = Session::flash('error'); ?>
  <?php if ($error): ?><div class="alert alert-error"><?= App\Core\View::e($error) ?></div><?php endif; ?>
  <?= $content ?>
</div>
</body>
</html>

<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;

Auth::logoutAdmin();
header('Location: /admin/login.php');

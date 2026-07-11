<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;

Auth::logoutReseller();
header('Location: /reseller/login.php');

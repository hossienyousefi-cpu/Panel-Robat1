<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;

if (Auth::currentReseller() !== null) {
    header('Location: /reseller/dashboard.php');
    exit;
}

if (Request::isPost()) {
    Csrf::requireValid();
    $username = Request::postString('username');
    $password = Request::postString('password');

    if (Auth::attemptReseller($username, $password)) {
        header('Location: /reseller/dashboard.php');
        exit;
    }

    Session::flash('error', 'نام کاربری یا رمز عبور اشتباه است.');
}

View::render('reseller/login', ['pageTitle' => 'ورود نمایندگی'], 'guest');

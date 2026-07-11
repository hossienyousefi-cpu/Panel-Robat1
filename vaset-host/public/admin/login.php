<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;

if (Auth::currentAdmin() !== null) {
    header('Location: /admin/dashboard.php');
    exit;
}

if (Request::isPost()) {
    Csrf::requireValid();
    $username = Request::postString('username');
    $password = Request::postString('password');

    if (Auth::attemptAdmin($username, $password)) {
        header('Location: /admin/dashboard.php');
        exit;
    }

    Session::flash('error', 'نام کاربری یا رمز عبور اشتباه است.');
}

View::render('admin/login', ['pageTitle' => 'ورود ادمین'], 'guest');

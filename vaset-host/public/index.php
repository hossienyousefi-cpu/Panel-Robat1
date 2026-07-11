<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\View;

View::render('landing', ['pageTitle' => 'پنل مدیریت'], 'guest');

<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Domain\Repositories\ManagedUserRepository;
use App\Domain\Repositories\ResellerRepository;

Auth::requireAdmin();

$users = (new ManagedUserRepository())->all(500);
$resellerNames = [];
foreach ((new ResellerRepository())->all() as $r) {
    $resellerNames[$r['id']] = $r['username'];
}

View::render('admin/users', [
    'pageTitle' => 'یوزرهای سیستم',
    'users' => $users,
    'resellerNames' => $resellerNames,
], 'admin');

<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Domain\Repositories\ManagedUserRepository;
use App\Domain\Repositories\OnlineSessionRepository;

$reseller = Auth::requireReseller();
$resellerId = (int) $reseller['id'];

$totalUsers = (new ManagedUserRepository())->countForReseller($resellerId);
$onlineCount = (new OnlineSessionRepository())->countForReseller($resellerId);
$expiringSoon = (new ManagedUserRepository())->expiringWithinDays(3, $resellerId);

View::render('reseller/dashboard', [
    'pageTitle' => 'داشبورد',
    'reseller' => $reseller,
    'totalUsers' => $totalUsers,
    'onlineCount' => $onlineCount,
    'expiringSoon' => $expiringSoon,
], 'reseller');

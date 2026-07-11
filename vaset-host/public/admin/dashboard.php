<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Domain\Repositories\ManagedUserRepository;
use App\Domain\Repositories\OnlineSessionRepository;
use App\Domain\Repositories\ReceiptRepository;
use App\Domain\Repositories\ResellerRepository;

Auth::requireAdmin();

$resellers = (new ResellerRepository())->all();
$userCounts = (new ManagedUserRepository())->countsByReseller();
$onlineCounts = (new OnlineSessionRepository())->countsByReseller();
$totalOnline = (new OnlineSessionRepository())->totalCount();
$expiringSoon = (new ManagedUserRepository())->expiringWithinDays(3);
$pendingReceipts = count((new ReceiptRepository())->pending());
$totalManagedUsers = array_sum($userCounts);

View::render('admin/dashboard', [
    'pageTitle' => 'داشبورد',
    'resellers' => $resellers,
    'userCounts' => $userCounts,
    'onlineCounts' => $onlineCounts,
    'totalOnline' => $totalOnline,
    'expiringSoon' => $expiringSoon,
    'pendingReceipts' => $pendingReceipts,
    'totalManagedUsers' => $totalManagedUsers,
], 'admin');

<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Domain\Repositories\AuditLogRepository;
use App\Domain\Repositories\ManagedUserRepository;
use App\Domain\Repositories\OnlineSessionRepository;
use App\Domain\Repositories\PricingRepository;
use App\Domain\Repositories\ResellerRepository;

$admin = Auth::requireAdmin();
$resellerRepo = new ResellerRepository();

if (Request::isPost()) {
    Csrf::requireValid();
    $username = Request::postString('username');
    $password = Request::postString('password');
    $fullName = Request::postString('full_name');
    $phone = Request::postString('phone');
    $isp = Request::postString('isp');

    if ($username === '' || $password === '' || $isp === '') {
        Session::flash('error', 'نام کاربری، رمز عبور و ISP الزامی است.');
    } elseif ($resellerRepo->findByUsername($username) !== null) {
        Session::flash('error', 'این نام کاربری قبلاً استفاده شده.');
    } else {
        $id = $resellerRepo->create($username, $password, $fullName, $phone, $isp);
        (new AuditLogRepository())->log('admin', (int) $admin['id'], 'create_reseller', $username);
        Session::flash('success', 'Reseller با موفقیت ساخته شد.');
        header('Location: /admin/reseller_edit.php?id=' . $id);
        exit;
    }
}

$resellers = $resellerRepo->all();
$userCounts = (new ManagedUserRepository())->countsByReseller();
$onlineCounts = (new OnlineSessionRepository())->countsByReseller();
$isps = (new PricingRepository())->cachedIsps();

View::render('admin/resellers', [
    'pageTitle' => 'Resellerها',
    'resellers' => $resellers,
    'userCounts' => $userCounts,
    'onlineCounts' => $onlineCounts,
    'isps' => $isps,
], 'admin');

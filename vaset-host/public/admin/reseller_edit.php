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
$pricingRepo = new PricingRepository();

$id = (int) Request::query('id', 0);
$reseller = $resellerRepo->find($id);
if ($reseller === null) {
    Session::flash('error', 'Reseller پیدا نشد.');
    header('Location: /admin/resellers.php');
    exit;
}

if (Request::isPost()) {
    Csrf::requireValid();
    $action = Request::postString('action');

    if ($action === 'update_profile') {
        $resellerRepo->update(
            $id,
            Request::postString('full_name'),
            Request::postString('phone'),
            Request::postString('isp'),
            Request::post('is_active') === '1'
        );
        Session::flash('success', 'اطلاعات به‌روزرسانی شد.');
    } elseif ($action === 'reset_password') {
        $newPassword = Request::postString('new_password');
        if ($newPassword !== '') {
            $resellerRepo->resetPassword($id, $newPassword);
            Session::flash('success', 'رمز عبور تغییر کرد.');
        }
    } elseif ($action === 'update_pricing') {
        $groups = Request::post('groups', []);
        $prices = Request::post('prices', []);
        $visible = Request::post('visible', []);
        foreach ((array) $groups as $i => $groupName) {
            $groupName = trim((string) $groupName);
            if ($groupName === '') {
                continue;
            }
            $price = (float) ($prices[$i] ?? 0);
            $isVisible = isset($visible[$i]);
            $pricingRepo->upsert($id, $groupName, $price, $isVisible);
        }
        Session::flash('success', 'قیمت‌گذاری به‌روزرسانی شد.');
    }

    (new AuditLogRepository())->log('admin', (int) $admin['id'], 'update_reseller', (string) $id, ['action' => $action]);
    header('Location: /admin/reseller_edit.php?id=' . $id);
    exit;
}

$reseller = $resellerRepo->find($id);
$cachedGroups = $pricingRepo->cachedGroups();
$existingPricing = $pricingRepo->allForReseller($id);
$existingByGroup = [];
foreach ($existingPricing as $row) {
    $existingByGroup[$row['group_name']] = $row;
}
foreach ($cachedGroups as $g) {
    if (!isset($existingByGroup[$g['group_name']])) {
        $existingByGroup[$g['group_name']] = ['group_name' => $g['group_name'], 'price' => 0, 'is_visible' => 0];
    }
}

$users = (new ManagedUserRepository())->forReseller($id);
$onlineCount = (new OnlineSessionRepository())->countForReseller($id);
$expiringSoon = (new ManagedUserRepository())->expiringWithinDays(3, $id);
$isps = $pricingRepo->cachedIsps();

View::render('admin/reseller_edit', [
    'pageTitle' => 'مدیریت Reseller',
    'reseller' => $reseller,
    'pricingRows' => array_values($existingByGroup),
    'users' => $users,
    'onlineCount' => $onlineCount,
    'expiringSoon' => $expiringSoon,
    'isps' => $isps,
], 'admin');

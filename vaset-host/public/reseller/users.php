<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Domain\Repositories\ManagedUserRepository;
use App\IBSng\IBSngGatewayFactory;
use App\Services\PricingService;
use App\Services\UserProvisioningService;

$reseller = Auth::requireReseller();
$provisioning = new UserProvisioningService(IBSngGatewayFactory::create());

if (Request::isPost()) {
    Csrf::requireValid();
    $action = Request::postString('action');

    try {
        if ($action === 'create') {
            $pattern = Request::postString('username_pattern');
            $count = max(1, Request::postInt('count', 1));
            $group = Request::postString('group_name');
            $autoPassword = Request::post('auto_password') === '1';
            $manualPassword = Request::postString('manual_password');

            $result = $provisioning->createForReseller($reseller, $pattern, $count, $group, $autoPassword, $manualPassword ?: null);

            $summary = count($result['created']) . ' یوزر ساخته شد.';
            if ($result['failed'] !== []) {
                $summary .= ' ' . count($result['failed']) . ' مورد ناموفق: ' . implode(', ', array_keys($result['failed']));
            }
            if ($autoPassword && $result['created'] !== []) {
                $pwLines = [];
                foreach ($result['created'] as $u) {
                    $pwLines[] = "{$u}: {$result['passwords'][$u]}";
                }
                $summary .= "\nپسوردها -> " . implode(' | ', $pwLines);
            }
            Session::flash('success', $summary);
        } elseif ($action === 'delete') {
            $provisioning->deleteForReseller($reseller, Request::postString('username'));
            Session::flash('success', 'یوزر حذف شد.');
        } elseif ($action === 'renew') {
            $provisioning->renewForReseller($reseller, Request::postString('username'));
            Session::flash('success', 'یوزر تمدید شد.');
        }
    } catch (\Throwable $e) {
        Session::flash('error', $e->getMessage());
    }

    header('Location: /reseller/users.php');
    exit;
}

$resellerId = (int) $reseller['id'];
$users = (new ManagedUserRepository())->forReseller($resellerId);
$catalog = (new PricingService())->catalogForReseller($resellerId);

View::render('reseller/users', [
    'pageTitle' => 'یوزرها',
    'users' => $users,
    'catalog' => $catalog,
], 'reseller');

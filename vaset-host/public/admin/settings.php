<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Domain\Repositories\AdminRepository;
use App\IBSng\HttpAgentGateway;
use App\Services\PricingService;

$admin = Auth::requireAdmin();
$gateway = new HttpAgentGateway();

if (Request::isPost()) {
    Csrf::requireValid();
    $action = Request::postString('action');

    if ($action === 'sync_ibsng') {
        try {
            (new PricingService())->refreshFromIBSng($gateway);
            Session::flash('success', 'گروه‌ها و ISPها با موفقیت از IBSng همگام‌سازی شدند.');
        } catch (\Throwable $e) {
            Session::flash('error', 'همگام‌سازی ناموفق بود: ' . $e->getMessage());
        }
    } elseif ($action === 'set_chat_id') {
        $chatId = Request::postInt('telegram_chat_id');
        if ($chatId > 0) {
            (new AdminRepository())->setTelegramChatId((int) $admin['id'], $chatId);
            Session::flash('success', 'شناسهٔ چت تلگرام ذخیره شد.');
        }
    }

    header('Location: /admin/settings.php');
    exit;
}

$agentHealthy = $gateway->healthCheck();

View::render('admin/settings', [
    'pageTitle' => 'تنظیمات',
    'admin' => $admin,
    'agentHealthy' => $agentHealthy,
], 'admin');

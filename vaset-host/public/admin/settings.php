<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Config;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Domain\Repositories\AdminRepository;
use App\IBSng\HttpAgentGateway;
use App\Services\DatabaseBackupService;
use App\Services\PricingService;
use App\Services\RuntimeSettings;

$admin = Auth::requireAdmin();
$runtimeSettings = new RuntimeSettings();

if (Request::isPost()) {
    Csrf::requireValid();
    $action = Request::postString('action');

    if ($action === 'sync_ibsng') {
        try {
            (new PricingService())->refreshFromIBSng(new HttpAgentGateway());
            Session::flash('success', 'گروه‌ها و ISPها با موفقیت از IBSng همگام‌سازی شدند.');
        } catch (\Throwable $e) {
            Session::flash('error', 'همگام‌سازی ناموفق بود: ' . $e->getMessage());
        }
    } elseif ($action === 'set_chat_id') {
        $chatId = Request::postInt('telegram_chat_id');
        if ($chatId > 0) {
            (new AdminRepository())->setTelegramChatId((int) $admin['id'], $chatId);
            Session::flash('success', 'شناسهٔ چت تلگرام ذخیره شد. حالا با همان اکانت تلگرام به ربات پیام دهید تا پنل مدیریتی ربات (Export/Import و تنظیمات اتصال) برایتان فعال شود.');
        }
    } elseif ($action === 'set_agent_url') {
        $url = trim(Request::postString('agent_url'));
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            $runtimeSettings->set(RuntimeSettings::IBSNG_AGENT_URL, rtrim($url, '/'));
            Session::flash('success', 'آدرس IBSng Agent به‌روزرسانی شد.');
        } else {
            Session::flash('error', 'آدرس نامعتبر است.');
        }
    } elseif ($action === 'set_agent_key') {
        $key = trim(Request::postString('agent_key'));
        if (strlen($key) >= 8) {
            $runtimeSettings->set(RuntimeSettings::IBSNG_AGENT_API_KEY, $key);
            Session::flash('success', 'کلید API به‌روزرسانی شد؛ حتماً همین مقدار را در config.php سرور IBSng هم به‌روز کنید.');
        } else {
            Session::flash('error', 'کلید باید حداقل ۸ کاراکتر باشد.');
        }
    } elseif ($action === 'export_db') {
        try {
            $path = (new DatabaseBackupService())->export();
            Session::flash('success', 'فایل خروجی ساخته شد: ' . basename($path) . ' (در storage/backups/ روی سرور، یا از طریق ربات تلگرام دریافت کنید).');
        } catch (\Throwable $e) {
            Session::flash('error', 'Export ناموفق بود: ' . $e->getMessage());
        }
    }

    header('Location: /admin/settings.php');
    exit;
}

$gateway = new HttpAgentGateway();
$agentHealthy = $gateway->healthCheck();

View::render('admin/settings', [
    'pageTitle' => 'تنظیمات',
    'admin' => $admin,
    'agentHealthy' => $agentHealthy,
    'agentUrl' => $runtimeSettings->get(RuntimeSettings::IBSNG_AGENT_URL, ''),
    'agentKeySet' => $runtimeSettings->isOverridden(RuntimeSettings::IBSNG_AGENT_API_KEY) || (Config::get('IBSNG_AGENT_API_KEY', '') !== ''),
], 'admin');

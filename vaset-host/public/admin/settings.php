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
use App\IBSng\IBSngGatewayFactory;
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
            (new PricingService())->refreshFromIBSng(IBSngGatewayFactory::create());
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
    } elseif ($action === 'set_direct_connection') {
        $baseUrl = rtrim(trim(Request::postString('admin_base_url')), '/');
        $username = trim(Request::postString('admin_username'));
        $password = Request::postString('admin_password');
        $verifySsl = Request::postString('verify_ssl') === '1';

        if ($baseUrl !== '' && preg_match('#^https?://#i', $baseUrl)) {
            $runtimeSettings->set(RuntimeSettings::IBSNG_ADMIN_BASE_URL, $baseUrl);
        }
        if ($username !== '') {
            $runtimeSettings->set(RuntimeSettings::IBSNG_ADMIN_USERNAME, $username);
        }
        if ($password !== '') {
            $runtimeSettings->set(RuntimeSettings::IBSNG_ADMIN_PASSWORD, $password);
        }
        $runtimeSettings->set(RuntimeSettings::IBSNG_ADMIN_VERIFY_SSL, $verifySsl ? 'true' : 'false');
        $runtimeSettings->set(RuntimeSettings::IBSNG_CONNECTION_MODE, 'direct');
        Session::flash('success', 'تنظیمات اتصال مستقیم به IBSng ذخیره شد.');
    } elseif ($action === 'set_connection_mode') {
        $mode = Request::postString('connection_mode') === 'agent' ? 'agent' : 'direct';
        $runtimeSettings->set(RuntimeSettings::IBSNG_CONNECTION_MODE, $mode);
        Session::flash('success', 'روش اتصال به IBSng تغییر کرد: ' . ($mode === 'agent' ? 'از طریق IBSng Agent (تونل SSH)' : 'مستقیم (بدون نصب چیزی روی سرور IBSng)'));
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

$connectionMode = $runtimeSettings->get(RuntimeSettings::IBSNG_CONNECTION_MODE, 'direct');
$gateway = IBSngGatewayFactory::create();
$ibsngHealthy = $gateway->healthCheck();

View::render('admin/settings', [
    'pageTitle' => 'تنظیمات',
    'admin' => $admin,
    'connectionMode' => $connectionMode,
    'ibsngHealthy' => $ibsngHealthy,
    'adminBaseUrl' => $runtimeSettings->get(RuntimeSettings::IBSNG_ADMIN_BASE_URL, 'https://194.59.214.84/IBSng/admin'),
    'adminUsername' => $runtimeSettings->get(RuntimeSettings::IBSNG_ADMIN_USERNAME, ''),
    'adminPasswordSet' => $runtimeSettings->isOverridden(RuntimeSettings::IBSNG_ADMIN_PASSWORD),
    'adminVerifySsl' => $runtimeSettings->get(RuntimeSettings::IBSNG_ADMIN_VERIFY_SSL, 'false') === 'true',
    'agentUrl' => $runtimeSettings->get(RuntimeSettings::IBSNG_AGENT_URL, ''),
    'agentKeySet' => $runtimeSettings->isOverridden(RuntimeSettings::IBSNG_AGENT_API_KEY) || (Config::get('IBSNG_AGENT_API_KEY', '') !== ''),
], 'admin');

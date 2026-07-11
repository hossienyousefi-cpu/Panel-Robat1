<?php

declare(strict_types=1);

namespace App\Bot;

use App\Domain\Repositories\AdminRepository;
use App\IBSng\IBSngGatewayFactory;
use App\Services\DatabaseBackupService;
use App\Services\RuntimeSettings;
use App\Services\TelegramNotifier;
use App\Support\TelegramFileDownloader;
use Throwable;

/**
 * Task 4: an admin-only control panel reachable from inside the same Telegram bot.
 * Any chat whose id matches an admins.telegram_chat_id (set from /admin/settings.php)
 * is routed here instead of the customer flow. Covers:
 *  - exporting/importing the vaset-host system's own database (never IBSng's DB), and
 *  - viewing/changing the IBSng Agent URL and API key at runtime, so the connection
 *    can be repaired from a phone if it ever breaks, without needing shell access.
 */
final class AdminBotController
{
    private const IMPORT_CONFIRM_PHRASE = 'CONFIRM-IMPORT';

    private AdminRepository $admins;
    private TelegramNotifier $notifier;
    private DatabaseBackupService $backups;
    private RuntimeSettings $settings;

    public function __construct()
    {
        $this->admins = new AdminRepository();
        $this->notifier = new TelegramNotifier();
        $this->backups = new DatabaseBackupService();
        $this->settings = new RuntimeSettings();
    }

    public function handleUpdate(array $admin, array $update): void
    {
        if (!isset($update['message'])) {
            return;
        }
        $this->handleMessage($admin, $update['message']);
    }

    private function handleMessage(array $admin, array $message): void
    {
        $chatId = (int) $admin['telegram_chat_id'];
        $adminId = (int) $admin['id'];
        $state = $admin['conversation_state'];

        if (isset($message['document'])) {
            if ($state === 'awaiting_import_file') {
                $this->handleImportFile($admin, $message['document']);
            } else {
                $this->notifier->sendToChat($chatId, 'فایلی در انتظار دریافت نبود. از منو گزینهٔ «📥 Import دیتابیس» را بزنید.', Keyboards::adminMenu());
            }
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));

        if ($text === '❌ لغو عملیات جاری' || $text === '/cancel') {
            $this->clearPendingImportFile($admin);
            $this->admins->setConversationState($adminId, null, null);
            $this->notifier->sendToChat($chatId, 'عملیات لغو شد.', Keyboards::adminMenu());
            return;
        }

        switch ($state) {
            case 'awaiting_agent_url':
                $this->setAgentUrl($admin, $text);
                return;
            case 'awaiting_agent_key':
                $this->setAgentKey($admin, $text);
                return;
            case 'awaiting_import_confirm':
                $this->confirmImport($admin, $text);
                return;
        }

        switch ($text) {
            case '/start':
                $this->notifier->sendToChat(
                    $chatId,
                    "سلام {$admin['full_name']} 👋\nاین بخش مدیریتی ربات است (فقط برای ادمین) — از اینجا می‌توانید دیتابیس سیستم را Export/Import کنید و در صورت قطعی ارتباط، آدرس IBSng Agent را تغییر دهید.",
                    Keyboards::adminMenu()
                );
                return;
            case '📤 Export دیتابیس':
                $this->exportDatabase($admin);
                return;
            case '📥 Import دیتابیس':
                $this->admins->setConversationState($adminId, 'awaiting_import_file');
                $this->notifier->sendToChat(
                    $chatId,
                    "لطفاً فایل .sql یا .sql.gz را به‌صورت Document (نه عکس) ارسال کنید.\n\n" .
                    "⚠️ توجه: تلگرام اجازهٔ دانلود فایل‌های بزرگ‌تر از ۲۰ مگابایت را به ربات‌ها نمی‌دهد.\n" .
                    "⚠️ این عملیات دادهٔ فعلی سیستم (Resellerها، قیمت‌گذاری، فیش‌ها، لجر مالی) را با فایل جایگزین می‌کند؛ قبل از اجرا یک بکاپ خودکار گرفته می‌شود. برای انصراف «❌ لغو عملیات جاری» را بزنید."
                );
                return;
            case '⚙️ آدرس IBSng Agent':
                $current = $this->settings->get(RuntimeSettings::IBSNG_AGENT_URL, '(تنظیم نشده)');
                $this->admins->setConversationState($adminId, 'awaiting_agent_url');
                $this->notifier->sendToChat($chatId, "آدرس فعلی: {$current}\n\nآدرس جدید IBSng Agent را ارسال کنید (مثلاً http://127.0.0.1:9091):");
                return;
            case '🔑 کلید API Agent':
                $masked = $this->maskKey((string) $this->settings->get(RuntimeSettings::IBSNG_AGENT_API_KEY, ''));
                $this->admins->setConversationState($adminId, 'awaiting_agent_key');
                $this->notifier->sendToChat($chatId, "کلید فعلی: {$masked}\n\nکلید API جدید را ارسال کنید:");
                return;
            case '📶 تست اتصال':
                $this->testConnection($admin);
                return;
            default:
                $this->notifier->sendToChat($chatId, 'از دکمه‌های زیر استفاده کنید 👇', Keyboards::adminMenu());
        }
    }

    private function exportDatabase(array $admin): void
    {
        $chatId = (int) $admin['telegram_chat_id'];
        $this->notifier->sendToChat($chatId, '⏳ در حال تهیهٔ خروجی از دیتابیس اختصاصی این سیستم (Resellerها، قیمت‌گذاری، فیش‌ها، لجر مالی) — نه دیتابیس IBSng...');

        try {
            $path = $this->backups->export();
            $sizeMb = round(filesize($path) / 1024 / 1024, 2);

            if ($sizeMb > 45) {
                $this->notifier->sendToChat(
                    $chatId,
                    "⚠️ حجم فایل ({$sizeMb} مگابایت) برای ارسال در تلگرام زیاد است. فایل روی سرور در مسیر زیر ذخیره شد؛ از طریق SFTP دریافتش کنید:\n{$path}",
                    Keyboards::adminMenu()
                );
                return;
            }

            $ok = $this->notifier->sendDocumentFromPath($chatId, $path, '📦 بکاپ دیتابیس سیستم - ' . date('Y-m-d H:i'));
            if (!$ok) {
                $this->notifier->sendToChat($chatId, 'ارسال فایل در تلگرام ناموفق بود؛ فایل روی سرور در این مسیر باقی ماند:\n' . $path, Keyboards::adminMenu());
            }
        } catch (Throwable $e) {
            $this->notifier->sendToChat($chatId, '❌ خطا در گرفتن خروجی: ' . $e->getMessage(), Keyboards::adminMenu());
        }
    }

    private function handleImportFile(array $admin, array $document): void
    {
        $chatId = (int) $admin['telegram_chat_id'];
        $fileName = (string) ($document['file_name'] ?? '');

        if (!preg_match('/\.sql(\.gz)?$/i', $fileName)) {
            $this->notifier->sendToChat($chatId, 'فقط فایل با پسوند .sql یا .sql.gz پذیرفته می‌شود.');
            return;
        }

        try {
            $dir = dirname(__DIR__, 2) . '/storage/imports';
            $localPath = TelegramFileDownloader::download((string) $document['file_id'], $dir, 'import');
        } catch (Throwable $e) {
            $this->notifier->sendToChat($chatId, 'دانلود فایل ناموفق بود: ' . $e->getMessage());
            return;
        }

        $this->admins->setConversationState((int) $admin['id'], 'awaiting_import_confirm', ['file' => $localPath]);
        $this->notifier->sendToChat(
            $chatId,
            "فایل دریافت شد.\n\n⚠️ این عملیات دادهٔ فعلی سیستم را با محتوای این فایل جایگزین می‌کند (پیش از اجرا یک بکاپ امن گرفته می‌شود).\n\n" .
            'برای تأیید نهایی دقیقاً همین عبارت را ارسال کنید: ' . self::IMPORT_CONFIRM_PHRASE
        );
    }

    private function confirmImport(array $admin, string $text): void
    {
        $chatId = (int) $admin['telegram_chat_id'];
        $adminId = (int) $admin['id'];
        $filePath = $this->pendingImportFile($admin);

        if ($text !== self::IMPORT_CONFIRM_PHRASE) {
            $this->clearPendingImportFile($admin);
            $this->admins->setConversationState($adminId, null, null);
            $this->notifier->sendToChat($chatId, 'عبارت تأیید مطابقت نداشت؛ عملیات import لغو شد.', Keyboards::adminMenu());
            return;
        }

        $this->notifier->sendToChat($chatId, '⏳ در حال اجرای import (ابتدا یک بکاپ امن از وضعیت فعلی گرفته می‌شود)...');

        try {
            $result = $this->backups->import($filePath);
            $this->notifier->sendToChat(
                $chatId,
                "✅ Import با موفقیت انجام شد.\nبکاپ امن قبل از این عملیات:\n{$result['safety_backup']}",
                Keyboards::adminMenu()
            );
        } catch (Throwable $e) {
            $this->notifier->sendToChat($chatId, '❌ Import ناموفق بود: ' . $e->getMessage(), Keyboards::adminMenu());
        } finally {
            @unlink($filePath);
            $this->admins->setConversationState($adminId, null, null);
        }
    }

    private function setAgentUrl(array $admin, string $text): void
    {
        $chatId = (int) $admin['telegram_chat_id'];
        $adminId = (int) $admin['id'];

        if ($text === '' || !preg_match('#^https?://#i', $text)) {
            $this->notifier->sendToChat($chatId, 'آدرس نامعتبر است؛ باید با http:// یا https:// شروع شود. دوباره ارسال کنید یا «❌ لغو عملیات جاری» را بزنید.');
            return;
        }

        $this->settings->set(RuntimeSettings::IBSNG_AGENT_URL, rtrim($text, '/'));
        $this->admins->setConversationState($adminId, null, null);
        $this->notifier->sendToChat($chatId, "✅ آدرس IBSng Agent به‌روزرسانی شد:\n{$text}\n\nبرای اطمینان از «📶 تست اتصال» استفاده کنید.", Keyboards::adminMenu());
    }

    private function setAgentKey(array $admin, string $text): void
    {
        $chatId = (int) $admin['telegram_chat_id'];
        $adminId = (int) $admin['id'];

        if (strlen($text) < 8) {
            $this->notifier->sendToChat($chatId, 'کلید باید حداقل ۸ کاراکتر باشد. دوباره ارسال کنید یا «❌ لغو عملیات جاری» را بزنید.');
            return;
        }

        $this->settings->set(RuntimeSettings::IBSNG_AGENT_API_KEY, $text);
        $this->admins->setConversationState($adminId, null, null);
        $this->notifier->sendToChat($chatId, '✅ کلید API به‌روزرسانی شد: ' . $this->maskKey($text) . "\n\nحتماً همین مقدار را در config.php سرور IBSng هم به‌روز کنید تا با هم مطابقت داشته باشند.", Keyboards::adminMenu());
    }

    private function testConnection(array $admin): void
    {
        $chatId = (int) $admin['telegram_chat_id'];
        $mode = $this->settings->get(RuntimeSettings::IBSNG_CONNECTION_MODE, 'direct');
        $gateway = IBSngGatewayFactory::create();
        $ok = $gateway->healthCheck();
        $url = $mode === 'agent'
            ? $this->settings->get(RuntimeSettings::IBSNG_AGENT_URL, '(تنظیم نشده)')
            : $this->settings->get(RuntimeSettings::IBSNG_ADMIN_BASE_URL, '(تنظیم نشده)');
        $modeLabel = $mode === 'agent' ? 'IBSng Agent' : 'اتصال مستقیم';

        $this->notifier->sendToChat(
            $chatId,
            ($ok ? "✅ اتصال ({$modeLabel}) برقرار است." : "❌ اتصال ({$modeLabel}) برقرار نیست.") . "\nآدرس فعلی: {$url}",
            Keyboards::adminMenu()
        );
    }

    private function maskKey(string $key): string
    {
        if ($key === '') {
            return '(تنظیم نشده)';
        }
        if (strlen($key) <= 8) {
            return str_repeat('*', strlen($key));
        }
        return substr($key, 0, 4) . str_repeat('*', max(4, strlen($key) - 8)) . substr($key, -4);
    }

    private function pendingImportFile(array $admin): string
    {
        $payload = json_decode((string) ($admin['conversation_payload'] ?? ''), true) ?? [];
        return (string) ($payload['file'] ?? '');
    }

    private function clearPendingImportFile(array $admin): void
    {
        $path = $this->pendingImportFile($admin);
        if ($path !== '') {
            @unlink($path);
        }
    }
}

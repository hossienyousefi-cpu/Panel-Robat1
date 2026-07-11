<?php

declare(strict_types=1);

namespace App\Bot;

use App\Config;
use App\Domain\Repositories\AdminRepository;
use App\Domain\Repositories\ManagedUserRepository;
use App\Domain\Repositories\OrderRepository;
use App\Domain\Repositories\ReceiptRepository;
use App\Domain\Repositories\TelegramCustomerRepository;
use App\IBSng\IBSngGatewayFactory;
use App\Services\PricingService;
use App\Services\TelegramNotifier;
use App\Services\UserProvisioningService;
use App\Support\TelegramFileDownloader;
use RuntimeException;

final class TelegramBot
{
    private TelegramCustomerRepository $customers;
    private ManagedUserRepository $managedUsers;
    private OrderRepository $orders;
    private ReceiptRepository $receipts;
    private PricingService $pricing;
    private TelegramNotifier $notifier;
    private UserProvisioningService $provisioning;
    private AdminRepository $admins;
    private AdminBotController $adminBot;

    public function __construct()
    {
        $this->customers = new TelegramCustomerRepository();
        $this->managedUsers = new ManagedUserRepository();
        $this->orders = new OrderRepository();
        $this->receipts = new ReceiptRepository();
        $this->pricing = new PricingService();
        $this->notifier = new TelegramNotifier();
        $this->provisioning = new UserProvisioningService(IBSngGatewayFactory::create());
        $this->admins = new AdminRepository();
        $this->adminBot = new AdminBotController();
    }

    public function handleUpdate(array $update): void
    {
        try {
            $chatId = $this->extractChatId($update);

            // Admins (identified by the telegram_chat_id saved in /admin/settings.php)
            // get an entirely separate control-panel flow: DB export/import, and
            // changing how the bot talks to the IBSng Agent - see AdminBotController.
            if ($chatId !== null) {
                $admin = $this->admins->findByTelegramChatId($chatId);
                if ($admin !== null) {
                    $this->adminBot->handleUpdate($admin, $update);
                    return;
                }
            }

            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
            }
        } catch (\Throwable $e) {
            error_log('[panel-vaset] Telegram bot error: ' . $e->getMessage());
        }
    }

    private function extractChatId(array $update): ?int
    {
        if (isset($update['message']['chat']['id'])) {
            return (int) $update['message']['chat']['id'];
        }
        if (isset($update['callback_query']['message']['chat']['id'])) {
            return (int) $update['callback_query']['message']['chat']['id'];
        }
        return null;
    }

    private function handleMessage(array $message): void
    {
        $chatId = (int) $message['chat']['id'];
        $customer = $this->customers->findOrCreate(
            $chatId,
            $message['from']['first_name'] ?? null,
            $message['from']['username'] ?? null
        );

        if (isset($message['photo']) && $customer['conversation_state'] === 'awaiting_receipt_photo') {
            $this->handleReceiptPhoto($customer, $message);
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));

        if ($customer['conversation_state'] === 'awaiting_tracking_code' && $text !== '') {
            $this->handleTrackingCode($customer, $text);
            return;
        }

        switch ($text) {
            case '/start':
                $this->resetState($customer['id']);
                $this->notifier->sendToChat($chatId, "سلام {$message['from']['first_name']} 👋\nبه ربات فروش سرویس اینترنت خوش آمدید.", Keyboards::mainMenu());
                return;
            case '🛍 مشاهده سرویس‌ها و خرید':
                $this->showCatalog($chatId);
                return;
            case '♻️ تمدید سرویس':
                $this->showMyServicesForRenew($customer, $chatId);
                return;
            case '💳 ثبت فیش پرداخت':
                $this->promptManualReceipt($customer, $chatId);
                return;
            case '👤 حساب من':
                $this->showAccount($customer, $chatId);
                return;
            case '☎️ پشتیبانی':
                $this->notifier->sendToChat($chatId, Config::get('SUPPORT_CONTACT_MESSAGE', 'برای پشتیبانی با ادمین در تماس باشید.'));
                return;
            default:
                $this->notifier->sendToChat($chatId, 'از دکمه‌های زیر استفاده کنید 👇', Keyboards::mainMenu());
        }
    }

    private function handleCallback(array $callback): void
    {
        $chatId = (int) $callback['message']['chat']['id'];
        $data = (string) ($callback['data'] ?? '');
        $customer = $this->customers->findOrCreate($chatId, $callback['from']['first_name'] ?? null, $callback['from']['username'] ?? null);

        if (str_starts_with($data, 'buy:')) {
            $this->startNewOrder($customer, $chatId, substr($data, 4));
        } elseif (str_starts_with($data, 'renew:')) {
            $this->startRenewOrder($customer, $chatId, substr($data, 6));
        }
    }

    private function showCatalog(int $chatId): void
    {
        $catalog = $this->pricing->directCatalog();
        if ($catalog === []) {
            $this->notifier->sendToChat($chatId, 'در حال حاضر سرویسی برای فروش تعریف نشده است.');
            return;
        }
        $this->notifier->sendToChat($chatId, 'یکی از سرویس‌های زیر را برای خرید انتخاب کنید:', Keyboards::catalogInline($catalog, 'buy'));
    }

    private function showMyServicesForRenew(array $customer, int $chatId): void
    {
        $users = $this->managedUsers->forTelegramCustomer((int) $customer['id']);
        if ($users === []) {
            $this->notifier->sendToChat($chatId, 'شما هنوز هیچ سرویس فعالی ندارید.');
            return;
        }
        $this->notifier->sendToChat($chatId, 'کدام سرویس را می‌خواهید تمدید کنید؟', Keyboards::myServicesInline($users, 'renew'));
    }

    private function startNewOrder(array $customer, int $chatId, string $groupName): void
    {
        $price = $this->pricing->priceForDirectCatalog($groupName);
        if ($price === null) {
            $this->notifier->sendToChat($chatId, 'این سرویس دیگر در دسترس نیست.');
            return;
        }

        $orderId = $this->orders->create('new', (int) $customer['id'], $groupName, null, $price);
        $order = $this->orders->find($orderId);

        try {
            $username = $this->provisioning->provisionForOrder($order);
        } catch (RuntimeException $e) {
            $this->notifier->sendToChat($chatId, 'خطا در فعال‌سازی سرویس: ' . $e->getMessage());
            return;
        }

        $this->orders->setProvisionedUsername($orderId, $username);
        $this->customers->adjustBalance((int) $customer['id'], -$price);

        $card = Config::get('PAYMENT_CARD_INFO', '(اطلاعات کارت بانکی در تنظیمات ادمین وارد نشده)');
        $this->notifier->sendToChat(
            $chatId,
            "✅ سرویس شما با یوزرنیم <b>{$username}</b> فعال شد.\n" .
            "مبلغ قابل پرداخت: " . number_format($price) . " تومان\n\n" .
            "لطفاً مبلغ را به شماره کارت زیر واریز کنید و سپس عکس فیش را همینجا ارسال کنید:\n{$card}\n\n" .
            "⏰ توجه: اگر تا ۲۴ ساعت آینده فیش پرداخت تأیید نشود، سرویس شما قفل خواهد شد."
        );

        $this->customers->setConversationState((int) $customer['id'], 'awaiting_receipt_photo', ['order_id' => $orderId]);
    }

    private function startRenewOrder(array $customer, int $chatId, string $username): void
    {
        $managedUser = $this->managedUsers->findByUsername($username);
        if ($managedUser === null || (int) $managedUser['telegram_customer_id'] !== (int) $customer['id']) {
            $this->notifier->sendToChat($chatId, 'این سرویس متعلق به شما نیست.');
            return;
        }

        $price = $this->pricing->priceForDirectCatalog((string) $managedUser['group_name']);
        if ($price === null) {
            $this->notifier->sendToChat($chatId, 'قیمت این سرویس دیگر تعریف نشده؛ با پشتیبانی تماس بگیرید.');
            return;
        }

        $orderId = $this->orders->create('renew', (int) $customer['id'], (string) $managedUser['group_name'], $username, $price);
        $order = $this->orders->find($orderId);

        try {
            $this->provisioning->provisionForOrder($order);
        } catch (RuntimeException $e) {
            $this->notifier->sendToChat($chatId, 'خطا در تمدید سرویس: ' . $e->getMessage());
            return;
        }

        $this->orders->setProvisionedUsername($orderId, $username);
        $this->customers->adjustBalance((int) $customer['id'], -$price);

        $card = Config::get('PAYMENT_CARD_INFO', '(اطلاعات کارت بانکی در تنظیمات ادمین وارد نشده)');
        $this->notifier->sendToChat(
            $chatId,
            "✅ سرویس <b>{$username}</b> تمدید شد.\n" .
            "مبلغ قابل پرداخت: " . number_format($price) . " تومان\n\n" .
            "لطفاً مبلغ را به شماره کارت زیر واریز کنید و سپس عکس فیش را همینجا ارسال کنید:\n{$card}\n\n" .
            "⏰ توجه: اگر تا ۲۴ ساعت آینده فیش پرداخت تأیید نشود، سرویس شما قفل خواهد شد."
        );

        $this->customers->setConversationState((int) $customer['id'], 'awaiting_receipt_photo', ['order_id' => $orderId]);
    }

    private function promptManualReceipt(array $customer, int $chatId): void
    {
        $orders = $this->orders->forTelegramCustomer((int) $customer['id']);
        $pending = array_values(array_filter($orders, fn ($o) => in_array($o['status'], ['pending_payment', 'under_review'], true)));

        if ($pending === []) {
            $this->notifier->sendToChat($chatId, 'در حال حاضر سفارش در انتظار پرداختی ندارید.');
            return;
        }

        $latest = $pending[0];
        $this->customers->setConversationState((int) $customer['id'], 'awaiting_receipt_photo', ['order_id' => $latest['id']]);
        $this->notifier->sendToChat($chatId, "لطفاً عکس فیش پرداخت سفارش #{$latest['id']} را ارسال کنید.");
    }

    private function handleReceiptPhoto(array $customer, array $message): void
    {
        $payload = json_decode((string) $customer['conversation_payload'], true) ?? [];
        $orderId = (int) ($payload['order_id'] ?? 0);
        $order = $orderId > 0 ? $this->orders->find($orderId) : null;
        if ($order === null) {
            $this->notifier->sendToChat((int) $customer['chat_id'], 'سفارش مرتبط پیدا نشد؛ لطفاً از منو دوباره شروع کنید.', Keyboards::mainMenu());
            $this->resetState((int) $customer['id']);
            return;
        }

        $photos = $message['photo'];
        $fileId = end($photos)['file_id'];
        $localPath = $this->downloadTelegramFile($fileId);

        $this->customers->setConversationState((int) $customer['id'], 'awaiting_tracking_code', ['order_id' => $orderId, 'image_path' => $localPath]);
        $this->notifier->sendToChat((int) $customer['chat_id'], 'کد پیگیری/شماره تراکنش فیش را ارسال کنید (یا اگر ندارید عدد 0 را بفرستید):');
    }

    private function handleTrackingCode(array $customer, string $text): void
    {
        $payload = json_decode((string) $customer['conversation_payload'], true) ?? [];
        $orderId = (int) ($payload['order_id'] ?? 0);
        $imagePath = (string) ($payload['image_path'] ?? '');
        $order = $orderId > 0 ? $this->orders->find($orderId) : null;

        if ($order === null || $imagePath === '') {
            $this->notifier->sendToChat((int) $customer['chat_id'], 'مشکلی پیش آمد؛ لطفاً دوباره از منو شروع کنید.', Keyboards::mainMenu());
            $this->resetState((int) $customer['id']);
            return;
        }

        $this->receipts->createForOrder($orderId, (float) $order['price'], $text, $imagePath);
        $this->orders->markUnderReview($orderId);
        $this->resetState((int) $customer['id']);

        $this->notifier->sendToChat((int) $customer['chat_id'], '✅ فیش شما ثبت شد و در انتظار تأیید ادمین است.', Keyboards::mainMenu());
        $this->notifier->notifyAllAdmins("💳 فیش پرداخت جدید برای سفارش #{$orderId} ثبت شد و منتظر بررسی است.");
    }

    private function showAccount(array $customer, int $chatId): void
    {
        $balance = (float) $customer['balance'];
        $status = $balance < 0 ? ('بدهکار: ' . number_format(abs($balance)) . ' تومان') : 'بدون بدهی';
        $services = $this->managedUsers->forTelegramCustomer((int) $customer['id']);

        $lines = ["👤 وضعیت حساب شما:", $status, '', '📦 سرویس‌های شما:'];
        if ($services === []) {
            $lines[] = 'هنوز سرویسی ندارید.';
        }
        foreach ($services as $service) {
            $lock = $service['is_locked'] ? ' 🔒(قفل)' : '';
            $expires = $service['expires_at'] ? (' - انقضا: ' . $service['expires_at']) : '';
            $lines[] = "• {$service['ibsng_username']} ({$service['group_name']}){$expires}{$lock}";
        }

        $this->notifier->sendToChat($chatId, implode("\n", $lines));
    }

    private function resetState(int $customerId): void
    {
        $this->customers->setConversationState($customerId, null, null);
    }

    private function downloadTelegramFile(string $fileId): string
    {
        $dir = dirname(__DIR__, 2) . '/public/uploads/receipts';
        $absolutePath = TelegramFileDownloader::download($fileId, $dir, 'receipt');

        return 'uploads/receipts/' . basename($absolutePath);
    }
}

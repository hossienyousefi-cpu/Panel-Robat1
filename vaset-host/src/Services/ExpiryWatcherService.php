<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Repositories\ManagedUserRepository;
use App\Domain\Repositories\OrderRepository;
use App\Domain\Repositories\TelegramCustomerRepository;
use App\IBSng\IBSngGatewayInterface;

/**
 * Used by cron/expiry_and_overdue_watch.php. Two independent jobs live here because
 * they were both described as periodic checks in the spec:
 *  1) refresh cached expiry/lock state for every managed user (powers the reseller
 *     dashboard's "3 days to expire" list without hitting IBSng on every page view).
 *  2) find telegram-direct orders unpaid for more than 24h, notify admins and lock
 *     the corresponding IBSng account.
 */
final class ExpiryWatcherService
{
    public function __construct(
        private readonly IBSngGatewayInterface $gateway,
        private readonly ManagedUserRepository $managedUsers = new ManagedUserRepository(),
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly TelegramCustomerRepository $telegramCustomers = new TelegramCustomerRepository(),
        private readonly ?TelegramNotifier $notifier = null,
    ) {
    }

    /** @return int number of users whose cached status was refreshed */
    public function refreshExpiryCache(): int
    {
        $refreshed = 0;
        foreach ($this->managedUsers->all(2000) as $user) {
            $status = $this->gateway->getUserStatus((string) $user['ibsng_username']);
            if ($status === null || !$status->exists) {
                continue;
            }
            $this->managedUsers->updateExpiry((string) $user['ibsng_username'], $status->expiresAt);
            $this->managedUsers->setLocked((string) $user['ibsng_username'], $status->isLocked);
            $refreshed++;
        }
        return $refreshed;
    }

    /** @return int number of orders locked+notified in this run */
    public function lockAndNotifyOverdueOrders(int $hours = 24): int
    {
        $overdue = $this->orders->overdueUnpaid($hours);
        $notifier = $this->notifier ?? new TelegramNotifier();

        foreach ($overdue as $order) {
            $customer = $this->telegramCustomers->find((int) $order['telegram_customer_id']);
            $username = $order['provisioned_username'] ?? $order['target_username'];

            if ($username !== null) {
                $this->gateway->lockUser((string) $username);
                $this->managedUsers->setLocked((string) $username, true);
            }

            $customerLabel = $customer !== null
                ? ('@' . ($customer['tg_username'] ?: $customer['chat_id']))
                : 'کاربر ناشناس';

            $notifier->notifyAllAdmins(
                "⚠️ کاربر {$customerLabel} برای سفارش #{$order['id']} (گروه {$order['group_name']}) بیش از {$hours} ساعت " .
                'است که فیش پرداخت را ثبت/تأیید نکرده. اکانت ' . ($username ?? '-') . ' قفل شد.'
            );

            $this->orders->markOverdueNotified((int) $order['id']);
        }

        return count($overdue);
    }
}

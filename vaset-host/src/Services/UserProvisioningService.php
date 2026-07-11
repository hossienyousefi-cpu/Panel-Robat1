<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Core\Database;
use App\Domain\Repositories\AuditLogRepository;
use App\Domain\Repositories\LedgerRepository;
use App\Domain\Repositories\ManagedUserRepository;
use App\Domain\Repositories\PricingRepository;
use App\Domain\Repositories\ResellerRepository;
use App\Domain\Repositories\TelegramCustomerRepository;
use App\IBSng\Dto\CreateUserItem;
use App\IBSng\IBSngGatewayInterface;
use App\Support\PasswordGenerator;
use App\Support\UsernamePattern;
use RuntimeException;

/**
 * Orchestrates "create/delete/renew a real IBSng account" together with the local
 * bookkeeping (managed_users + ledger_entries) that keeps reseller/admin panels
 * accurate. IBSng Gateway calls happen first (can't be rolled back), local DB writes
 * for whatever the gateway actually confirmed happen afterwards inside a transaction.
 */
final class UserProvisioningService
{
    public function __construct(
        private readonly IBSngGatewayInterface $gateway,
        private readonly ManagedUserRepository $managedUsers = new ManagedUserRepository(),
        private readonly ResellerRepository $resellers = new ResellerRepository(),
        private readonly TelegramCustomerRepository $telegramCustomers = new TelegramCustomerRepository(),
        private readonly PricingRepository $pricing = new PricingRepository(),
        private readonly LedgerRepository $ledger = new LedgerRepository(),
        private readonly AuditLogRepository $auditLog = new AuditLogRepository(),
    ) {
    }

    /**
     * @return array{created:string[],failed:array<string,string>,total_charged:float}
     */
    public function createForReseller(
        array $reseller,
        string $usernamePattern,
        int $count,
        string $groupName,
        bool $autoGeneratePassword,
        ?string $manualPassword
    ): array {
        $resellerId = (int) $reseller['id'];

        $price = $this->pricing->priceFor($resellerId, $groupName);
        if ($price === null) {
            throw new RuntimeException('این گروه برای شما فعال نشده یا قیمتی برایش تعریف نشده است.');
        }

        $usernames = UsernamePattern::expand($usernamePattern, $count);

        $items = [];
        $plainPasswords = [];
        foreach ($usernames as $username) {
            $password = $autoGeneratePassword ? PasswordGenerator::fourDigit() : (string) $manualPassword;
            if ($password === '') {
                throw new RuntimeException('پسورد نمی‌تواند خالی باشد.');
            }
            $items[] = new CreateUserItem($username, $password);
            $plainPasswords[$username] = $password;
        }

        $credit1 = (float) Config::get('IBSNG_DEFAULT_CREDIT1', '100');
        $credit2 = (float) Config::get('IBSNG_DEFAULT_CREDIT2', '0');

        $results = $this->gateway->createUsers($items, $groupName, (string) $reseller['ibsng_isp'], $credit1, $credit2);

        $created = [];
        $failed = [];
        $totalCharged = 0.0;

        foreach ($results as $result) {
            if (!$result->ok) {
                $failed[$result->username] = $result->message ?? 'خطای نامشخص از IBSng';
                continue;
            }

            Database::transaction(function () use ($result, $resellerId, $groupName, $reseller, $price, &$totalCharged) {
                $this->managedUsers->create($result->username, 'reseller', $resellerId, null, $groupName, (string) $reseller['ibsng_isp']);
                $balanceAfter = $this->resellers->adjustBalance($resellerId, -$price);
                $this->ledger->record('reseller', $resellerId, 'debit_purchase', $price, $balanceAfter, $result->username, "ساخت یوزر {$result->username} در گروه {$groupName}");
                $totalCharged += $price;
            });

            $created[] = $result->username;
        }

        $this->auditLog->log('reseller', $resellerId, 'create_users', $groupName, [
            'created' => $created,
            'failed' => $failed,
            'passwords' => array_intersect_key($plainPasswords, array_flip($created)),
        ]);

        return ['created' => $created, 'failed' => $failed, 'total_charged' => $totalCharged, 'passwords' => $plainPasswords];
    }

    public function deleteForReseller(array $reseller, string $username): void
    {
        $resellerId = (int) $reseller['id'];
        if (!$this->managedUsers->ownedByReseller($resellerId, $username)) {
            throw new RuntimeException('این یوزر متعلق به شما نیست یا از طریق این سیستم ساخته نشده است.');
        }

        $ok = $this->gateway->deleteUser($username);
        if (!$ok) {
            throw new RuntimeException('حذف یوزر از IBSng ناموفق بود.');
        }

        $this->managedUsers->delete($username);
        $this->auditLog->log('reseller', $resellerId, 'delete_user', $username);
    }

    public function renewForReseller(array $reseller, string $username): float
    {
        $resellerId = (int) $reseller['id'];
        $managedUser = $this->managedUsers->findByUsername($username);
        if ($managedUser === null || (int) $managedUser['reseller_id'] !== $resellerId) {
            throw new RuntimeException('این یوزر متعلق به شما نیست یا از طریق این سیستم ساخته نشده است.');
        }

        $groupName = (string) $managedUser['group_name'];
        $price = $this->pricing->priceFor($resellerId, $groupName);
        if ($price === null) {
            throw new RuntimeException('این گروه دیگر برای شما فعال نیست؛ برای تمدید با ادمین هماهنگ کنید.');
        }

        $renewCredit1 = (float) Config::get('IBSNG_RENEW_CREDIT1', '100');
        $ok = $this->gateway->renewUser($username, $groupName, $renewCredit1);
        if (!$ok) {
            throw new RuntimeException('تمدید یوزر در IBSng ناموفق بود.');
        }

        $balanceAfter = Database::transaction(function () use ($resellerId, $price, $username, $groupName) {
            $after = $this->resellers->adjustBalance($resellerId, -$price);
            $this->ledger->record('reseller', $resellerId, 'debit_renew', $price, $after, $username, "تمدید یوزر {$username} در گروه {$groupName}");
            return $after;
        });

        $this->auditLog->log('reseller', $resellerId, 'renew_user', $username);

        return $balanceAfter;
    }

    /**
     * Provisions the actual IBSng account for a telegram-direct order right away (see
     * docs/ARCHITECTURE.md for why the account is granted before payment is confirmed).
     */
    public function provisionForOrder(array $order): string
    {
        $groupName = (string) $order['group_name'];
        $isp = (string) Config::get('IBSNG_DIRECT_ISP', '');
        if ($isp === '') {
            throw new RuntimeException('IBSNG_DIRECT_ISP در .env تنظیم نشده است (ISP کانال فروش مستقیم ربات تلگرام).');
        }

        if ($order['order_type'] === 'renew') {
            $username = (string) $order['target_username'];
            $managedUser = $this->managedUsers->findByUsername($username);
            if ($managedUser === null || (int) $managedUser['telegram_customer_id'] !== (int) $order['telegram_customer_id']) {
                throw new RuntimeException('یوزر انتخاب‌شده برای تمدید متعلق به این مشتری نیست.');
            }
            $renewCredit1 = (float) Config::get('IBSNG_RENEW_CREDIT1', '100');
            if (!$this->gateway->renewUser($username, $groupName, $renewCredit1)) {
                throw new RuntimeException('تمدید یوزر در IBSng ناموفق بود.');
            }
            return $username;
        }

        $username = 'tg' . $order['id'];
        $password = PasswordGenerator::fourDigit();
        $credit1 = (float) Config::get('IBSNG_DEFAULT_CREDIT1', '100');
        $credit2 = (float) Config::get('IBSNG_DEFAULT_CREDIT2', '0');

        $results = $this->gateway->createUsers([new CreateUserItem($username, $password)], $groupName, $isp, $credit1, $credit2);
        $result = $results[0] ?? null;
        if ($result === null || !$result->ok) {
            throw new RuntimeException('ساخت یوزر در IBSng ناموفق بود: ' . ($result->message ?? 'نامشخص'));
        }

        $this->managedUsers->create($username, 'direct', null, (int) $order['telegram_customer_id'], $groupName, $isp);

        return $username;
    }
}

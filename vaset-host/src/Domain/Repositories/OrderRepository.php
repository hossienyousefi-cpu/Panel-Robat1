<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

use App\Core\Database;

final class OrderRepository
{
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(
        string $orderType,
        int $telegramCustomerId,
        string $groupName,
        ?string $targetUsername,
        float $price
    ): int {
        $stmt = Database::connection()->prepare(
            'INSERT INTO orders (order_type, telegram_customer_id, group_name, target_username, price)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$orderType, $telegramCustomerId, $groupName, $targetUsername, $price]);
        return (int) Database::connection()->lastInsertId();
    }

    public function setProvisionedUsername(int $orderId, string $username): void
    {
        $stmt = Database::connection()->prepare('UPDATE orders SET provisioned_username = ? WHERE id = ?');
        $stmt->execute([$username, $orderId]);
    }

    public function setStatus(int $orderId, string $status, ?int $adminId = null): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE orders SET status = ?, decided_at = NOW(), decided_by = ? WHERE id = ?'
        );
        $stmt->execute([$status, $adminId, $orderId]);
    }

    public function markUnderReview(int $orderId): void
    {
        $stmt = Database::connection()->prepare("UPDATE orders SET status = 'under_review' WHERE id = ?");
        $stmt->execute([$orderId]);
    }

    /** @return array<int,array<string,mixed>> orders unpaid for more than $hours hours, not yet notified */
    public function overdueUnpaid(int $hours): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM orders
             WHERE status IN ('pending_payment', 'under_review')
             AND paid_notified_overdue = 0
             AND created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)"
        );
        $stmt->execute([$hours]);
        return $stmt->fetchAll();
    }

    public function markOverdueNotified(int $orderId): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE orders SET paid_notified_overdue = 1, status = 'expired_unpaid' WHERE id = ?"
        );
        $stmt->execute([$orderId]);
    }

    /** @return array<int,array<string,mixed>> */
    public function forTelegramCustomer(int $telegramCustomerId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM orders WHERE telegram_customer_id = ? ORDER BY created_at DESC');
        $stmt->execute([$telegramCustomerId]);
        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function pendingReview(): array
    {
        return Database::connection()->query(
            "SELECT o.*, tc.tg_username, tc.first_name, tc.chat_id FROM orders o
             JOIN telegram_customers tc ON tc.id = o.telegram_customer_id
             WHERE o.status = 'under_review' ORDER BY o.created_at ASC"
        )->fetchAll();
    }
}

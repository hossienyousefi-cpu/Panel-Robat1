<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

use App\Core\Database;

final class ReceiptRepository
{
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM receipts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createForOrder(int $orderId, float $amount, ?string $trackingCode, string $imagePath): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO receipts (order_id, amount, tracking_code, image_path) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$orderId, $amount, $trackingCode, $imagePath]);
        return (int) Database::connection()->lastInsertId();
    }

    public function createForReseller(int $resellerId, float $amount, ?string $trackingCode, string $imagePath): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO receipts (reseller_id, amount, tracking_code, image_path) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$resellerId, $amount, $trackingCode, $imagePath]);
        return (int) Database::connection()->lastInsertId();
    }

    public function setStatus(int $id, string $status, int $adminId, ?string $note): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE receipts SET status = ?, admin_note = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?'
        );
        $stmt->execute([$status, $note, $adminId, $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function pending(): array
    {
        return Database::connection()->query(
            "SELECT r.*, res.username AS reseller_username, tc.tg_username, tc.first_name
             FROM receipts r
             LEFT JOIN resellers res ON res.id = r.reseller_id
             LEFT JOIN orders o ON o.id = r.order_id
             LEFT JOIN telegram_customers tc ON tc.id = o.telegram_customer_id
             WHERE r.status = 'pending' ORDER BY r.created_at ASC"
        )->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function forReseller(int $resellerId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM receipts WHERE reseller_id = ? ORDER BY created_at DESC');
        $stmt->execute([$resellerId]);
        return $stmt->fetchAll();
    }
}

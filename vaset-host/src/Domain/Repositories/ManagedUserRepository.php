<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

use App\Core\Database;

/**
 * The single source of truth for "users created by this system". Admin/reseller panels
 * must always read through this repository, never from IBSng's own full user list, so
 * pre-existing IBSng accounts never leak into the new panels.
 */
final class ManagedUserRepository
{
    public function findByUsername(string $username): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM managed_users WHERE ibsng_username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(
        string $username,
        string $ownerType,
        ?int $resellerId,
        ?int $telegramCustomerId,
        string $group,
        string $isp
    ): int {
        $stmt = Database::connection()->prepare(
            'INSERT INTO managed_users (ibsng_username, owner_type, reseller_id, telegram_customer_id, group_name, isp)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$username, $ownerType, $resellerId, $telegramCustomerId, $group, $isp]);
        return (int) Database::connection()->lastInsertId();
    }

    public function delete(string $username): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM managed_users WHERE ibsng_username = ?');
        $stmt->execute([$username]);
    }

    public function setLocked(string $username, bool $locked): void
    {
        $stmt = Database::connection()->prepare('UPDATE managed_users SET is_locked = ? WHERE ibsng_username = ?');
        $stmt->execute([$locked ? 1 : 0, $username]);
    }

    public function updateExpiry(string $username, ?string $expiresAt): void
    {
        $stmt = Database::connection()->prepare('UPDATE managed_users SET expires_at = ? WHERE ibsng_username = ?');
        $stmt->execute([$expiresAt, $username]);
    }

    /** @return array<int,array<string,mixed>> users owned by this reseller only */
    public function forReseller(int $resellerId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM managed_users WHERE reseller_id = ? ORDER BY created_at DESC');
        $stmt->execute([$resellerId]);
        return $stmt->fetchAll();
    }

    public function ownedByReseller(int $resellerId, string $username): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM managed_users WHERE reseller_id = ? AND ibsng_username = ?');
        $stmt->execute([$resellerId, $username]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return array<int,array<string,mixed>> */
    public function forTelegramCustomer(int $telegramCustomerId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM managed_users WHERE telegram_customer_id = ? ORDER BY created_at DESC');
        $stmt->execute([$telegramCustomerId]);
        return $stmt->fetchAll();
    }

    public function countForReseller(int $resellerId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM managed_users WHERE reseller_id = ?');
        $stmt->execute([$resellerId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<int,array<string,mixed>> users expiring within $days days, across all resellers */
    public function expiringWithinDays(int $days, ?int $resellerId = null): array
    {
        $sql = 'SELECT * FROM managed_users WHERE expires_at IS NOT NULL
                AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? DAY)';
        $params = [$days];
        if ($resellerId !== null) {
            $sql .= ' AND reseller_id = ?';
            $params[] = $resellerId;
        }
        $sql .= ' ORDER BY expires_at ASC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,int> counts grouped by reseller_id for the admin dashboard */
    public function countsByReseller(): array
    {
        $rows = Database::connection()->query(
            'SELECT reseller_id, COUNT(*) AS cnt FROM managed_users WHERE reseller_id IS NOT NULL GROUP BY reseller_id'
        )->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['reseller_id']] = (int) $row['cnt'];
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> all system-created users (admin overview) */
    public function all(int $limit = 200): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM managed_users ORDER BY created_at DESC LIMIT ?');
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

use App\Core\Database;

final class ResellerRepository
{
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM resellers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM resellers WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return Database::connection()->query('SELECT * FROM resellers ORDER BY id DESC')->fetchAll();
    }

    public function create(string $username, string $password, string $fullName, string $phone, string $isp): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO resellers (username, password_hash, full_name, phone, ibsng_isp) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $phone, $isp]);
        return (int) Database::connection()->lastInsertId();
    }

    public function update(int $id, string $fullName, string $phone, string $isp, bool $isActive): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE resellers SET full_name = ?, phone = ?, ibsng_isp = ?, is_active = ? WHERE id = ?'
        );
        $stmt->execute([$fullName, $phone, $isp, $isActive ? 1 : 0, $id]);
    }

    public function resetPassword(int $id, string $password): void
    {
        $stmt = Database::connection()->prepare('UPDATE resellers SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    /**
     * Adjust balance by a signed delta and return the new balance. Must run inside the
     * caller's transaction together with the matching ledger_entries insert.
     */
    public function adjustBalance(int $id, float $delta): float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE resellers SET balance = balance + ? WHERE id = ?');
        $stmt->execute([$delta, $id]);
        $stmt = $pdo->prepare('SELECT balance FROM resellers WHERE id = ?');
        $stmt->execute([$id]);
        return (float) $stmt->fetchColumn();
    }
}

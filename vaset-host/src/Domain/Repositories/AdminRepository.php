<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

use App\Core\Database;

final class AdminRepository
{
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM admins WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM admins WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return Database::connection()->query('SELECT id, username, full_name, telegram_chat_id, is_active FROM admins ORDER BY id')->fetchAll();
    }

    /** @return array<int,array<string,mixed>> admins that have a telegram_chat_id to notify */
    public function allWithTelegram(): array
    {
        return Database::connection()->query('SELECT id, telegram_chat_id FROM admins WHERE telegram_chat_id IS NOT NULL AND is_active = 1')->fetchAll();
    }

    public function create(string $username, string $password, string $fullName): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO admins (username, password_hash, full_name) VALUES (?, ?, ?)'
        );
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName]);
        return (int) Database::connection()->lastInsertId();
    }

    public function setTelegramChatId(int $adminId, int $chatId): void
    {
        $stmt = Database::connection()->prepare('UPDATE admins SET telegram_chat_id = ? WHERE id = ?');
        $stmt->execute([$chatId, $adminId]);
    }

    public function findByTelegramChatId(int $chatId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM admins WHERE telegram_chat_id = ? AND is_active = 1');
        $stmt->execute([$chatId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function setConversationState(int $adminId, ?string $state, ?array $payload = null): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE admins SET conversation_state = ?, conversation_payload = ? WHERE id = ?'
        );
        $stmt->execute([$state, $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE), $adminId]);
    }
}

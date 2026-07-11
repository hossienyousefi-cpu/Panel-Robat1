<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

use App\Core\Database;

final class TelegramCustomerRepository
{
    public function findByChatId(int $chatId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM telegram_customers WHERE chat_id = ?');
        $stmt->execute([$chatId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM telegram_customers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findOrCreate(int $chatId, ?string $firstName, ?string $username): array
    {
        $existing = $this->findByChatId($chatId);
        if ($existing !== null) {
            return $existing;
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO telegram_customers (chat_id, first_name, tg_username) VALUES (?, ?, ?)'
        );
        $stmt->execute([$chatId, $firstName, $username]);
        return $this->find((int) Database::connection()->lastInsertId());
    }

    public function setConversationState(int $id, ?string $state, ?array $payload = null): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE telegram_customers SET conversation_state = ?, conversation_payload = ? WHERE id = ?'
        );
        $stmt->execute([$state, $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE), $id]);
    }

    public function adjustBalance(int $id, float $delta): float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE telegram_customers SET balance = balance + ? WHERE id = ?');
        $stmt->execute([$delta, $id]);
        $stmt = $pdo->prepare('SELECT balance FROM telegram_customers WHERE id = ?');
        $stmt->execute([$id]);
        return (float) $stmt->fetchColumn();
    }

    public function setPhone(int $id, string $phone): void
    {
        $stmt = Database::connection()->prepare('UPDATE telegram_customers SET phone = ? WHERE id = ?');
        $stmt->execute([$phone, $id]);
    }
}

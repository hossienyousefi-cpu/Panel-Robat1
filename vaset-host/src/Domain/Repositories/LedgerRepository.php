<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

use App\Core\Database;

final class LedgerRepository
{
    public function record(
        string $accountType,
        int $accountId,
        string $entryType,
        float $amount,
        float $balanceAfter,
        ?string $reference,
        ?string $note
    ): int {
        $stmt = Database::connection()->prepare(
            'INSERT INTO ledger_entries (account_type, account_id, entry_type, amount, balance_after, reference, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$accountType, $accountId, $entryType, $amount, $balanceAfter, $reference, $note]);
        return (int) Database::connection()->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> */
    public function forAccount(string $accountType, int $accountId, int $limit = 100): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ledger_entries WHERE account_type = ? AND account_id = ? ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $accountType);
        $stmt->bindValue(2, $accountId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

use App\Core\Database;
use App\IBSng\Dto\OnlineSession;

/** Local cache of IBSng online sessions, refreshed periodically by cron/sync_online_sessions.php. */
final class OnlineSessionRepository
{
    /** @param OnlineSession[] $sessions */
    public function replaceAll(array $sessions): void
    {
        Database::transaction(function () use ($sessions) {
            $pdo = Database::connection();
            $pdo->exec('TRUNCATE TABLE online_sessions_cache');
            $stmt = $pdo->prepare(
                'INSERT INTO online_sessions_cache (username, nas_ip, framed_ip, session_start) VALUES (?, ?, ?, ?)'
            );
            foreach ($sessions as $session) {
                $stmt->execute([$session->username, $session->nasIp, $session->framedIp, $session->startedAt]);
            }
        });
    }

    public function isOnline(string $username): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM online_sessions_cache WHERE username = ?');
        $stmt->execute([$username]);
        return (bool) $stmt->fetchColumn();
    }

    public function countForReseller(int $resellerId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM online_sessions_cache osc
             JOIN managed_users mu ON mu.ibsng_username = osc.username
             WHERE mu.reseller_id = ?'
        );
        $stmt->execute([$resellerId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int> username-count map grouped by reseller_id */
    public function countsByReseller(): array
    {
        $rows = Database::connection()->query(
            'SELECT mu.reseller_id, COUNT(*) AS cnt FROM online_sessions_cache osc
             JOIN managed_users mu ON mu.ibsng_username = osc.username
             WHERE mu.reseller_id IS NOT NULL GROUP BY mu.reseller_id'
        )->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['reseller_id']] = (int) $row['cnt'];
        }
        return $result;
    }

    public function totalCount(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM online_sessions_cache')->fetchColumn();
    }
}

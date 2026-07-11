<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

use App\Core\Database;

final class AuditLogRepository
{
    public function log(string $actorType, ?int $actorId, string $action, ?string $target = null, ?array $meta = null): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO audit_log (actor_type, actor_id, action, target, meta) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $actorType,
            $actorId,
            $action,
            $target,
            $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 100): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT ?');
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

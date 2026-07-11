<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

use App\Core\Database;

/** Key-value store for runtime-editable settings (system_settings table). */
final class SettingsRepository
{
    public function get(string $key): ?string
    {
        $stmt = Database::connection()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function set(string $key, ?string $value): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([$key, $value]);
    }

    /** @return array<string,?string> */
    public function all(): array
    {
        $rows = Database::connection()->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['setting_key']] = $row['setting_value'];
        }
        return $result;
    }
}

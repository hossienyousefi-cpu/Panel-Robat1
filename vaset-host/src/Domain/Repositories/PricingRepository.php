<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

use App\Core\Database;

final class PricingRepository
{
    /** @return array<int,array<string,mixed>> visible groups + price for one reseller */
    public function forReseller(int $resellerId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT group_name, price, is_visible FROM reseller_group_pricing WHERE reseller_id = ? AND is_visible = 1 ORDER BY group_name'
        );
        $stmt->execute([$resellerId]);
        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> every row (visible or not) for the admin pricing matrix */
    public function allForReseller(int $resellerId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT group_name, price, is_visible FROM reseller_group_pricing WHERE reseller_id = ? ORDER BY group_name'
        );
        $stmt->execute([$resellerId]);
        return $stmt->fetchAll();
    }

    public function priceFor(int $resellerId, string $groupName): ?float
    {
        $stmt = Database::connection()->prepare(
            'SELECT price FROM reseller_group_pricing WHERE reseller_id = ? AND group_name = ? AND is_visible = 1'
        );
        $stmt->execute([$resellerId, $groupName]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (float) $value;
    }

    public function upsert(int $resellerId, string $groupName, float $price, bool $visible): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO reseller_group_pricing (reseller_id, group_name, price, is_visible) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE price = VALUES(price), is_visible = VALUES(is_visible)'
        );
        $stmt->execute([$resellerId, $groupName, $price, $visible ? 1 : 0]);
    }

    /** @return array<int,array<string,mixed>> */
    public function directCatalog(bool $onlyVisible = true): array
    {
        $sql = 'SELECT * FROM direct_catalog_pricing';
        if ($onlyVisible) {
            $sql .= ' WHERE is_visible = 1';
        }
        $sql .= ' ORDER BY group_name';
        return Database::connection()->query($sql)->fetchAll();
    }

    public function directPriceFor(string $groupName): ?float
    {
        $stmt = Database::connection()->prepare(
            'SELECT price FROM direct_catalog_pricing WHERE group_name = ? AND is_visible = 1'
        );
        $stmt->execute([$groupName]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (float) $value;
    }

    public function upsertDirect(string $groupName, ?string $displayName, float $price, bool $visible): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO direct_catalog_pricing (group_name, display_name, price, is_visible) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), price = VALUES(price), is_visible = VALUES(is_visible)'
        );
        $stmt->execute([$groupName, $displayName, $price, $visible ? 1 : 0]);
    }

    /** @return array<int,array<string,mixed>> */
    public function cachedGroups(): array
    {
        return Database::connection()->query('SELECT group_name, description FROM ibsng_groups_cache ORDER BY group_name')->fetchAll();
    }

    public function replaceCachedGroups(array $groups): void
    {
        Database::transaction(function () use ($groups) {
            $pdo = Database::connection();
            $pdo->exec('DELETE FROM ibsng_groups_cache');
            $stmt = $pdo->prepare('INSERT INTO ibsng_groups_cache (group_name, description) VALUES (?, ?)');
            foreach ($groups as $group) {
                $stmt->execute([$group['name'], $group['description'] ?? null]);
            }
        });
    }

    /** @return array<int,array<string,mixed>> */
    public function cachedIsps(): array
    {
        return Database::connection()->query('SELECT isp_name FROM ibsng_isps_cache ORDER BY isp_name')->fetchAll();
    }

    public function replaceCachedIsps(array $isps): void
    {
        Database::transaction(function () use ($isps) {
            $pdo = Database::connection();
            $pdo->exec('DELETE FROM ibsng_isps_cache');
            $stmt = $pdo->prepare('INSERT INTO ibsng_isps_cache (isp_name) VALUES (?)');
            foreach ($isps as $isp) {
                $stmt->execute([$isp['name']]);
            }
        });
    }
}

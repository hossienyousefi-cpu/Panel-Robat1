<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Repositories\PricingRepository;
use App\IBSng\IBSngGatewayInterface;

final class PricingService
{
    public function __construct(
        private readonly PricingRepository $pricing = new PricingRepository(),
        private readonly ?IBSngGatewayInterface $gateway = null,
    ) {
    }

    /** Refresh the local groups/ISP cache from the IBSng Agent (used by cron/sync_groups.php and an admin "Refresh" button). */
    public function refreshFromIBSng(IBSngGatewayInterface $gateway): void
    {
        $this->pricing->replaceCachedGroups($gateway->listGroups());
        $this->pricing->replaceCachedIsps($gateway->listIsps());
    }

    public function priceForReseller(int $resellerId, string $groupName): ?float
    {
        return $this->pricing->priceFor($resellerId, $groupName);
    }

    public function priceForDirectCatalog(string $groupName): ?float
    {
        return $this->pricing->directPriceFor($groupName);
    }

    /** @return array<int,array<string,mixed>> */
    public function catalogForReseller(int $resellerId): array
    {
        return $this->pricing->forReseller($resellerId);
    }

    /** @return array<int,array<string,mixed>> */
    public function directCatalog(bool $onlyVisible = true): array
    {
        return $this->pricing->directCatalog($onlyVisible);
    }

    /** @return array<int,array<string,mixed>> */
    public function cachedGroups(): array
    {
        return $this->pricing->cachedGroups();
    }

    /** @return array<int,array<string,mixed>> */
    public function cachedIsps(): array
    {
        return $this->pricing->cachedIsps();
    }

    public function setResellerGroupPrice(int $resellerId, string $groupName, float $price, bool $visible): void
    {
        $this->pricing->upsert($resellerId, $groupName, $price, $visible);
    }

    public function setDirectPrice(string $groupName, ?string $displayName, float $price, bool $visible): void
    {
        $this->pricing->upsertDirect($groupName, $displayName, $price, $visible);
    }
}

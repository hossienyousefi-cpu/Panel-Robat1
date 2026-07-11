<?php

declare(strict_types=1);

namespace App\IBSng;

use App\IBSng\Dto\CreateUserItem;
use App\IBSng\Dto\CreateUserResultItem;
use App\IBSng\Dto\OnlineSession;
use App\IBSng\Dto\UserStatus;

/**
 * Everything the vaset-host application knows about IBSng goes through this interface.
 * The concrete implementation (HttpAgentGateway) talks to the remote IBSng Agent over
 * the SSH tunnel. Swapping the integration technique later (e.g. a future official API)
 * only requires a new implementation of this interface - no caller needs to change.
 */
interface IBSngGatewayInterface
{
    /** @return array<int,array{name:string,description:?string}> */
    public function listGroups(): array;

    /** @return array<int,array{name:string}> */
    public function listIsps(): array;

    /**
     * @param CreateUserItem[] $items
     * @return CreateUserResultItem[]
     */
    public function createUsers(array $items, string $group, string $isp, float $credit1, float $credit2): array;

    /**
     * $ibsngUserId, when known (stored on the managed_users row at creation time),
     * lets a gateway skip an extra username-search round-trip against IBSng.
     */
    public function deleteUser(string $username, ?int $ibsngUserId = null): bool;

    public function renewUser(string $username, string $group, float $addCredit1, ?int $ibsngUserId = null): bool;

    public function lockUser(string $username, ?int $ibsngUserId = null): bool;

    public function unlockUser(string $username, ?int $ibsngUserId = null): bool;

    public function getUserStatus(string $username, ?int $ibsngUserId = null): ?UserStatus;

    /** @return OnlineSession[] */
    public function listOnlineSessions(): array;

    public function healthCheck(): bool;
}

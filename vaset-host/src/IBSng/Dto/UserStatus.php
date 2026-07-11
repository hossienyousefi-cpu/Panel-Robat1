<?php

declare(strict_types=1);

namespace App\IBSng\Dto;

final class UserStatus
{
    public function __construct(
        public readonly string $username,
        public readonly bool $exists,
        public readonly ?string $group = null,
        public readonly ?string $isp = null,
        public readonly ?float $credit1 = null,
        public readonly ?float $credit2 = null,
        public readonly bool $isLocked = false,
        public readonly ?string $expiresAt = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['username'] ?? ''),
            (bool) ($data['exists'] ?? false),
            $data['group'] ?? null,
            $data['isp'] ?? null,
            isset($data['credit1']) ? (float) $data['credit1'] : null,
            isset($data['credit2']) ? (float) $data['credit2'] : null,
            (bool) ($data['is_locked'] ?? false),
            $data['expires_at'] ?? null,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\IBSng\Dto;

final class OnlineSession
{
    public function __construct(
        public readonly string $username,
        public readonly ?string $nasIp,
        public readonly ?string $framedIp,
        public readonly ?string $startedAt,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['username'] ?? ''),
            $data['nas_ip'] ?? null,
            $data['framed_ip'] ?? null,
            $data['session_start'] ?? null,
        );
    }
}

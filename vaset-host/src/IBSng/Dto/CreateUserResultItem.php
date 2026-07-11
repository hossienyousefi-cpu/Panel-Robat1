<?php

declare(strict_types=1);

namespace App\IBSng\Dto;

final class CreateUserResultItem
{
    public function __construct(
        public readonly string $username,
        public readonly bool $ok,
        public readonly ?string $message = null,
    ) {
    }

    /** @param array{username:string,ok:bool,message?:?string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['username'],
            (bool) $data['ok'],
            $data['message'] ?? null,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\IBSng\Dto;

final class CreateUserResultItem
{
    public function __construct(
        public readonly string $username,
        public readonly bool $ok,
        public readonly ?string $message = null,
        public readonly ?int $ibsngUserId = null,
    ) {
    }

    /** @param array{username:string,ok:bool,message?:?string,ibsng_user_id?:?int} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['username'],
            (bool) $data['ok'],
            $data['message'] ?? null,
            isset($data['ibsng_user_id']) ? (int) $data['ibsng_user_id'] : null,
        );
    }
}

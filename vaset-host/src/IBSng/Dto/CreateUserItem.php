<?php

declare(strict_types=1);

namespace App\IBSng\Dto;

final class CreateUserItem
{
    public function __construct(
        public readonly string $username,
        public readonly string $password,
    ) {
    }

    /** @return array{username:string,password:string} */
    public function toArray(): array
    {
        return ['username' => $this->username, 'password' => $this->password];
    }
}

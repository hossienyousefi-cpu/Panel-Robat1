<?php

declare(strict_types=1);

namespace App\Support;

final class PasswordGenerator
{
    /** 4-digit numeric password, as requested for IBSng's per-user auto-generate flow. */
    public static function fourDigit(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

use App\Config;

final class ApiKeyAuth
{
    public static function check(): bool
    {
        $expected = (string) Config::get('api_key', '');
        $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';

        if ($expected === '' || !hash_equals($expected, $provided)) {
            return false;
        }

        $allowedIps = Config::get('allowed_ips', []);
        if ($allowedIps !== [] && !in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowedIps, true)) {
            return false;
        }

        return true;
    }
}

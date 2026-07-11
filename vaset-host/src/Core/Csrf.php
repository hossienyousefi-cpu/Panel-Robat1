<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            Session::set('_csrf', $token);
        }
        return $token;
    }

    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return "<input type=\"hidden\" name=\"_csrf\" value=\"{$token}\">";
    }

    public static function verify(?string $submitted): bool
    {
        $token = Session::get('_csrf');
        return is_string($token) && is_string($submitted) && hash_equals($token, $submitted);
    }

    public static function requireValid(): void
    {
        $submitted = $_POST['_csrf'] ?? null;
        if (!self::verify($submitted)) {
            http_response_code(419);
            echo 'درخواست نامعتبر است (CSRF). صفحه را رفرش کرده و دوباره تلاش کنید.';
            exit;
        }
    }
}

<?php

declare(strict_types=1);

namespace App;

final class Config
{
    private static ?array $data = null;

    public static function load(): array
    {
        if (self::$data === null) {
            $path = dirname(__DIR__) . '/config.php';
            if (!is_file($path)) {
                throw new \RuntimeException('config.php not found. Copy config.php.example to config.php and fill it in.');
            }
            self::$data = require $path;
        }
        return self::$data;
    }

    public static function get(string $dotPath, mixed $default = null): mixed
    {
        $value = self::load();
        foreach (explode('.', $dotPath) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}

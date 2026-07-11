<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

App\Config::load();

date_default_timezone_set('Asia/Tehran');

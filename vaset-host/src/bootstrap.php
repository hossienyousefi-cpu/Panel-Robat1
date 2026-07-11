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

// Must run before any HTML/output is echoed anywhere downstream, otherwise the
// session cookie's Set-Cookie header silently fails to reach the browser on hosts
// without output buffering (some pages only touched the session deep inside their
// HTML template, which broke CSRF validation on the very first page load).
if (PHP_SAPI !== 'cli') {
    App\Core\Session::start();
}

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Config;

/**
 * Temporary diagnostic page for the INSTALL_TOKEN mismatch during first-time setup.
 * Only reveals lengths and short prefixes/suffixes, never full secret values.
 * Delete this file (along with install.php) once setup is confirmed working.
 */
header('Content-Type: text/plain; charset=utf-8');

$envPath = dirname(__DIR__) . '/.env';

echo "env file path: {$envPath}\n";
echo "env file exists: " . (is_file($envPath) ? 'yes' : 'NO') . "\n";
echo "env file readable: " . (is_readable($envPath) ? 'yes' : 'NO') . "\n";
if (is_file($envPath)) {
    echo "env file size (bytes): " . filesize($envPath) . "\n";
    echo "env file permissions: " . substr(sprintf('%o', fileperms($envPath)), -4) . "\n";
}

$token = (string) Config::get('INSTALL_TOKEN', '');
echo "\nINSTALL_TOKEN from .env -> length: " . strlen($token) . "\n";
echo "INSTALL_TOKEN from .env -> first4: " . substr($token, 0, 4) . "\n";
echo "INSTALL_TOKEN from .env -> last4: " . substr($token, -4) . "\n";

$provided = (string) ($_GET['token'] ?? '');
echo "\nGET token param present: " . (isset($_GET['token']) ? 'yes' : 'no') . "\n";
echo "GET token -> length: " . strlen($provided) . "\n";
echo "GET token -> first4: " . substr($provided, 0, 4) . "\n";
echo "GET token -> last4: " . substr($provided, -4) . "\n";

echo "\nMATCH: " . (hash_equals($token, $provided) ? 'YES' : 'NO') . "\n";

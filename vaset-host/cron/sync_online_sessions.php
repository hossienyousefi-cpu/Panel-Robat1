<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Domain\Repositories\OnlineSessionRepository;
use App\IBSng\IBSngGatewayFactory;

$gateway = IBSngGatewayFactory::create();

try {
    $sessions = $gateway->listOnlineSessions();
    (new OnlineSessionRepository())->replaceAll($sessions);
    echo '[' . date('c') . "] synced " . count($sessions) . " online sessions\n";
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] sync_online_sessions failed: ' . $e->getMessage() . "\n");
    exit(1);
}

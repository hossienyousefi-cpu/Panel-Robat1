<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\IBSng\IBSngGatewayFactory;
use App\Services\ExpiryWatcherService;

$gateway = IBSngGatewayFactory::create();
$service = new ExpiryWatcherService($gateway);

try {
    $refreshed = $service->refreshExpiryCache();
    echo '[' . date('c') . "] refreshed expiry/lock status for {$refreshed} users\n";

    $locked = $service->lockAndNotifyOverdueOrders(24);
    echo '[' . date('c') . "] locked+notified {$locked} overdue-unpaid orders\n";
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] expiry_and_overdue_watch failed: ' . $e->getMessage() . "\n");
    exit(1);
}

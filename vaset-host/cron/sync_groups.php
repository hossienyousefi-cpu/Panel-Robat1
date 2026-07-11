<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\IBSng\IBSngGatewayFactory;
use App\Services\PricingService;

$gateway = IBSngGatewayFactory::create();

try {
    (new PricingService())->refreshFromIBSng($gateway);
    echo '[' . date('c') . "] groups/ISPs cache refreshed from IBSng\n";
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] sync_groups failed: ' . $e->getMessage() . "\n");
    exit(1);
}

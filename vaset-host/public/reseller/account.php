<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Domain\Repositories\LedgerRepository;

$reseller = Auth::requireReseller();
$ledger = (new LedgerRepository())->forAccount('reseller', (int) $reseller['id']);

View::render('reseller/account', [
    'pageTitle' => 'حساب من',
    'reseller' => $reseller,
    'ledger' => $ledger,
], 'reseller');

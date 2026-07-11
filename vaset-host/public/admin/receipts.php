<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Domain\Repositories\ReceiptRepository;
use App\Services\ReceiptApprovalService;
use App\Services\TelegramNotifier;

$admin = Auth::requireAdmin();

if (Request::isPost()) {
    Csrf::requireValid();
    $receiptId = Request::postInt('receipt_id');
    $action = Request::postString('action');
    $note = Request::postString('note');

    $service = new ReceiptApprovalService(notifier: new TelegramNotifier());

    try {
        if ($action === 'approve') {
            $service->approve($receiptId, (int) $admin['id'], $note ?: null);
            Session::flash('success', 'فیش تأیید شد.');
        } elseif ($action === 'reject') {
            $service->reject($receiptId, (int) $admin['id'], $note ?: 'رد شد');
            Session::flash('success', 'فیش رد شد.');
        }
    } catch (\Throwable $e) {
        Session::flash('error', $e->getMessage());
    }

    header('Location: /admin/receipts.php');
    exit;
}

$pending = (new ReceiptRepository())->pending();

View::render('admin/receipts', [
    'pageTitle' => 'تأیید فیش‌ها',
    'pending' => $pending,
], 'admin');

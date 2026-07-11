<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Repositories\AuditLogRepository;
use App\Domain\Repositories\LedgerRepository;
use App\Domain\Repositories\OrderRepository;
use App\Domain\Repositories\ReceiptRepository;
use App\Domain\Repositories\ResellerRepository;
use App\Domain\Repositories\TelegramCustomerRepository;
use RuntimeException;

final class ReceiptApprovalService
{
    public function __construct(
        private readonly ReceiptRepository $receipts = new ReceiptRepository(),
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly ResellerRepository $resellers = new ResellerRepository(),
        private readonly TelegramCustomerRepository $telegramCustomers = new TelegramCustomerRepository(),
        private readonly LedgerRepository $ledger = new LedgerRepository(),
        private readonly AuditLogRepository $auditLog = new AuditLogRepository(),
        private readonly ?TelegramNotifier $notifier = null,
    ) {
    }

    public function approve(int $receiptId, int $adminId, ?string $note = null): void
    {
        $receipt = $this->receipts->find($receiptId);
        if ($receipt === null || $receipt['status'] !== 'pending') {
            throw new RuntimeException('این فیش قبلاً بررسی شده یا وجود ندارد.');
        }

        $amount = (float) $receipt['amount'];

        Database::transaction(function () use ($receipt, $receiptId, $adminId, $note, $amount) {
            if ($receipt['reseller_id'] !== null) {
                $resellerId = (int) $receipt['reseller_id'];
                $balanceAfter = $this->resellers->adjustBalance($resellerId, $amount);
                $this->ledger->record('reseller', $resellerId, 'credit_topup', $amount, $balanceAfter, (string) $receiptId, 'شارژ کیف‌پول از طریق فیش تأییدشده');
                $this->auditLog->log('admin', $adminId, 'approve_receipt', (string) $receiptId, ['reseller_id' => $resellerId, 'amount' => $amount]);
            } elseif ($receipt['order_id'] !== null) {
                $order = $this->orders->find((int) $receipt['order_id']);
                if ($order === null) {
                    throw new RuntimeException('سفارش مرتبط با این فیش پیدا نشد.');
                }
                $telegramCustomerId = (int) $order['telegram_customer_id'];
                $balanceAfter = $this->telegramCustomers->adjustBalance($telegramCustomerId, $amount);
                $this->ledger->record('telegram_customer', $telegramCustomerId, 'credit_refund', $amount, $balanceAfter, (string) $receiptId, 'تأیید فیش پرداخت سفارش #' . $order['id']);
                $this->orders->setStatus((int) $order['id'], 'approved', $adminId);
                $this->auditLog->log('admin', $adminId, 'approve_receipt', (string) $receiptId, ['order_id' => $order['id'], 'amount' => $amount]);
            }

            $this->receipts->setStatus($receiptId, 'approved', $adminId, $note);
        });

        $this->notifyCustomerIfApplicable($receiptId, true, $note);
    }

    public function reject(int $receiptId, int $adminId, string $note): void
    {
        $receipt = $this->receipts->find($receiptId);
        if ($receipt === null || $receipt['status'] !== 'pending') {
            throw new RuntimeException('این فیش قبلاً بررسی شده یا وجود ندارد.');
        }

        $this->receipts->setStatus($receiptId, 'rejected', $adminId, $note);
        $this->auditLog->log('admin', $adminId, 'reject_receipt', (string) $receiptId, ['note' => $note]);

        $this->notifyCustomerIfApplicable($receiptId, false, $note);
    }

    private function notifyCustomerIfApplicable(int $receiptId, bool $approved, ?string $note): void
    {
        if ($this->notifier === null) {
            return;
        }
        $receipt = $this->receipts->find($receiptId);
        if ($receipt === null || $receipt['order_id'] === null) {
            return;
        }
        $order = $this->orders->find((int) $receipt['order_id']);
        if ($order === null) {
            return;
        }
        $customer = $this->telegramCustomers->find((int) $order['telegram_customer_id']);
        if ($customer === null) {
            return;
        }

        $message = $approved
            ? "✅ فیش پرداخت شما برای سفارش #{$order['id']} تأیید شد. با تشکر از خرید شما."
            : "❌ فیش پرداخت شما برای سفارش #{$order['id']} رد شد." . ($note ? "\nدلیل: {$note}" : '') . "\nلطفاً فیش صحیح را دوباره ارسال کنید.";

        $this->notifier->sendToChat((int) $customer['chat_id'], $message);
    }
}

<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Domain\Repositories\ReceiptRepository;
use App\Services\TelegramNotifier;

$reseller = Auth::requireReseller();
$resellerId = (int) $reseller['id'];

if (Request::isPost()) {
    Csrf::requireValid();
    $amount = Request::postFloat('amount');
    $trackingCode = Request::postString('tracking_code');
    $file = Request::file('receipt_image');

    if ($amount <= 0) {
        Session::flash('error', 'مبلغ نامعتبر است.');
    } elseif ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
        Session::flash('error', 'آپلود تصویر فیش الزامی است.');
    } else {
        $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, $allowedMime, true) || $file['size'] > 5 * 1024 * 1024) {
            Session::flash('error', 'فایل باید تصویر jpg/png/webp و حداکثر ۵ مگابایت باشد.');
        } else {
            $dir = dirname(__DIR__) . '/uploads/receipts';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
            $filename = 'reseller' . $resellerId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $dir . '/' . $filename);

            (new ReceiptRepository())->createForReseller($resellerId, $amount, $trackingCode ?: null, 'uploads/receipts/' . $filename);
            (new TelegramNotifier())->notifyAllAdmins("💳 فیش شارژ کیف‌پول جدید از Reseller {$reseller['username']} به مبلغ " . number_format($amount) . ' تومان ثبت شد.');

            Session::flash('success', 'فیش شما ثبت شد و در انتظار تأیید ادمین است.');
        }
    }

    header('Location: /reseller/receipts.php');
    exit;
}

$receipts = (new ReceiptRepository())->forReseller($resellerId);

View::render('reseller/receipts', [
    'pageTitle' => 'شارژ کیف‌پول',
    'receipts' => $receipts,
], 'reseller');

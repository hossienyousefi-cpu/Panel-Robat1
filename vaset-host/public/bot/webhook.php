<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Bot\TelegramBot;
use App\Config;

$expectedSecret = Config::get('TELEGRAM_WEBHOOK_SECRET', '');
$providedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';

if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
    http_response_code(403);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$update = json_decode($raw, true);

if (!is_array($update)) {
    http_response_code(400);
    exit;
}

(new TelegramBot())->handleUpdate($update);

http_response_code(200);
echo 'ok';

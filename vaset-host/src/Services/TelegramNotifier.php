<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Domain\Repositories\AdminRepository;

final class TelegramNotifier
{
    private string $apiBase;

    public function __construct(?string $botToken = null)
    {
        $token = $botToken ?? Config::get('TELEGRAM_BOT_TOKEN', '');
        $this->apiBase = "https://api.telegram.org/bot{$token}";
    }

    public function sendToChat(int $chatId, string $text, ?array $replyMarkup = null): bool
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }

        return $this->call('sendMessage', $payload);
    }

    public function sendPhotoFromPath(int $chatId, string $path, string $caption = ''): bool
    {
        $payload = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'photo' => new \CURLFile($path),
        ];
        return $this->call('sendPhoto', $payload, true);
    }

    /** Used for database export dumps (Task 4). Telegram's bot API caps uploads at 50MB. */
    public function sendDocumentFromPath(int $chatId, string $path, string $caption = ''): bool
    {
        $payload = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'document' => new \CURLFile($path),
        ];
        return $this->call('sendDocument', $payload, true, 120);
    }

    public function notifyAllAdmins(string $text): void
    {
        foreach ((new AdminRepository())->allWithTelegram() as $admin) {
            $this->sendToChat((int) $admin['telegram_chat_id'], $text);
        }
    }

    private function call(string $method, array $payload, bool $multipart = false, int $timeoutSeconds = 15): bool
    {
        $ch = curl_init("{$this->apiBase}/{$method}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $multipart ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);
        if (!$multipart) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            error_log('[panel-vaset] Telegram API call failed: ' . $error);
            return false;
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
            error_log('[panel-vaset] Telegram API error: ' . $body);
            return false;
        }

        return true;
    }
}

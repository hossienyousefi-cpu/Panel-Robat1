<?php

declare(strict_types=1);

namespace App\Support;

use App\Config;
use RuntimeException;

/** Shared by the customer bot (receipt photos) and the admin bot control panel (DB import files). */
final class TelegramFileDownloader
{
    public static function download(string $fileId, string $destDir, string $filenamePrefix = 'file'): string
    {
        $token = Config::get('TELEGRAM_BOT_TOKEN', '');
        $meta = json_decode((string) file_get_contents("https://api.telegram.org/bot{$token}/getFile?file_id={$fileId}"), true);
        $filePath = $meta['result']['file_path'] ?? null;
        if ($filePath === null) {
            throw new RuntimeException('دریافت فایل از تلگرام ناموفق بود.');
        }

        $contents = file_get_contents("https://api.telegram.org/file/bot{$token}/{$filePath}");
        if ($contents === false) {
            throw new RuntimeException('دانلود فایل از تلگرام ناموفق بود.');
        }

        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'bin';
        $localName = $filenamePrefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
        $localPath = rtrim($destDir, '/') . '/' . $localName;
        file_put_contents($localPath, $contents);

        return $localPath;
    }
}

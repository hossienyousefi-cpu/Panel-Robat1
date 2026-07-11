<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use RuntimeException;

/**
 * Export/import for the vaset-host system's OWN MySQL database (resellers, pricing,
 * receipts, ledger, managed_users cache, ...) - used by the admin Telegram control
 * panel (Task 4). This intentionally never touches IBSng's own database; IBSng is
 * only ever driven through the Agent's admin-panel automation (see ibsng-agent/),
 * consistent with the rest of this system's architecture. Back up IBSng itself with
 * whatever process you already use on that server.
 */
final class DatabaseBackupService
{
    private string $backupDir;

    public function __construct(?string $backupDir = null)
    {
        $this->backupDir = $backupDir ?? dirname(__DIR__, 2) . '/storage/backups';
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0750, true);
        }
    }

    /** Dumps the current DB to a gzip-compressed .sql.gz file and returns its path. */
    public function export(): string
    {
        $dumpPath = $this->backupDir . '/panel_vaset_' . date('Ymd_His') . '.sql.gz';
        $credentialsFile = $this->writeCredentialsFile();
        $tempSql = $this->backupDir . '/.tmp_' . bin2hex(random_bytes(6)) . '.sql';
        $errFile = sys_get_temp_dir() . '/panel-vaset-dumperr-' . bin2hex(random_bytes(6)) . '.log';

        try {
            $mysqldump = Config::get('MYSQLDUMP_PATH', 'mysqldump');
            $dbName = (string) Config::get('DB_NAME', 'panel_vaset');

            // Dump to a plain file first (stdout/stderr kept in separate streams), then
            // gzip it as a second step - piping mysqldump straight into gzip would risk
            // mixing stderr output into the compressed dump if redirection ever changed.
            $cmd = sprintf(
                '%s --defaults-extra-file=%s --single-transaction --routines --triggers %s > %s 2> %s',
                escapeshellcmd($mysqldump),
                escapeshellarg($credentialsFile),
                escapeshellarg($dbName),
                escapeshellarg($tempSql),
                escapeshellarg($errFile)
            );

            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0 || !is_file($tempSql) || filesize($tempSql) === 0) {
                $error = is_file($errFile) ? file_get_contents($errFile) : 'unknown error';
                throw new RuntimeException('mysqldump failed: ' . $error);
            }

            $in = fopen($tempSql, 'rb');
            $out = gzopen($dumpPath, 'wb9');
            while (!feof($in)) {
                gzwrite($out, fread($in, 1024 * 1024));
            }
            fclose($in);
            gzclose($out);

            return $dumpPath;
        } finally {
            @unlink($credentialsFile);
            @unlink($tempSql);
            @unlink($errFile);
        }
    }

    /**
     * Restores the DB from a .sql or .sql.gz file. A safety export() of the current
     * state is taken first and its path is always returned alongside the result, so a
     * failed/unwanted import can be undone by importing that safety file back.
     *
     * @return array{safety_backup:string}
     */
    public function import(string $sqlFilePath): array
    {
        if (!is_file($sqlFilePath)) {
            throw new RuntimeException('فایل import پیدا نشد.');
        }

        $safetyBackup = $this->export();

        $plainSqlPath = $sqlFilePath;
        $decompressedTemp = null;
        if (str_ends_with(strtolower($sqlFilePath), '.gz')) {
            $decompressedTemp = $this->backupDir . '/import_' . bin2hex(random_bytes(6)) . '.sql';
            $this->gunzip($sqlFilePath, $decompressedTemp);
            $plainSqlPath = $decompressedTemp;
        }

        $credentialsFile = $this->writeCredentialsFile();

        try {
            $mysql = Config::get('MYSQL_CLI_PATH', 'mysql');
            $dbName = (string) Config::get('DB_NAME', 'panel_vaset');

            $cmd = sprintf(
                '%s --defaults-extra-file=%s %s < %s 2>&1',
                escapeshellcmd($mysql),
                escapeshellarg($credentialsFile),
                escapeshellarg($dbName),
                escapeshellarg($plainSqlPath)
            );

            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    "Import ناموفق بود (خروجی mysql):\n" . implode("\n", $output) .
                    "\n\nیک بکاپ امن از وضعیت قبل از این تلاش در {$safetyBackup} ذخیره شده است."
                );
            }

            return ['safety_backup' => $safetyBackup];
        } finally {
            @unlink($credentialsFile);
            if ($decompressedTemp !== null) {
                @unlink($decompressedTemp);
            }
        }
    }

    private function gunzip(string $gzPath, string $destPath): void
    {
        $in = gzopen($gzPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('باز کردن فایل gz ناموفق بود.');
        }
        $out = fopen($destPath, 'wb');
        while (!gzeof($in)) {
            fwrite($out, gzread($in, 1024 * 1024));
        }
        gzclose($in);
        fclose($out);
    }

    /** Writes DB credentials to a 0600 temp file so they never appear in `ps aux` output. */
    private function writeCredentialsFile(): string
    {
        $path = sys_get_temp_dir() . '/panel-vaset-db-' . bin2hex(random_bytes(8)) . '.cnf';
        $contents = sprintf(
            "[client]\nhost=%s\nuser=%s\npassword=%s\n",
            Config::get('DB_HOST', '127.0.0.1'),
            Config::get('DB_USER', 'panel_vaset'),
            Config::get('DB_PASS', '')
        );
        file_put_contents($path, $contents);
        chmod($path, 0600);
        return $path;
    }
}

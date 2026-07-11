<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Read-only access to the standard FreeRADIUS `radacct` table that IBSng relies on
 * for accounting. This schema is stable across FreeRADIUS/IBSng installs, so unlike
 * IBSngAdminClient this needs no per-install verification beyond the DB credentials.
 */
final class RadAcctReader
{
    private ?PDO $pdo = null;

    private function connection(): PDO
    {
        if ($this->pdo === null) {
            $cfg = Config::get('radacct');
            $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return $this->pdo;
    }

    /** @return array<int,array{username:string,nas_ip:?string,framed_ip:?string,session_start:?string}> */
    public function onlineSessions(): array
    {
        $table = Config::get('radacct.table', 'radacct');
        $sql = "SELECT username, nasipaddress AS nas_ip, framedipaddress AS framed_ip, acctstarttime AS session_start
                FROM {$table} WHERE acctstoptime IS NULL";

        $stmt = $this->connection()->query($sql);
        return $stmt->fetchAll();
    }
}

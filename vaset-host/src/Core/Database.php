<?php

declare(strict_types=1);

namespace App\Core;

use App\Config;
use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $host = Config::get('DB_HOST', '127.0.0.1');
            $name = Config::get('DB_NAME', 'panel_vaset');
            $user = Config::get('DB_USER', 'panel_vaset');
            $pass = Config::get('DB_PASS', '');
            $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

            try {
                self::$connection = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                error_log('[panel-vaset] DB connection failed: ' . $e->getMessage());
                throw $e;
            }
        }

        return self::$connection;
    }

    /**
     * Run $callback inside a transaction, rolling back on any exception.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function transaction(callable $callback)
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

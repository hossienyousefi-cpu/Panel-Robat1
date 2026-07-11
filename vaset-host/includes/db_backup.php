<?php
// Export/Import کامل دیتابیس این سیستم به‌صورت PHP خالص (بدون نیاز به mysqldump/شل)
// چون هاست‌های cPanel معمولاً shell_exec را غیرفعال می‌کنند و طبق خواسته کارفرما نباید
// روی این هاست هم به Terminal نیاز باشد.

// ─── Export: کل جدول‌ها به یک فایل .sql ───
function db_export_to_file(PDO $pdo, string $destPath): bool {
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $fp = fopen($destPath, 'w');
    if (!$fp) return false;

    fwrite($fp, "-- Export دیتابیس پنل - " . date('Y-m-d H:i:s') . "\n");
    fwrite($fp, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    foreach ($tables as $table) {
        $createRow = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $createSql = $createRow['Create Table'] ?? null;
        if ($createSql === null) continue;

        fwrite($fp, "DROP TABLE IF EXISTS `$table`;\n");
        fwrite($fp, $createSql . ";\n\n");

        $stmt = $pdo->query("SELECT * FROM `$table`");
        $cols = null;
        $batch = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($cols === null) $cols = array_keys($row);
            $vals = array_map(function ($v) use ($pdo) {
                return $v === null ? 'NULL' : $pdo->quote((string)$v);
            }, $row);
            $batch[] = '(' . implode(',', $vals) . ')';
            if (count($batch) >= 200) {
                fwrite($fp, "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            fwrite($fp, "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        fwrite($fp, "\n");
    }

    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);
    return true;
}

// ─── تقسیم فایل sql به دستورهای مجزا، با احترام به رشته‌های ' و " و کاراکترهای \escape ───
function db_split_sql_statements(string $sql): array {
    $statements = [];
    $buffer = '';
    $inSingle = false;
    $inDouble = false;
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $buffer .= $ch;

        if ($inSingle) {
            if ($ch === '\\' && $i + 1 < $len) { $buffer .= $sql[++$i]; continue; }
            if ($ch === "'") { $inSingle = false; }
            continue;
        }
        if ($inDouble) {
            if ($ch === '\\' && $i + 1 < $len) { $buffer .= $sql[++$i]; continue; }
            if ($ch === '"') { $inDouble = false; }
            continue;
        }
        if ($ch === "'") { $inSingle = true; continue; }
        if ($ch === '"') { $inDouble = true; continue; }
        if ($ch === ';') {
            $statements[] = substr($buffer, 0, -1);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') $statements[] = $buffer;
    return $statements;
}

// ─── Import: اجرای فایل .sql روی همین دیتابیس (جایگزین کامل جدول‌های موجود در فایل) ───
function db_import_from_file(PDO $pdo, string $sqlFilePath): array {
    $sql = @file_get_contents($sqlFilePath);
    if ($sql === false) return ['ok' => false, 'error' => 'فایل خوانده نشد'];

    $statements = db_split_sql_statements($sql);
    $executed = 0;
    $failed = 0;
    $errors = [];

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || str_starts_with($stmt, '--')) continue;
        try {
            $pdo->exec($stmt);
            $executed++;
        } catch (PDOException $e) {
            $failed++;
            if (count($errors) < 10) $errors[] = $e->getMessage();
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    return ['ok' => true, 'executed' => $executed, 'failed' => $failed, 'errors' => $errors];
}

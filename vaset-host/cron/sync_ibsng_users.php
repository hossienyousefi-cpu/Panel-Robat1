<?php
// این اسکریپت با کرون (cPanel → Cron Jobs) هر ۵ دقیقه اجرا می‌شود و همه‌ی کاربرهای
// IBSng (تمام ISPها) را در جدول محلی ibsng_users_cache به‌روز می‌کند. صفحات سرچ
// کاربران (admin/users.php و reseller/users.php) اگر این جدول تازه باشد، به‌جای
// زدن مستقیم و مکرر به IBSng، فقط از همین جدول محلی (سریع + بدون فشار روی IBSng)
// می‌خوانند. قبل از فعال‌شدن باید migrations/004_ibsng_users_cache.sql را از
// phpMyAdmin اجرا کرده باشید.
//
// نمونه‌ی خط کرون (مسیر php و مسیر پروژه را با مسیر واقعی هاست خودتان جایگزین کنید):
//   */5 * * * * /usr/local/bin/php /home/USERNAME/public_html/cron/sync_ibsng_users.php >/dev/null 2>&1

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('این اسکریپت فقط از طریق کرون (CLI) قابل اجراست.');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ibsng_api.php';

// جلوگیری از اجرای هم‌زمان دو نمونه از این اسکریپت (اگر یک اجرا بیشتر از ۵ دقیقه طول
// بکشد و اجرای بعدی کرون هنوز تمام‌نشده شروع بشه)
$lockFile = IBS_CACHE_DIR . 'sync_ibsng_users.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 600) {
    error_log('[sync_ibsng_users] اجرای قبلی هنوز در حال انجام است، رد شد.');
    exit(0);
}
@file_put_contents($lockFile, (string)time());
register_shutdown_function(function () use ($lockFile) { @unlink($lockFile); });

$startedAt = microtime(true);
$isps = ibsng_getIsps();
$totalSynced = 0;

$stmt = $pdo->prepare("INSERT INTO ibsng_users_cache
    (uid, username, password, status, group_name, isp_name, ras_ip, credit, exp_date, exp_ts, updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
    ON DUPLICATE KEY UPDATE
        username=VALUES(username), password=VALUES(password), status=VALUES(status),
        group_name=VALUES(group_name), isp_name=VALUES(isp_name), ras_ip=VALUES(ras_ip),
        credit=VALUES(credit), exp_date=VALUES(exp_date), exp_ts=VALUES(exp_ts), updated_at=NOW()");

foreach ($isps as $ispName) {
    $conds = ibsng_ispCond($ispName);
    // $throttled=true: دسته‌دسته + retry به‌جای فرستادن ده‌ها تماس هم‌زمان -
    // برای کرون که فشار کم روی IBSng از سرعت مهم‌تره.
    $uids = ibsng_getAllUidsForIsp($conds, true);
    if (empty($uids)) continue;

    $infos = ibsng_getUserInfoBulk($uids, true);
    $seenUids = [];

    foreach ($uids as $uid) {
        $u = $infos[(string)$uid] ?? $infos[$uid] ?? null;
        if (!$u) continue;
        $row = ibsng_uidToRow($uid, $u, $ispName, []);
        $stmt->execute([
            (string)$uid, $row['username'], $row['password'], $row['status'],
            $row['group'], $row['isp'] ?: $ispName, $row['ras'],
            (float)($u['basic_info']['credit'] ?? 0),
            $row['exp'] === '∞' ? '' : $row['exp'], (int)$row['exp_ts'],
        ]);
        $seenUids[] = (string)$uid;
        $totalSynced++;
    }

    $missing = count($uids) - count($seenUids);
    if ($missing > 0) {
        error_log("[sync_ibsng_users] {$ispName}: {$missing} کاربر با وجود retry از IBSng جواب نگرفت - این دور، ردیف‌های قدیمی این ISP پاک نمی‌شن (برای جلوگیری از حذف اشتباه).");
    } elseif (!empty($seenUids)) {
        // فقط وقتی مطمئنیم لیست کامله (هیچ uid ای جا نمونده)، کاربرهایی که دیگه
        // توی این ISP نیستند (حذف‌شده/منتقل‌شده) رو از کش پاک می‌کنیم
        $placeholders = implode(',', array_fill(0, count($seenUids), '?'));
        $del = $pdo->prepare("DELETE FROM ibsng_users_cache WHERE isp_name = ? AND uid NOT IN ($placeholders)");
        $del->execute(array_merge([$ispName], $seenUids));
    }

    error_log("[sync_ibsng_users] {$ispName}: " . count($seenUids) . "/" . count($uids) . " کاربر سینک شد.");

    // یک مکث کوتاه بین ISPها تا فشار روی IBSng یک‌جا و پشت‌سرهم نباشه
    usleep(300000);
}

$elapsed = round(microtime(true) - $startedAt, 1);
error_log("[sync_ibsng_users] {$totalSynced} کاربر در " . count($isps) . " ISP طی {$elapsed} ثانیه سینک شد.");

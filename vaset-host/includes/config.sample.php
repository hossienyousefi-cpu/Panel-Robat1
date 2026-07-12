<?php
// ===== جلوگیری از دسترسی مستقیم =====
if (!defined('IBS_PANEL')) {
    define('IBS_PANEL', true);
}

// ===== تنظیمات پایگاه داده =====
// این مقادیر را از cPanel > MySQL Databases بردارید
define('DB_HOST', 'localhost');
define('DB_NAME', 'CHANGE_ME_DB_NAME');
define('DB_USER', 'CHANGE_ME_DB_USER');
define('DB_PASS', 'CHANGE_ME_DB_PASS');

// ===== تنظیمات اولیه IBSng (فقط پیش‌فرض اولیه؛ بعد از نصب از admin/settings.php و =====
// ===== admin/telegram.php قابل تغییرند و آن مقدار در دیتابیس اولویت دارد) =====
define('IBS_URL',   'http://IP-سرور-IBSng/IBSng/admin');
define('IBS_ADMIN', 'CHANGE_ME');
define('IBS_PASS',  'CHANGE_ME');

// ===== تنظیمات جلسه =====
define('SESSION_LIFETIME', 3600 * 8); // 8 ساعت
define('SITE_NAME', 'IBSng Panel');
define('BCRYPT_COST', 12);

// ===== پیکربندی جلسه امن =====
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    session_start();
}

// ===== اتصال PDO =====
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]
    );
} catch (PDOException $e) {
    // لاگ خطا بدون نمایش جزئیات
    error_log('DB Error: ' . $e->getMessage());
    http_response_code(503);
    die('{"error":"Service unavailable"}');
}

// ===== بارگذاری تنظیمات =====
function getSetting($key, $default = '') {
    global $pdo;
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    $cache[$key] = $row ? $row['setting_value'] : $default;
    return $cache[$key];
}

// ===== توابع امنیتی =====
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim((string)$input)), ENT_QUOTES, 'UTF-8');
}

// اعتبارسنجی ورودی عددی
function validateInt($val, $min = 0, $max = PHP_INT_MAX) {
    $v = filter_var($val, FILTER_VALIDATE_INT);
    if ($v === false) return $min;
    return max($min, min($max, $v));
}

// بررسی CSRF token
function generateCsrf() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function verifyCsrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ===== احراز هویت =====
function isAdminLoggedIn() {
    if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) return false;
    // بررسی تایم‌اوت جلسه
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

function isResellerLoggedIn() {
    if (!isset($_SESSION['reseller_id']) || empty($_SESSION['reseller_id'])) return false;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

function requireAdmin() {
    if (!isAdminLoggedIn()) {
        header('Location: /admin/login.php');
        exit;
    }
    // جلوگیری از کش شدن صفحات محافظت‌شده
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    // PHP به‌طور پیش‌فرض فایل سشن را برای کل عمر یک درخواست قفل می‌کند؛ صفحاتی که
    // چند ثانیه صبر می‌کنند تا با  صحبت کنند (جستجو، ساخت کاربر و ...) باعث می‌شدند
    // بقیه‌ی تب‌ها/درخواست‌های همون کاربر (که سشن یکسان دارند) هم قفل بمونن و کل
    // سایت انگار هنگ کنه. چون بعد از این نقطه هیچ صفحه‌ای چیزی توی $_SESSION
    // نمی‌نویسه، همین‌جا قفل رو آزاد می‌کنیم.
    session_write_close();
}

function requireReseller() {
    if (!isResellerLoggedIn()) {
        header('Location: /reseller/login.php');
        exit;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    session_write_close();
}

// ===== لاگ فعالیت =====
function logActivity($actorType, $actorId, $action, $details = '') {
    global $pdo;
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip = explode(',', $ip)[0]; // اولین IP در صورت proxy
    $ip = filter_var(trim($ip), FILTER_VALIDATE_IP) ? trim($ip) : 'invalid';
    try {
        $pdo->prepare("INSERT INTO activity_logs (actor_type, actor_id, action, details, ip_address) VALUES (?,?,?,?,?)")
            ->execute([$actorType, $actorId, $action, substr($details, 0, 1000), $ip]);
    } catch (Exception $e) {
        error_log('logActivity error: ' . $e->getMessage());
    }
}

// rate limiting ساده برای لاگین
function checkLoginRateLimit($identifier) {
    global $pdo;
    $windowStart = date('Y-m-d H:i:s', time() - 300); // 5 دقیقه
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE actor_type='login_fail' AND details=? AND created_at > ?");
        $stmt->execute([$identifier, $windowStart]);
        return (int)$stmt->fetchColumn() < 10; // حداکثر 10 تلاش
    } catch (Exception $e) {
        return true;
    }
}

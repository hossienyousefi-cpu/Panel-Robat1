<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Session;

/**
 * Temporary diagnostic: proves whether PHP sessions actually persist between a GET
 * and a POST on this host (the root requirement for CSRF protection to work at all).
 * Delete this file once setup is confirmed working.
 */
header('Content-Type: text/html; charset=utf-8');

Session::start();

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$dir = dirname(__DIR__) . '/storage/sessions';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stored = $_SESSION['debug_test'] ?? null;
    $expected = $_POST['expected'] ?? null;
    $result = ($stored !== null && $stored === $expected)
        ? 'MATCH -> session data persisted correctly across requests.'
        : "MISMATCH -> session did NOT persist. stored='" . (string) $stored . "' expected='" . (string) $expected . "'";
} else {
    $_SESSION['debug_test'] = bin2hex(random_bytes(8));
}
$token = $_SESSION['debug_test'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><title>Session Debug</title></head>
<body style="font-family:sans-serif;padding:20px;line-height:2">
<h2>تست پایداری Session</h2>
<pre style="background:#eee;padding:12px;white-space:pre-wrap"><?= e(
    "session_save_path() runtime: " . session_save_path() . "\n" .
    "ini session.save_path default: " . (string) ini_get('session.save_path') . "\n" .
    "session_id(): " . session_id() . "\n" .
    "storage/sessions exists: " . (is_dir($dir) ? 'yes' : 'no') . "\n" .
    "storage/sessions writable: " . (is_dir($dir) && is_writable($dir) ? 'yes' : 'no') . "\n" .
    "cookies received: " . json_encode($_COOKIE) . "\n" .
    "session cookie name: " . session_name() . "\n"
) ?></pre>

<?php if ($result !== null): ?>
  <p style="font-weight:bold"><?= e($result) ?></p>
  <p><a href="debug_session.php">دوباره تست کن</a></p>
<?php else: ?>
  <form method="post">
    <input type="hidden" name="expected" value="<?= e($token) ?>">
    <button type="submit">ادامه (POST) برای تست پایداری Session</button>
  </form>
<?php endif; ?>
</body>
</html>

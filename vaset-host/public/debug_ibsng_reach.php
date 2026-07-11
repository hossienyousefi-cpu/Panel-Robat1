<?php

declare(strict_types=1);

/**
 * Temporary diagnostic: tests whether Host vaset can reach the IBSng admin panel
 * directly over the internet (no agent/tunnel needed if so), and shows the raw HTML
 * of the fetched page so real form field names can be extracted for
 * docs/IBSNG_INTEGRATION.md without needing browser DevTools access to IBSng itself.
 * Delete this file once the IBSng integration is wired up and confirmed working.
 */

const DEBUG_KEY = 'f533df2e4dd4a3990b19190909834e24b33bb2d1570a8565';

$providedKey = $_GET['key'] ?? $_POST['key'] ?? '';
if (!hash_equals(DEBUG_KEY, (string) $providedKey)) {
    http_response_code(403);
    echo 'دسترسی غیرمجاز.';
    exit;
}

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$url = trim((string) ($_POST['url'] ?? $_GET['url'] ?? 'https://194.59.214.84/IBSng/admin/index.php'));
$verifySsl = ($_POST['verify_ssl'] ?? '0') === '1';
$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $url !== '') {
    $cookieJar = sys_get_temp_dir() . '/ibsng_debug_cookies.txt';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
    ]);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $curlError = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($errno !== 0) {
        $error = "cURL error ({$errno}): {$curlError}";
    } else {
        $headerSize = $info['header_size'];
        $headers = substr((string) $response, 0, $headerSize);
        $body = substr((string) $response, $headerSize);
        $result = [
            'http_code' => $info['http_code'],
            'effective_url' => $info['url'],
            'total_time' => $info['total_time'],
            'headers' => $headers,
            'body' => $body,
        ];
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><title>IBSng Reach Debug</title></head>
<body style="font-family:sans-serif;padding:20px;line-height:1.8">
<h2>تست اتصال به IBSng</h2>
<form method="post">
<input type="hidden" name="key" value="<?= e($providedKey) ?>">
<label>آدرس:</label><br>
<input type="text" name="url" value="<?= e($url) ?>" style="width:500px">
<br><br>
<label><input type="checkbox" name="verify_ssl" value="1"> بررسی معتبر بودن گواهی SSL (اگه IP خامه، خاموش بذار)</label>
<br><br>
<button type="submit">تست اتصال</button>
</form>
<hr>
<?php if ($error): ?>
  <p style="color:red;font-weight:bold"><?= e($error) ?></p>
<?php elseif ($result): ?>
  <p><b>HTTP Code:</b> <?= e((string) $result['http_code']) ?></p>
  <p><b>Effective URL:</b> <?= e($result['effective_url']) ?></p>
  <p><b>Time (s):</b> <?= e((string) $result['total_time']) ?></p>
  <h3>Response Headers</h3>
  <pre style="background:#eee;padding:10px;white-space:pre-wrap"><?= e($result['headers']) ?></pre>
  <h3>Response Body (HTML)</h3>
  <textarea style="width:100%;height:500px;direction:ltr"><?= e($result['body']) ?></textarea>
<?php endif; ?>
</body>
</html>

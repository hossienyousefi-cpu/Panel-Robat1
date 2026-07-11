<?php

declare(strict_types=1);

/**
 * Temporary diagnostic: tests whether Host vaset can reach the IBSng admin panel
 * directly over the internet (no agent/tunnel needed if so), and shows the raw HTML
 * of the fetched page so real form field names / login success markers can be
 * extracted for docs/IBSNG_INTEGRATION.md without needing browser DevTools access to
 * IBSng itself. Supports POSTing fields (e.g. username/password) using a persistent
 * cookie jar so a login can be tested and then followed up with a GET to check
 * whether the session actually authenticated. Delete this file once the IBSng
 * integration is wired up and confirmed working.
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

$url = trim((string) ($_POST['url'] ?? $_GET['url'] ?? 'https://194.59.214.84/IBSng/admin/'));
$verifySsl = ($_POST['verify_ssl'] ?? '0') === '1';
$method = ($_POST['method'] ?? 'GET') === 'POST' ? 'POST' : 'GET';
$fieldsRaw = (string) ($_POST['fields'] ?? "username=\npassword=");
$resetCookies = ($_POST['reset_cookies'] ?? '0') === '1';
$result = null;
$error = null;

$cookieJar = sys_get_temp_dir() . '/ibsng_debug_cookies.txt';
if ($resetCookies) {
    @unlink($cookieJar);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $url !== '' && isset($_POST['do_request'])) {
    $fields = [];
    foreach (preg_split('/\r?\n/', $fieldsRaw) as $line) {
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $fields[trim($k)] = $v;
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($fields);
    }
    curl_setopt_array($ch, $opts);

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

$cookieJarContents = is_file($cookieJar) ? file_get_contents($cookieJar) : '(هنوز کوکی‌ای ذخیره نشده)';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><title>IBSng Reach Debug</title></head>
<body style="font-family:sans-serif;padding:20px;line-height:1.8">
<h2>تست اتصال / لاگین IBSng</h2>
<form method="post">
<input type="hidden" name="key" value="<?= e($providedKey) ?>">
<input type="hidden" name="do_request" value="1">

<label>آدرس:</label><br>
<input type="text" name="url" value="<?= e($url) ?>" style="width:500px">
<br><br>

<label>متد:</label><br>
<select name="method">
  <option value="GET" <?= $method === 'GET' ? 'selected' : '' ?>>GET</option>
  <option value="POST" <?= $method === 'POST' ? 'selected' : '' ?>>POST</option>
</select>
<br><br>

<label>فیلدهای POST (هر خط یک key=value؛ فقط وقتی متد POST باشه استفاده می‌شه):</label><br>
<textarea name="fields" style="width:500px;height:100px;direction:ltr"><?= e($fieldsRaw) ?></textarea>
<br><br>

<label><input type="checkbox" name="verify_ssl" value="1"> بررسی معتبر بودن گواهی SSL</label><br>
<label><input type="checkbox" name="reset_cookies" value="1"> پاک‌کردن کوکی‌های قبلی قبل از این درخواست (شروع Session تازه)</label>
<br><br>
<button type="submit">ارسال درخواست</button>
</form>

<h3>کوکی‌های ذخیره‌شده (Session فعلی بین درخواست‌ها حفظ می‌شه)</h3>
<pre style="background:#eee;padding:10px;white-space:pre-wrap;direction:ltr;font-size:12px"><?= e((string) $cookieJarContents) ?></pre>

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

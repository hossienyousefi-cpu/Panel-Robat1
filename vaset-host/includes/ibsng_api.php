<?php
// این سه مقدار از جدول settings (قابل تغییر از admin/settings.php بدون نیاز به ویرایش
// فایل یا SSH) خوانده می‌شوند. آدرس JSON-RPC API با آدرس پنل ادمین (IBS_URL) فرق دارد
// پس پیش‌فرض جداگانه دارد؛ یوزر/پسورد ادمین همان‌هایی هستند که در config.php برای ورود
// به پنل ادمین  استفاده می‌شوند.
define('IBS_API_URL',   getSetting('ibs_api_url', 'http://194.59.214.84/ibs-api/'));
define('IBS_ADMIN_USER',getSetting('ibs_admin_user', defined('IBS_ADMIN') ? IBS_ADMIN : ''));
define('IBS_ADMIN_PASS',getSetting('ibs_admin_pass', defined('IBS_PASS') ? IBS_PASS : ''));
define('IBS_CACHE_DIR',  sys_get_temp_dir() . '/ibs_cache/');

if (!is_dir(IBS_CACHE_DIR)) @mkdir(IBS_CACHE_DIR, 0750, true);

// ─── تابع اصلی JSON-RPC با timeout بهینه ───
// $timeoutSec: برای تماس‌های «بهترین تلاش»/غیرحیاتی (مثل وضعیت آنلاین که فقط
// یک نشانگر توی جدول‌هاست) یک تایم‌اوت کوتاه‌تر از پیش‌فرض ۲۰ ثانیه می‌شه پاس
// داد، تا اگر آن یک متد خاص روی IBSng کند/خراب بود، کل صفحه (که به این نتیجه
// وابسته نیست) رو معطل نکنه.
function ibsng_call($method, $params = [], $cacheSec = 0, $timeoutSec = null) {
    if ($cacheSec > 0) {
        $cKey = IBS_CACHE_DIR . md5($method . serialize($params)) . '.json';
        if (file_exists($cKey) && (time() - filemtime($cKey)) < $cacheSec) {
            $c = json_decode(file_get_contents($cKey), true);
            if ($c !== null) return $c;
        }
    }
    $params['auth_name'] = IBS_ADMIN_USER;
    $params['auth_pass'] = IBS_ADMIN_PASS;
    $params['auth_type'] = 'ADMIN';
    $data = json_encode(['jsonrpc'=>'2.0','method'=>$method,'params'=>$params,'id'=>1]);

    static $ch = null;
    if ($ch === null) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => IBS_API_URL,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TCP_KEEPALIVE  => 1,
            CURLOPT_TCP_KEEPIDLE   => 30,
        ]);
    }
    // چون $ch یک curl handle استاتیک/مشترکه، اگه این تماس تایم‌اوت سفارشی خواسته،
    // بعد از تمام‌شدنش باید به پیش‌فرض ۲۰ ثانیه برگردونیمش تا روی تماس بعدیِ همین
    // request (که شاید سفارشی نخواد) اثر نذاره.
    if ($timeoutSec !== null) curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    if ($timeoutSec !== null) curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    if ($curlErr) return ['error' => $curlErr, 'result' => null];
    $result = json_decode($response, true);
    if ($result === null) return ['error' => 'Invalid JSON from IBS API', 'result' => null];
    if (isset($result['error']) && !empty($result['error'])) return $result;

    if ($cacheSec > 0 && isset($cKey)) {
        @file_put_contents($cKey, json_encode($result));
    }
    return $result;
}

// ─── چند فراخوانی JSON-RPC مستقل از هم رو هم‌زمان (موازی، با curl_multi) بفرست
// - مثلاً وقتی چند تا صفحه‌ی user.getUserInfo یا جست‌وجوی چند ISP جدا از هم
// لازمه. چون این‌ها هیچ‌کدوم به نتیجه‌ی هم نیاز ندارن، به‌جای پشت‌سرهم رفتن (که
// زمانش جمع N تماس می‌شه)، هم‌زمان می‌رن روی شبکه و کل کار تقریباً فقط طول
// کندترینِ تک تماس رو می‌کشه. ورودی: آرایه‌ای از [method, params]؛ خروجی: آرایه‌ای
// هم‌اندازه با همون کلیدها، هرکدوم دقیقاً همون فرمتی که ibsng_call برمی‌گردونه.
function ibsng_callParallel(array $calls) {
    if (count($calls) <= 1) {
        $out = [];
        foreach ($calls as $k => $c) $out[$k] = ibsng_call($c[0], $c[1] ?? []);
        return $out;
    }
    $mh = curl_multi_init();
    $handles = [];
    foreach ($calls as $k => $c) {
        $method = $c[0];
        $params = $c[1] ?? [];
        $params['auth_name'] = IBS_ADMIN_USER;
        $params['auth_pass'] = IBS_ADMIN_PASS;
        $params['auth_type'] = 'ADMIN';
        $data = json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1]);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => IBS_API_URL,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$k] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running > 0 && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $k => $ch) {
        $response = curl_multi_getcontent($ch);
        $result   = json_decode($response, true);
        $out[$k]  = $result === null ? ['error' => 'Invalid JSON from IBS API', 'result' => null] : $result;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

// ─── مثل ibsng_callParallel ولی به‌جای فرستادن همه‌ی تماس‌ها هم‌زمان (که برای
// IBSngهای حساس/کم‌منبع می‌تونه باعث تایم‌اوت/شکست بی‌صدای بعضی تماس‌ها بشه -
// دقیقاً همون چیزی که باعث می‌شد سینک کرون فقط بخشی از کاربرها رو پیدا کنه)،
// تماس‌ها رو در دسته‌های کوچیک (پیش‌فرض ۵ تا) با یک مکث کوتاه بین دسته‌ها
// می‌فرسته، و هر تماسی که شکست خورده (خطا/JSON نامعتبر) رو یک‌بار دیگه
// پشت‌سرهم (نه موازی) retry می‌کنه. برای کارهای پس‌زمینه/کرون که فشار کم روی
// IBSng از سرعت مهم‌تره ───
function ibsng_callThrottled(array $calls, $chunkSize = 5, $delayMs = 200) {
    $out = [];
    foreach (array_chunk($calls, $chunkSize, true) as $chunk) {
        $results = ibsng_callParallel($chunk);
        foreach ($results as $k => $r) {
            if (!empty($r['error'])) {
                usleep(200000);
                $r = ibsng_call($chunk[$k][0], $chunk[$k][1] ?? []);
            }
            $out[$k] = $r;
        }
        if ($delayMs > 0) usleep($delayMs * 1000);
    }
    return $out;
}

// ─── گرفتن user.getUserInfo برای یک لیست بزرگ از uid، تکه‌تکه (۱۰۰ تایی) ولی
// هم‌زمان به‌جای پشت‌سرهم - جایگزین الگوی تکراری «array_chunk + foreach با
// ibsng_call پشت‌سرهم» که برای ISPهای بزرگ (چند هزار کاربر = چند ده chunk) کند
// بود. خروجی: آرایه‌ی user_id => اطلاعات کاربر (دقیقاً مثل result یک
// getUserInfo تکی روی همه‌ی uidها).
function ibsng_getUserInfoBulk(array $uids, $throttled = false) {
    $uids = array_values(array_unique($uids));
    if (empty($uids)) return [];
    $chunks = array_chunk($uids, 100);
    $calls = [];
    foreach ($chunks as $i => $chunk) {
        $calls[$i] = ['user.getUserInfo', ['user_id' => implode(',', $chunk)]];
    }
    $results = $throttled ? ibsng_callThrottled($calls) : ibsng_callParallel($calls);
    $inf = [];
    foreach ($results as $r) {
        if (!empty($r['result'])) $inf += $r['result'];
    }
    return $inf;
}

// ─── پاک کردن کش ───
function ibsng_clearCache($pattern = null) {
    if ($pattern === null) {
        foreach (glob(IBS_CACHE_DIR . '*.json') as $f) @unlink($f);
    } else {
        foreach (glob(IBS_CACHE_DIR . $pattern) as $f) @unlink($f);
    }
}

// ─── گروه‌ها با کش 5 دقیقه ───
function ibsng_getGroups() {
    $r   = ibsng_call('group.listGroups', [], 300);
    $raw = $r['result'] ?? [];
    $out = [];
    if (is_array($raw)) {
        foreach ($raw as $k => $v) {
            if (is_string($k) && $k !== '') $out[] = $k;
            elseif (is_string($v) && $v !== '') $out[] = $v;
        }
    }
    sort($out);
    return $out;
}

// ─── ISP ها با کش 5 دقیقه ───
function ibsng_getIsps() {
    $r = ibsng_call('isp.getAllISPNames', [], 300);
    return is_array($r['result']) ? $r['result'] : [];
}

// ─── نگاشت دستی/دائمیِ اسم‌ISP → isp_id، ذخیره‌شده توی جدول settings (کلید
// isp_id_map، یک JSON از اسم به عدد). این منبع اصلی و همیشگیه: هم ادمین از
// پنل می‌تونه دستی یک ISP رو (که کشف خودکار روش جواب نداد) ثبت کنه، هم خودِ
// ibsng_getIspId بعد از هر کشف موفق، نتیجه رو همین‌جا برای همیشه ذخیره می‌کنه
// تا دیگه لازم نباشه دوباره اسکن بشه ───
function ibsng_getIspIdManualMap() {
    global $pdo;
    $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='isp_id_map' LIMIT 1")->fetchColumn();
    $d = $v ? json_decode($v, true) : null;
    return is_array($d) ? $d : [];
}

function ibsng_setIspIdManual($ispName, $id) {
    global $pdo;
    $map = ibsng_getIspIdManualMap();
    $map[$ispName] = (int)$id;
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('isp_id_map', ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([json_encode($map, JSON_UNESCAPED_UNICODE)]);
}

function ibsng_clearIspIdManual($ispName) {
    global $pdo;
    $map = ibsng_getIspIdManualMap();
    unset($map[$ispName]);
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('isp_id_map', ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([json_encode($map, JSON_UNESCAPED_UNICODE)]);
}

// ─── تبدیل اسم ISP به شناسه‌ی عددی‌اش (isp_id) ───
// conds['isp_name'] توی user.searchUser هیچ تأثیری نداره (تست شد: total همیشه کل
// کاربرها بود) - فیلتر واقعی روی conds['isp_id'] انجام می‌شه. این عدد همون
// admin_id حساب ادمینِ صاحب اون ISP هست، ولی یوزرنیم لاگین اون ادمین لزوماً با
// اسم ISP یکی نیست (فقط برای Milad این‌طور بود) - پس اول نگاشت دائمی ذخیره‌شده
// رو چک می‌کنیم، بعد راه‌های خودکار کشف رو امتحان می‌کنیم.
function ibsng_getIspId($ispName) {
    if ($ispName === '') return null;
    static $mem = [];
    if (array_key_exists($ispName, $mem)) return $mem[$ispName];

    $manual = ibsng_getIspIdManualMap();
    if (isset($manual[$ispName])) {
        $mem[$ispName] = (int)$manual[$ispName];
        return $mem[$ispName];
    }

    // راه اول (ثابت‌شده برای Milad): شاید یوزرنیم ادمین دقیقاً همون اسم ISP باشه.
    $id = null;
    $r  = ibsng_call('admin.getAdminInfo', ['admin_username' => $ispName]);
    $info = $r['result'] ?? null;
    if (is_array($info) && ($info['username'] ?? null) === $ispName) {
        $id = $info['admin_id'] ?? null;
    }
    // راه دوم (برای بقیه‌ی ISPها که یوزرنیم ادمین‌شون با اسم ISP فرق داره): از روی
    // نگاشت کامل اسم‌ISP→isp_id که با اسکن عددی isp_id روی user.searchUser ساخته
    // شده پیدا می‌کنیم.
    if ($id === null) {
        $map = ibsng_getIspIdMap();
        $id  = $map[$ispName] ?? null;
    }
    $mem[$ispName] = $id !== null ? (int)$id : null;
    // چون کشفش گرون بود (اسکن عددی)، برای همیشه ذخیره‌اش می‌کنیم تا دیگه لازم
    // نباشه دوباره اسکن بشه.
    if ($id !== null) ibsng_setIspIdManual($ispName, (int)$id);
    return $mem[$ispName];
}

// ─── ساخت کامل نگاشت اسم‌ISP → isp_id با اسکن عددیِ isp_id از روی خودِ
// user.searchUser (نه admin.getAdminInfo - اون فقط با admin_username دقیق کار
// می‌کنه و admin_id رو اصلاً قبول نمی‌کنه، تست شد). چون فیلتر isp_id روی
// user.searchUser ثابت‌شده کار می‌کنه، برای هر عدد کاندید یک کاربر نمونه می‌گیریم
// و اسم واقعی ISP اون کاربر رو می‌خونیم. این فقط وقتی صدا زده می‌شه که نگاشت
// دستی/ذخیره‌شده جواب نداده - نتیجه‌اش هم دائمی ذخیره می‌شه (نه فقط کش موقت).
function ibsng_getIspIdMap($maxId = 100) {
    $cKey = IBS_CACHE_DIR . 'ispid_map_v2.json';
    if (file_exists($cKey) && (time() - filemtime($cKey)) < 3600) {
        $cached = @json_decode(@file_get_contents($cKey), true);
        if (is_array($cached) && !empty($cached)) return $cached;
    }
    $map = [];
    $wanted = count(ibsng_getIsps());
    for ($i = 1; $i <= $maxId; $i++) {
        $r = ibsng_call('user.searchUser', [
            'conds' => ['isp_id' => [(string)$i]], 'from' => 0, 'to' => 1,
            'order_by' => 'user_id', 'desc' => true,
        ]);
        $uids = $r['result'][2] ?? [];
        if (empty($uids)) continue;
        $inf = ibsng_call('user.getUserInfo', ['user_id' => (string)$uids[0]]);
        $row = $inf['result'][$uids[0]] ?? $inf['result'][(string)$uids[0]] ?? null;
        $isp = $row['basic_info']['isp_name'] ?? null;
        if ($isp !== null && $isp !== '') $map[$isp] = (int)$i;
        if ($wanted > 0 && count($map) >= $wanted) break;
    }
    if (!empty($map)) @file_put_contents($cKey, json_encode($map));
    return $map;
}

// ─── ساخت شرط conds برای فیلتر یک ISP - همیشه از این تابع استفاده کن، نه از
// isp_name مستقیم که کار نمی‌کنه. اگه ISP پیدا نشه، شرطی برمی‌گردونه که هیچ
// کاربری رو برنمی‌گردونه (به‌جای نادیده گرفتن سایلنت فیلتر و برگردوندن کل لیست).
function ibsng_ispCond($ispName) {
    $id = ibsng_getIspId($ispName);
    if ($id === null) return ['isp_id' => ['0']];
    return ['isp_id' => [(string)$id]];
}

// ─── RAS ها با کش 10 دقیقه ───
function ibsng_getRasList() {
    $r = ibsng_call('ras.getAllRasNames', [], 600);
    if (!empty($r['result']) && is_array($r['result'])) return $r['result'];
    // fallback: از طریق کاربران آنلاین یا searchUser نمی‌شه گرفت
    return [];
}

// ─── تعداد کاربران یک ISP با کش ۱۰ دقیقه‌ای ───
// صفحات لیست ریسلرها/داشبورد ادمین/مدیریت موجودی این تابع رو برای هر ریسلر جدا
// جدا صدا می‌زنن؛ کش کوتاه (۶۰ ثانیه) باعث می‌شد اکثر بازدیدها چند تماس زنده و
// پشت‌سرهم به  بزنن و کل صفحه کند بشه. تعداد کاربر یک ISP به این تازگی نیاز نداره.
function ibsng_getIspUserCount($ispName) {
    $r = ibsng_call('user.searchUser', [
        'conds'    => ibsng_ispCond($ispName),
        'from'     => 0,
        'to'       => 1,
        'order_by' => 'user_id',
        'desc'     => false,
    ], 600);
    return (int)($r['result'][0] ?? 0);
}

// ─── دریافت اطلاعات یک کاربر با کش 2 دقیقه ───
function ibsng_getUserInfo($uid) {
    $uid = (string)$uid;
    $cKey = IBS_CACHE_DIR . 'u_' . $uid . '.json';
    if (file_exists($cKey) && (time() - filemtime($cKey)) < 120) {
        $c = json_decode(file_get_contents($cKey), true);
        if ($c !== null) return $c;
    }
    $r = ibsng_call('user.getUserInfo', ['user_id' => $uid]);
    $info = $r['result'][$uid] ?? null;
    if ($info === null && !empty($r['result']) && is_array($r['result'])) {
        $first = reset($r['result']);
        if (is_array($first)) $info = $first;
    }
    if ($info !== null) {
        @file_put_contents($cKey, json_encode($info));
        return $info;
    }
    return [];
}

// ─── دریافت اطلاعات چند کاربر به صورت batch ───
function ibsng_getUsersInfo(array $uids) {
    if (empty($uids)) return [];
    $out = [];
    // batch call
    $r = ibsng_call('user.getUserInfo', ['user_id' => implode(',', $uids)]);
    if (!empty($r['result']) && is_array($r['result'])) {
        foreach ($uids as $uid) {
            if (isset($r['result'][$uid])) {
                $out[$uid] = $r['result'][$uid];
            }
        }
    }
    // اگر batch کار نکرد، یک‌به‌یک
    foreach ($uids as $uid) {
        if (!isset($out[$uid])) {
            $out[$uid] = ibsng_getUserInfo($uid);
        }
    }
    return $out;
}

// ─── جستجوی کاربران با فیلتر ISP (برای ریسلر) ───
// برگرداندن [total, uids]

// ─── کش کامل کاربران یک ISP (برای پنل ریسلر - 60 ثانیه) ───
// ─── گرفتن همه UIDs یک ISP با pagination کامل ───
function ibsng_getAllUidsForIsp($conds, $throttled = false) {
    $batchSize = 500;
    // اول فقط total رو با یک تماس سبک بگیر
    $rCount = ibsng_call('user.searchUser', ['conds' => $conds, 'from' => 0, 'to' => 1, 'order_by' => 'user_id', 'desc' => true]);
    $total  = (int)($rCount['result'][0] ?? 0);
    if ($total <= 0) return [];

    // سقف صفحه: اگر شرط isp_name/group_name به هر دلیلی سمت  فیلتر نکند (یا total
    // درست برنگردد)، این نباید صدها صفحه هم‌زمان درخواست بزند و کل هاست را برای
    // بقیه‌ی کاربران هم کند/بلاک کند. حداکثر ۴۰ صفحه (۲۰٬۰۰۰ کاربر) کافیه.
    $maxPages = 40;
    $pages    = min($maxPages, (int)ceil($total / $batchSize));

    // چون همه‌ی صفحات از هم مستقلن (فقط offset فرق می‌کنه)، به‌جای پشت‌سرهم
    // رفتن (که برای ISPهای بزرگ ده‌ها request طول می‌کشید)، همه رو هم‌زمان
    // می‌فرستیم - زمان کل تقریباً فقط طول کندترینِ تک صفحه می‌شه. برای کارهای
    // پس‌زمینه (کرون سینک) که فشار کم روی IBSng از سرعت مهم‌تره، $throttled=true
    // این صفحات رو دسته‌دسته و با retry می‌فرسته، نه همه رو یک‌جا - چون فرستادن
    // ده‌ها تماس هم‌زمان به یک IBSng حساس باعث می‌شد بعضی‌شون بی‌صدا timeout/شکست
    // بخورن و نتیجه (تعداد کاربر) کمتر از واقعی برگرده.
    $calls = [];
    for ($p = 0; $p < $pages; $p++) {
        $from = $p * $batchSize;
        $calls[$p] = ['user.searchUser', ['conds' => $conds, 'from' => $from, 'to' => $from + $batchSize, 'order_by' => 'user_id', 'desc' => true]];
    }
    $results = $throttled ? ibsng_callThrottled($calls) : ibsng_callParallel($calls);
    $allUids = [];
    foreach ($results as $r) {
        $allUids = array_merge($allUids, $r['result'][2] ?? []);
    }
    return $allUids;
}

// ─── تبدیل یک UID به row ───
// ─── مجموعه‌ی یوزرنیم‌های آنلاین (از report.getOnlineUsers) - چون online_status
// توی getUserInfo وقتی برای صدها کاربر یک‌جا (bulk) خونده می‌شه همیشه false
// برمی‌گرده، وضعیت آنلاین واقعی رو باید جدا از همین لیست گرفت. عمداً همیشه از
// نسخه‌ی خام/سریع (بدون فیلتر isp_name) استفاده می‌کنیم - یوزرنیم توی کل IBSng
// یکتاست، پس برای «این یوزرنیم آنلاینه یا نه» نیازی به resolve کردن ISP نیست؛
// این باعث می‌شه این تابع (که هر بازدید صفحه‌ی کاربران صداش می‌زنیم) همیشه سریع
// و سبک بمونه، حتی اگه پارامتر $ispName پاس داده بشه.
function ibsng_getOnlineUsernameSet($ispName = '') {
    $r = ibsng_getOnlineRaw();
    $set = [];
    foreach ($r['data'] ?? [] as $u) {
        $un = $u['normal_username'] ?? $u['username'] ?? '';
        if ($un !== '') $set[$un] = true;
    }
    return $set;
}

function ibsng_uidToRow($uid, $u, $ispName, $onlineSet = []) {
    $basic = $u['basic_info'] ?? []; $attrs = $u['attrs'] ?? [];
    $un = $attrs['normal_username'] ?? $attrs['username'] ?? '';
    if ($un === '') foreach ($attrs as $k=>$v) if(stripos($k,'username')!==false&&is_string($v)&&$v!==''){$un=$v;break;}
    // فیلتر ISP همین الان توی خود کوئری (conds['isp_name']) روی  انجام می‌شه؛ چک
    // دوباره‌ی اینجا فقط باعث می‌شد اگه رشته‌ی isp_name برگشتی از  با فاصله/حروف
    // کمی فرق داشت (که پیش میومد)، ردیف‌های درست هم بی‌صدا حذف بشن.
    $exp = $basic['nearest_exp_date'] ?? '';
    $et  = $exp ? strtotime($exp) : 0;
    $dL  = $et ? (int)(($et - time()) / 86400) : null;
    $ras = $basic['ras_ip_addr'] ?? ($attrs['ras_ip_addr'] ?? '—');
    return [
        'id'       => $uid,
        'username' => $un,
        'password' => $attrs['normal_password'] ?? '—',
        'status'   => $basic['status'] ?? '—',
        'group'    => $basic['group_name'] ?? '—',
        'isp'      => $basic['isp_name'] ?? $ispName,
        'ras'      => $ras,
        'exp'      => $exp ? substr($exp, 0, 10) : '∞',
        'exp_ts'   => $et ?: 0,
        'days_left'=> $dL,
        'online'   => isset($onlineSet[$un]),
    ];
}

// ─── کش کامل کاربران یک ISP (5 دقیقه) ───
function ibsng_getIspUsersCache($ispName, $groupFilter = '') {
    if ($ispName === '') return [];

    $cKey = IBS_CACHE_DIR . 'isp_full_' . md5($ispName . $groupFilter) . '.json';
    if (file_exists($cKey) && (time() - filemtime($cKey)) < 300) {
        $c = @json_decode(@file_get_contents($cKey), true);
        if (is_array($c) && !empty($c)) return $c;
    }

    // ─ فایل "در حال ساخت" بررسی کن تا دو request همزمان نسازن ─
    $lockFile = $cKey . '.building';
    if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 120) {
        // کش دارد ساخته میشه - اگه کش قدیمی داریم همونو بده
        if (file_exists($cKey)) {
            $c = @json_decode(@file_get_contents($cKey), true);
            if (is_array($c)) return $c;
        }
        return []; // هنوز چیزی نیست
    }
    @file_put_contents($lockFile, time());
    // اگر اسکریپت به هر دلیل (timeout، خطای بحرانی) وسط راه متوقف شود، این فایل قفل
    // باقی می‌ماند و تا ۱۲۰ ثانیه بعدی درخواست‌ها را خالی برمی‌گرداند؛ با register_shutdown
    // مطمئن می‌شویم قفل در هر صورت پاک می‌شود.
    register_shutdown_function(function () use ($lockFile) { @unlink($lockFile); });

    $conds = ibsng_ispCond($ispName);
    if ($groupFilter !== '') $conds['group_name'] = $groupFilter;

    $uids = ibsng_getAllUidsForIsp($conds);
    if (empty($uids)) {
        @unlink($lockFile);
        @file_put_contents($cKey, '[]');
        return [];
    }

    $onlineSet = ibsng_getOnlineUsernameSet($ispName);
    $rows = [];
    $inf = ibsng_getUserInfoBulk($uids);
    foreach ($inf as $uid => $u) {
        $row = ibsng_uidToRow($uid, $u, $ispName, $onlineSet);
        if ($row) $rows[] = $row;
    }
    @unlink($lockFile);
    @file_put_contents($cKey, json_encode($rows));
    return $rows;
}

// ─── گرفتن سریع صفحه اول بدون کش کامل ───
// فقط UIDs صفحه اول رو میگیره + getUserInfo - خیلی سریعتر
// ─── کش محلی کاربران در جدول MySQL (ibsng_users_cache)، پرشده توسط
// cron/sync_ibsng_users.php هر چند دقیقه. سریع‌تر از کش فایلی (SQL ایندکس‌شده،
// امکان سرچ هم‌زمان روی همه‌ی ISPها با یک کوئری) و کاملاً بدون تماس با IBSng.
// اگه جدول خالی/قدیمی باشه (کرون هنوز اجرا نشده یا مدتی متوقف بوده)، false
// برمی‌گرده تا فراخوان به روش قدیمی (زنده از IBSng) برگرده - یعنی نبود این کش
// هیچ‌وقت باعث خراب‌شدن سرچ نمی‌شه، فقط کندتر می‌مونه ───
// $maxAgeSec پیش‌فرض ۲۶ ساعته (نه ۱۵ دقیقه) چون سینک روزی یک‌بار (کرون
// cron/sync_ibsng_users.php با شیدول روزانه) اجرا می‌شه، نه هر چند دقیقه -
// اجرای مکرر فشار/بار زیادی روی IBSng و CPU خود هاست می‌ذاشت.
// ─── به‌روزرسانی فوری یک ردیف از کش محلی (ibsng_users_cache) درست بعد از
// ساخت/تمدید/تغییر رمز یک کاربر از خودِ پنل (ادمین/ریسلر/تلگرام). کرون شبانه
// (cron/sync_ibsng_users.php) فقط یک‌بار در روز کل جدول رو تازه می‌کنه - این
// باعث می‌شد اگه کسی همین الان از پنل یوزر بسازه/تمدید کنه، توی سرچ (که از
// همین کش می‌خونه) تا ۴ صبح فردا با اطلاعات قدیمی/ناقص دیده بشه. این تابع فقط
// همون یک کاربر (نه کل ISP) رو با یک تماس زنده‌ی سبک به‌روز می‌کنه، پس روی
// سرعت سرچ بقیه یا فشار کلی روی IBSng تأثیری نمی‌ذاره - فقط دقیقاً همون لحظه‌ای
// اجرا می‌شه که خودِ پنل داره یک عملیات نوشتنی روی همون کاربر انجام می‌ده.
function ibsng_cacheUpsertUser($pdo, $uid, $ispNameHint = '') {
    try {
        $inf = ibsng_call('user.getUserInfo', ['user_id' => (string)$uid]);
        $u = $inf['result'][(string)$uid] ?? $inf['result'][$uid] ?? null;
        if (!$u) return;
        $row = ibsng_uidToRow($uid, $u, $ispNameHint, []);
        $stmt = $pdo->prepare("INSERT INTO ibsng_users_cache
            (uid, username, password, status, group_name, isp_name, ras_ip, credit, exp_date, exp_ts, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE
                username=VALUES(username), password=VALUES(password), status=VALUES(status),
                group_name=VALUES(group_name), isp_name=VALUES(isp_name), ras_ip=VALUES(ras_ip),
                credit=VALUES(credit), exp_date=VALUES(exp_date), exp_ts=VALUES(exp_ts), updated_at=NOW()");
        $stmt->execute([
            (string)$uid, $row['username'], $row['password'], $row['status'],
            $row['group'], $row['isp'] ?: $ispNameHint, $row['ras'],
            (float)($u['basic_info']['credit'] ?? 0),
            $row['exp'] === '∞' ? '' : $row['exp'], (int)$row['exp_ts'],
        ]);
    } catch (Throwable $e) {
        // کش محلی فقط برای سرعت سرچه؛ شکست این به‌روزرسانی نباید عملیات اصلی
        // (که موفق شده) رو خراب کنه - کرون شبانه بالاخره جبرانش می‌کنه.
    }
}

// ─── حذف فوری یک ردیف از کش محلی بعد از حذف کاربر از خودِ پنل ───
function ibsng_cacheDeleteUser($pdo, $uid) {
    try {
        $pdo->prepare("DELETE FROM ibsng_users_cache WHERE uid = ?")->execute([(string)$uid]);
    } catch (Throwable $e) {
    }
}

function ibsng_dbCacheFresh($pdo, $maxAgeSec = 93600) {
    static $fresh = null;
    if ($fresh !== null) return $fresh;
    try {
        $row = $pdo->query("SELECT MAX(updated_at) m, COUNT(*) c FROM ibsng_users_cache")->fetch();
    } catch (Throwable $e) {
        return $fresh = false; // جدول هنوز وجود ندارد (migration اجرا نشده)
    }
    $fresh = !empty($row['c']) && $row['m'] && (time() - strtotime($row['m'])) < $maxAgeSec;
    return $fresh;
}

// ─── چک اینکه آیا این ISP خاص اصلاً توی کش هست یا نه. ibsng_dbCacheFresh بالا
// فقط تازگیِ کل جدول رو چک می‌کنه، نه اینکه هر ISP خاصی توش باشه. بدون این چک،
// یک ISP تازه‌ساز (یا یوزرهایی که تازه بهش منتقل شدن) که هنوز کرون شبانه روش
// اجرا نشده، با اینکه صفر ردیف توی کش داره، چون بقیه‌ی جدول "تازه" حساب می‌شه
// نتیجه‌ی سرچ صفر (به‌جای برگشت به روش زنده‌ی IBSng) نشون می‌داد - انگار اصلاً
// کاربری نداره، در حالی که فقط هنوز سینک نشده. ───
function ibsng_dbCacheHasIsp($pdo, $ispName) {
    if ($ispName === '') return true; // فیلتر ISP نداریم، این چک اصلاً معنی نداره
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM ibsng_users_cache WHERE isp_name = ? LIMIT 1");
        $stmt->execute([$ispName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ibsng_dbCacheSearch($pdo, $ispName, $groupFilter, $search, $rasFilter, $sortBy, $sortDir, $page, $perPage) {
    $where = []; $params = [];
    if ($ispName !== '')    { $where[] = 'isp_name = ?';   $params[] = $ispName; }
    if ($groupFilter !== '') { $where[] = 'group_name = ?'; $params[] = $groupFilter; }
    if ($search !== '')      { $where[] = 'username LIKE ?'; $params[] = '%' . $search . '%'; }
    if ($rasFilter !== '')   { $where[] = 'ras_ip LIKE ?';   $params[] = '%' . $rasFilter . '%'; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $totalIsp = 0;
    if ($ispName !== '') {
        $s = $pdo->prepare("SELECT COUNT(*) FROM ibsng_users_cache WHERE isp_name = ?");
        $s->execute([$ispName]);
        $totalIsp = (int)$s->fetchColumn();
    }

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM ibsng_users_cache $whereSql");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();

    $sortCol = ['username' => 'username', 'group' => 'group_name', 'isp' => 'isp_name',
        'ras' => 'ras_ip', 'exp' => 'exp_ts', 'status' => 'status'][$sortBy] ?? 'uid';
    $dir = strtolower($sortDir) === 'asc' ? 'ASC' : 'DESC';

    $stmt = $pdo->prepare("SELECT * FROM ibsng_users_cache $whereSql ORDER BY $sortCol $dir LIMIT ? OFFSET ?");
    $i = 1;
    foreach ($params as $p) $stmt->bindValue($i++, $p);
    $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($i++, $page * $perPage, PDO::PARAM_INT);
    $stmt->execute();

    $onlineSet = ibsng_getOnlineUsernameSet('');
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $expTs = (int)$r['exp_ts'];
        $rows[] = [
            'id' => $r['uid'], 'username' => $r['username'], 'password' => $r['password'],
            'status' => $r['status'], 'group' => $r['group_name'], 'isp' => $r['isp_name'],
            'ras' => $r['ras_ip'], 'exp' => $r['exp_date'] !== '' ? $r['exp_date'] : '∞',
            'exp_ts' => $expTs, 'days_left' => $expTs ? (int)(($expTs - time()) / 86400) : null,
            'online' => isset($onlineSet[$r['username']]), 'credit' => (float)$r['credit'],
        ];
    }
    return ['total' => $total, 'total_isp' => $totalIsp, 'rows' => $rows, 'cached' => true];
}

function ibsng_getIspUsersPage($ispName, $groupFilter, $search, $sortBy, $sortDir, $page, $perPage) {
    if ($ispName === '') return ['total' => 0, 'total_isp' => 0, 'rows' => [], 'cached' => false];

    global $pdo;
    if (isset($pdo) && ibsng_dbCacheFresh($pdo) && ibsng_dbCacheHasIsp($pdo, $ispName)) {
        return ibsng_dbCacheSearch($pdo, $ispName, $groupFilter, $search, '', $sortBy, $sortDir, $page, $perPage);
    }

    // اگه کش کامل داریم، از اون استفاده کن
    $cKey = IBS_CACHE_DIR . 'isp_full_' . md5($ispName . $groupFilter) . '.json';
    if (file_exists($cKey) && (time() - filemtime($cKey)) < 300) {
        $allRows = @json_decode(@file_get_contents($cKey), true);
        if (is_array($allRows) && !empty($allRows)) {
            $totalIsp = count($allRows);
            if ($search !== '') {
                $allRows = array_values(array_filter($allRows, fn($r) => stripos($r['username'], $search) !== false));
            }
            $total = count($allRows);
            if (!empty($allRows) && in_array($sortBy, ['username','group','isp','ras','exp','status'])) {
                usort($allRows, function($a, $b) use ($sortBy, $sortDir) {
                    $va = $sortBy==='exp' ? ($a['exp_ts']??0) : strtolower($a[$sortBy]??'');
                    $vb = $sortBy==='exp' ? ($b['exp_ts']??0) : strtolower($b[$sortBy]??'');
                    $cmp = is_numeric($va) ? ($va<=>$vb) : strcmp($va,$vb);
                    return $sortDir==='asc' ? $cmp : -$cmp;
                });
            }
            return ['total'=>$total,'total_isp'=>$totalIsp,'rows'=>array_slice($allRows,$page*$perPage,$perPage),'cached'=>true];
        }
    }

    // کش نداریم → فقط تعداد کل + صفحه اول رو سریع بگیر
    $conds = ibsng_ispCond($ispName);
    if ($groupFilter !== '') $conds['group_name'] = $groupFilter;

    // تعداد کل
    $rCount = ibsng_call('user.searchUser', ['conds'=>$conds,'from'=>0,'to'=>1,'order_by'=>'user_id','desc'=>true]);
    $totalIsp = (int)($rCount['result'][0] ?? 0);

    // اگه search داریم: اول یک جستجوی سریع مستقیم (LIKE روی همین ISP) امتحان کن -
    // فقط یک/چند  request کوچیکه. اگه جواب نداد (جستجوی جزئی/partial)، به اسکن
    // کامل کاربرهای ISP برمی‌گردیم که برای ISPهای بزرگ (۹۰۰۰+ کاربر) خیلی کندتره
    // ولی همیشه جواب درست می‌ده.
    if ($search !== '') {
        $onlineSet = ibsng_getOnlineUsernameSet($ispName);
        $fastConds = $conds;
        $fastConds['normal_username']    = $search;
        $fastConds['normal_username_op'] = 'like';
        $fr = ibsng_call('user.searchUser', ['conds' => $fastConds, 'from' => 0, 'to' => 500, 'order_by' => 'user_id', 'desc' => true]);
        $fastUids = $fr['result'][2] ?? [];
        $allRows = [];
        if (!empty($fastUids)) {
            $inf = ibsng_getUserInfoBulk($fastUids);
            foreach ($fastUids as $uid) {
                $u = $inf[(string)$uid] ?? $inf[$uid] ?? null;
                if (!$u) continue;
                $row = ibsng_uidToRow($uid, $u, $ispName, $onlineSet);
                if ($row) $allRows[] = $row;
            }
        }
        if (empty($allRows)) {
            $allUids = ibsng_getAllUidsForIsp($conds);
            $inf = ibsng_getUserInfoBulk($allUids);
            foreach ($allUids as $uid) {
                $u = $inf[(string)$uid] ?? $inf[$uid] ?? null;
                if (!$u) continue;
                $row = ibsng_uidToRow($uid, $u, $ispName, $onlineSet);
                if (!$row) continue;
                if (stripos($row['username'], $search) === false) continue;
                $allRows[] = $row;
            }
        }
        $total = count($allRows);
        if (!empty($allRows) && in_array($sortBy, ['username','group','isp','ras','exp','status'])) {
            usort($allRows, function($a, $b) use ($sortBy, $sortDir) {
                $va = $sortBy==='exp' ? ($a['exp_ts']??0) : strtolower($a[$sortBy]??'');
                $vb = $sortBy==='exp' ? ($b['exp_ts']??0) : strtolower($b[$sortBy]??'');
                $cmp = is_numeric($va) ? ($va<=>$vb) : strcmp($va,$vb);
                return $sortDir==='asc' ? $cmp : -$cmp;
            });
        }
        return ['total'=>$total,'total_isp'=>$totalIsp,'rows'=>array_slice($allRows,$page*$perPage,$perPage),'cached'=>false];
    }

    // بدون search/فیلتر آنلاین: فقط صفحه فعلی رو بگیر
    $rPage = ibsng_call('user.searchUser', ['conds'=>$conds,'from'=>$page*$perPage,'to'=>($page+1)*$perPage,'order_by'=>'user_id','desc'=>true]);
    $pageUids = $rPage['result'][2] ?? [];
    $rows = [];
    if (!empty($pageUids)) {
        $inf = ibsng_call('user.getUserInfo', ['user_id' => implode(',', $pageUids)]);
        $onlineSet = ibsng_getOnlineUsernameSet($ispName);
        foreach ($pageUids as $uid) {
            $u = ($inf['result'][(string)$uid] ?? $inf['result'][$uid] ?? null);
            if (!$u) continue;
            $row = ibsng_uidToRow($uid, $u, $ispName, $onlineSet);
            if ($row) $rows[] = $row;
        }
    }
    return ['total'=>$totalIsp,'total_isp'=>$totalIsp,'rows'=>$rows,'cached'=>false];
}

function ibsng_searchUsersForReseller($ispName, $search, $group, $page, $perPage = 50) {
    $conds = [];
    if ($ispName !== '') $conds = ibsng_ispCond($ispName);
    if ($group   !== '') $conds['group_name'] = $group;

    // ─ جستجو با username ─
    if ($search !== '') {
        // ابتدا با کلید اصلی سعی می‌کنیم
        $conds['normal_username'] = $search;
        $conds['normal_username_op'] = 'like';
        $r = ibsng_call('user.searchUser', [
            'conds'    => $conds,
            'from'     => $page * $perPage,
            'to'       => ($page + 1) * $perPage,
            'order_by' => 'user_id',
            'desc'     => true,
        ]);
        $total = (int)($r['result'][0] ?? 0);
        $uids  = $r['result'][2] ?? [];

        // اگر نتیجه‌ای نبود، با partial match در PHP فیلتر کن
        if (empty($uids)) {
            unset($conds['normal_username'], $conds['normal_username_op']);
            $r2 = ibsng_call('user.searchUser', [
                'conds'    => $conds,
                'from'     => 0,
                'to'       => 1000,
                'order_by' => 'user_id',
                'desc'     => true,
            ]);
            $allUIDs = $r2['result'][2] ?? [];
            if (!empty($allUIDs)) {
                $inf   = ibsng_call('user.getUserInfo', ['user_id' => implode(',', $allUIDs)]);
                $infos = $inf['result'] ?? [];
                $filtered = [];
                foreach ($allUIDs as $uid) {
                    $u  = $infos[$uid] ?? null;
                    if (!$u) continue;
                    $un = $u['attrs']['normal_username'] ?? '';
                    if (stripos($un, $search) !== false) {
                        // double-check ISP
                        if ($ispName === '' || ($u['basic_info']['isp_name'] ?? '') === $ispName) {
                            $filtered[] = $uid;
                        }
                    }
                }
                $total = count($filtered);
                $uids  = array_slice($filtered, $page * $perPage, $perPage);
            }
        }
        return [$total, $uids];
    }

    // ─ بدون جستجو ─
    $r = ibsng_call('user.searchUser', [
        'conds'    => $conds,
        'from'     => $page * $perPage,
        'to'       => ($page + 1) * $perPage,
        'order_by' => 'user_id',
        'desc'     => true,
    ]);
    return [(int)($r['result'][0] ?? 0), $r['result'][2] ?? []];
}

// ─── کاربران آنلاین با کش 20 ثانیه ───
function ibsng_getOnlineRaw() {
    $cKey = IBS_CACHE_DIR . 'online_all.json';
    if (file_exists($cKey) && (time() - filemtime($cKey)) < 30) {
        $c = json_decode(file_get_contents($cKey), true);
        if (is_array($c)) return ['error' => '', 'data' => $c];
    }

    // Circuit breaker: این متد خاص روی این IBSng همیشه شکست می‌خوره (حتی با
    // تایم‌اوت ۵ ثانیه). اگه همین الان (طی ۶۰ ثانیه‌ی گذشته) یک‌بار شکست خورده،
    // دیگه دوباره امتحانش نمی‌کنیم تا معطل یک تماسِ همیشه‌ناموفق نمونیم - ولی
    // مهم: این فقط خودِ تماس API رو skip می‌کنه، نه کل تابع رو؛ همیشه به fallback
    // نشست HTML (پایین) می‌رسیم، چون اون یکی واقعاً کار می‌کنه. قبلاً این تابع
    // موقع skip کردن API مستقیماً خطا برمی‌گردوند و اصلاً سراغ fallback نمی‌رفت -
    // همون چیزی که باعث می‌شد بعضی وقت‌ها (بین ۳۰ تا ۶۰ ثانیه بعد از یک شکست) پیام
    // «موقتاً رد شد» به‌جای لیست واقعی نشون داده بشه.
    $failFlag = IBS_CACHE_DIR . 'online_failing.flag';
    $skipApi  = file_exists($failFlag) && (time() - filemtime($failFlag)) < 60;

    if (!$skipApi) {
        // نتیجه‌اش فقط یک نشانگر «آنلاین/آفلاین» توی جدول‌هاست (نه چیز حیاتی)، پس
        // تایم‌اوت این تماس رو ۵ ثانیه می‌ذاریم تا اگه IBSng جواب نداد، صفحه به‌جای
        // ۲۰ ثانیه فقط ۵ ثانیه (و فقط همین یک‌بار در هر ۶۰ ثانیه) معطل بمونه.
        $r = ibsng_call('report.getOnlineUsers', [
            'normal_sort_by' => 'username',
            'normal_desc'    => false,
            'voip_sort_by'   => 'username',
            'voip_desc'      => false,
            'conds'          => [],
        ], 0, 5);
        if (empty($r['error'])) {
            @unlink($failFlag);
            $raw = [];
            if (isset($r['result'][0]) && is_array($r['result'][0])) {
                $raw = $r['result'][0];
            } elseif (isset($r['result']) && is_array($r['result'])) {
                foreach ($r['result'] as $v) {
                    if (is_array($v) && (isset($v['normal_username']) || isset($v['username'])))
                        $raw[] = $v;
                }
            }
            $seen = []; $out = [];
            foreach ($raw as $u) {
                $un = $u['normal_username'] ?? $u['username'] ?? '';
                if ($un !== '' && !isset($seen[$un])) {
                    $seen[$un] = true;
                    $out[] = $u;
                }
            }
            @file_put_contents($cKey, json_encode($out));
            return ['error' => '', 'data' => $out];
        }
        @file_put_contents($failFlag, (string)time());
    }

    // متد API روی این سرور IBSng همیشه شکست می‌خوره (تأیید شده)، پس به‌جاش از
    // همون نشست HTML پنل اصلی (مثل Kick) لیست آنلاین‌ها رو می‌خونیم. محدودیت: این
    // روش مدت اتصال (duration) و RAS رو نداره (IBSng این‌ها رو توی فرم Search
    // User/تب Online نشون نمی‌ده)، فقط یوزرنیم/گروه/ISP/آی‌پی/user_id.
    $native = ibsng_getOnlineNative();
    if ($native['error'] === null) {
        @file_put_contents($cKey, json_encode($native['data']));
        return ['error' => '', 'data' => $native['data']];
    }
    return ['error' => $native['error'], 'data' => []];
}

// ─── لیست کاربران آنلاین از طریق نشست HTML پنل اصلی - fallback وقتی
// report.getOnlineUsers (بالا) جواب نمی‌ده. معلوم شد IBSng گزارش آنلاین
// جداگانه‌ای نداره: خودِ لینک "Online Users" توی پنل اصلی صرفاً به فرم
// Search User با تب Online ریدایرکت می‌شه؛ پس همون فرم رو submit می‌کنیم. ───
function ibsng_getOnlineNative() {
    if (!ibsng_nativeLogin()) {
        return ['error' => 'ورود به پنل اصلی IBSng ناموفق بود', 'data' => []];
    }
    $cookieFile = ibsng_nativeCookieFile();
    $base = rtrim(IBS_URL, '/');
    $searchUrl = $base . '/user/search_user.php';

    $postFields = [
        'search'            => '1',
        'show_reports'      => '1',
        'submit_form'       => '1',
        'page'              => '1',
        'is_online_yes'     => 'On',
        'order_by'          => 'user_id',
        'desc'              => 'on',
        'rpp'               => '5000',
        'view_options'      => '2',
        'Internet_Username' => 'show__attrs_normal_username',
        'User_ID'           => 'show__basic_user_id',
        'Group'             => 'show__basic_group_name',
        'ISP'               => 'show__basic_isp_name',
        'Online'            => 'show__online_status|formatOnline',
        'Remote_IPs'        => 'show__remote_ips',
    ];
    $headers = ['Referer: ' . $searchUrl . '?tab1_selected=Online'];
    $res  = ibsng_nativeHttpEx($searchUrl, $cookieFile, $postFields, $headers);
    $body = $res['body'];

    if ($body === false || !ibsng_nativeIsLoggedIn($body)) {
        // شاید نشست منقضی شده - یک‌بار دیگه با لاگین تازه امتحان کن
        if (ibsng_nativeLogin()) {
            $res  = ibsng_nativeHttpEx($searchUrl, $cookieFile, $postFields, $headers);
            $body = $res['body'];
        }
        if ($body === false || !ibsng_nativeIsLoggedIn($body)) {
            return ['error' => 'پاسخ نامعتبر از پنل اصلی IBSng (نشست منقضی؟)', 'data' => []];
        }
    }

    $out = [];
    if (preg_match_all(
        '/<tr class="List_Row_(?:light|dark)Color"[^>]*onClick="window\.open\(\'[^\']*user_id=(\d+)\'[^>]*>(.*?)<\/tr>/s',
        $body, $rowMatches, PREG_SET_ORDER
    )) {
        foreach ($rowMatches as $rm) {
            $uid = $rm[1];
            preg_match_all('/<td class="List_Col"[^>]*>(.*?)<\/td>/s', $rm[2], $cellMatches);
            $cells = $cellMatches[1] ?? [];
            // ترتیب ستون‌ها دقیقاً همون ترتیبیه که توی $postFields درخواست دادیم:
            // چک‌باکس، User ID، Internet Username، Group، ISP، Online، Remote IPs
            if (count($cells) < 7) continue;
            $clean = function ($html) {
                $html = preg_replace('/<br\s*\/?>/i', ', ', $html);
                return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
            };
            $out[] = [
                'uid'             => $uid,
                'normal_username' => $clean($cells[2]),
                'group_name'      => $clean($cells[3]),
                'isp_name'        => $clean($cells[4]),
                'remote_ip'       => $clean($cells[6]),
                'duration_secs'   => -1, // نامشخص - این روش مدت اتصال رو نداره
            ];
        }
    }
    return ['error' => null, 'data' => $out];
}

// ─── کاربران آنلاین یک ISP خاص ───
function ibsng_getOnlineForIsp($ispName) {
    try {
        $result = ibsng_getOnlineRaw();
        if (!empty($result['error'])) return ['error' => $result['error'], 'data' => []];

        $data = $result['data'];
        if ($ispName === '') return ['error' => '', 'data' => $data];

        // ابتدا با isp_name مستقیم فیلتر کن
        $filtered = [];
        foreach ($data as $u) {
            if (($u['isp_name'] ?? '') === $ispName) $filtered[] = $u;
        }

        // اگر isp_name در داده آنلاین نبود، از کش username‌های ISP استفاده کن
        if (empty($filtered) && !empty($data)) {
            $ispUsers = ibsng_getIspUsernameMap($ispName);
            if (!empty($ispUsers)) {
                foreach ($data as $u) {
                    $un = $u['normal_username'] ?? $u['username'] ?? '';
                    if ($un !== '' && isset($ispUsers[$un])) $filtered[] = $u;
                }
            }
        }

        return ['error' => '', 'data' => $filtered];
    } catch (Exception $e) {
        return ['error' => 'خطای سیستم: ' . $e->getMessage(), 'data' => []];
    }
}

// ─── کش username‌های یک ISP (2 دقیقه) ───
function ibsng_getIspUsernameMap($ispName) {
    $cKey = IBS_CACHE_DIR . 'isp_umap_' . md5($ispName) . '.json';
    if (file_exists($cKey) && (time() - filemtime($cKey)) < 120) {
        $c = @json_decode(@file_get_contents($cKey), true);
        if (is_array($c)) return $c;
    }
    try {
        // از pagination کامل استفاده کن
        $uids = ibsng_getAllUidsForIsp(ibsng_ispCond($ispName));
        if (empty($uids)) { @file_put_contents($cKey, '{}'); return []; }
        $map = [];
        foreach (ibsng_getUserInfoBulk($uids) as $u) {
            $un = $u['attrs']['normal_username'] ?? $u['attrs']['username'] ?? '';
            if ($un !== '') $map[$un] = true;
        }
        @file_put_contents($cKey, json_encode($map));
        return $map;
    } catch (Exception $e) {
        return [];
    }
}

// ─── پاک کردن کش یک کاربر ───
function ibsng_clearUserCache($uid) {
    @unlink(IBS_CACHE_DIR . 'u_' . $uid . '.json');
}

// ─── نشست (سشن) HTML پنل اصلی IBSng - مجزا از ibs-api (JSON-RPC). Kick از طریق
// API اصلاً وجود نداره (همه‌ی حدس‌ها شکست خوردن)؛ از خروجی خامِ خودِ پنل ادمین
// IBSng معلوم شد Kick واقعاً یک درخواست GET ساده به
// /IBSng/admin/user/kill_user_by_id.php?user_id=X&kill=1&ajax=1 هست که فقط با
// کوکی سشنِ لاگین‌شده (نه auth_name/auth_pass) قابل انجامه.
function ibsng_nativeCookieFile() {
    return IBS_CACHE_DIR . 'ibsng_native_cookies.txt';
}

function ibsng_nativeHttp($url, $cookieFile, $postFields = null, $headers = []) {
    return ibsng_nativeHttpEx($url, $cookieFile, $postFields, $headers)['body'];
}

// ─── مثل ibsng_nativeHttp ولی اطلاعات تشخیصی بیشتر (URL نهایی بعد از هر
// ریدایرکت، کد HTTP) رو هم برمی‌گردونه - برای تشخیص مشکلاتی مثل ریدایرکت شدن
// به مسیر/دامنه‌ی اشتباه لازمه. ───
function ibsng_nativeHttpEx($url, $cookieFile, $postFields = null, $headers = []) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ];
    if ($postFields !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($postFields);
    }
    if (!empty($headers)) {
        $opts[CURLOPT_HTTPHEADER] = $headers;
    }
    curl_setopt_array($ch, $opts);
    $r = curl_exec($ch);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $r, 'effective_url' => $effectiveUrl, 'http_code' => $httpCode];
}

function ibsng_nativeIsLoggedIn($html) {
    // صفحه‌ی داخلی (بعد از لاگین موفق) لینک Logout داره؛ صفحه‌ی لاگین نداره.
    return $html !== false && stripos($html, 'logout') !== false;
}

function ibsng_nativeLogin() {
    $cookieFile = ibsng_nativeCookieFile();
    $base = rtrim(IBS_URL, '/');
    $check = ibsng_nativeHttp($base . '/admin_index.php', $cookieFile);
    if (ibsng_nativeIsLoggedIn($check)) return true;

    // اسم دقیق فیلدهای فرم لاگین پنل اصلی مستند نیست، چند حدس محتمل رو امتحان می‌کنیم.
    $candidates = [
        ['username' => IBS_ADMIN_USER, 'password' => IBS_ADMIN_PASS],
        ['admin_username' => IBS_ADMIN_USER, 'admin_password' => IBS_ADMIN_PASS],
        ['user' => IBS_ADMIN_USER, 'pass' => IBS_ADMIN_PASS],
        ['login_username' => IBS_ADMIN_USER, 'login_password' => IBS_ADMIN_PASS],
    ];
    foreach ($candidates as $fields) {
        @unlink($cookieFile);
        ibsng_nativeHttp($base . '/index.php', $cookieFile, $fields);
        $check = ibsng_nativeHttp($base . '/admin_index.php', $cookieFile);
        if (ibsng_nativeIsLoggedIn($check)) return true;
    }
    return false;
}

// ─── پاسخ ajax=1 برای kill_user_by_id.php وقتی موفقه یک متن تأییدی مثل
// «User 19605 Kicked Out Successfully» برمی‌گردونه (تأیید شده روی سرور واقعی).
// صرفاً موفق بودن خودِ curl request کافی نیست: اگه نشست منقضی شده باشه، جواب
// می‌تونه صفحه‌ی لاگین یا پیام خطای دیگه‌ای باشه که به اشتباه به‌عنوان موفقیت
// در نظر گرفته می‌شد.
function ibsng_nativeKillLooksSuccessful($resp) {
    return $resp !== false && preg_match('/kick(ed)?\s*out|successfully/i', (string)$resp) === 1;
}

function ibsng_kickUserNative($uid) {
    if (!ibsng_nativeLogin()) {
        return ['error' => 'ورود به پنل اصلی IBSng (برای Kick) ناموفق بود - فیلدهای فرم لاگین حدس‌زده‌شده درست نبودن', 'method' => null];
    }
    $cookieFile = ibsng_nativeCookieFile();
    $base = rtrim(IBS_URL, '/');
    $url  = $base . '/user/kill_user_by_id.php?user_id=' . urlencode($uid) . '&kill=1&ajax=1';
    // خودِ  با پیام صریح مشخص کرد که مشکل واقعی چیه: چون هدر Referer نداشتیم،
    // یک چک ساده‌ی ضدـCSRF/hotlink توی  ("invalid Referrer") درخواست رو رد
    // می‌کرد و به صفحه‌ی اصلی (که صفحه‌ی پیش‌فرض  رو نشون می‌داد) ریدایرکت
    // می‌شدیم. Referer رو دقیقاً به همون صفحه‌ای که این لینک Kill توش قرار داره
    // (صفحه‌ی اطلاعات کاربر) ست می‌کنیم تا مثل یک کلیک واقعی از مرورگر به‌نظر بیاد.
    $headers = [
        'X-Requested-With: XMLHttpRequest',
        'Referer: ' . $base . '/user/user_info.php?user_id=' . urlencode($uid),
    ];
    $res  = ibsng_nativeHttpEx($url, $cookieFile, null, $headers);
    $resp = $res['body'];
    if ($resp === false) return ['error' => 'درخواست Kick ناموفق بود (خطای اتصال)', 'method' => 'native:kill_user_by_id'];

    // اگه پاسخ تأیید موفقیت نداشت، بدون از بین بردن کوکی فعلی (که ممکنه هنوز
    // کاملاً معتبر باشه - حذفش قبلاً باعث می‌شد یک نشست سالم با یک لاگین حدسیِ
    // ناموفق جایگزین بشه و همه‌چیز خراب‌تر بشه) فقط یک بار دیگه از
    // ibsng_nativeLogin (که خودش اول اعتبار کوکی موجود رو با یک درخواست زنده چک
    // می‌کنه و فقط اگه واقعاً نامعتبر بود سراغ فیلدهای حدسی می‌ره) استفاده می‌کنیم.
    if (!ibsng_nativeKillLooksSuccessful($resp) && ibsng_nativeLogin()) {
        $res2 = ibsng_nativeHttpEx($url, $cookieFile, null, $headers);
        if ($res2['body'] !== false) $res = $res2;
        $resp = $res['body'];
    }

    if ($resp === false) return ['error' => 'درخواست Kick ناموفق بود (خطای اتصال)', 'method' => 'native:kill_user_by_id'];
    if (!ibsng_nativeKillLooksSuccessful($resp)) {
        $snippet = trim(substr(strip_tags((string)$resp), 0, 200));
        $effUrl  = $res['effective_url'] ?? '';
        $hint = (stripos($resp, 'apache') !== false || stripos($resp, 'it works') !== false)
            ? ' - درخواست به آدرس دیگه‌ای ریدایرکت شده (' . $effUrl . ')، نه صفحه‌ی واقعی IBSng'
            : ' (احتمالاً نشست منقضی یا user_id نامعتبر)';
        return ['error' => 'پاسخ نامعتبر از IBSng' . $hint . ': ' . ($snippet !== '' ? $snippet : '(پاسخ خالی)'), 'method' => 'native:kill_user_by_id', 'raw' => $resp, 'effective_url' => $effUrl];
    }
    ibsng_clearUserCache($uid);
    return ['error' => null, 'method' => 'native:kill_user_by_id.php', 'raw' => $resp];
}

// ─── Kick کردن (قطع اتصال آنلاین) یک کاربر ───
// راه اصلی: نشست HTML پنل اصلی (بالا). اگه لاگین نشست ناموفق بود (مثلاً فیلدهای
// فرم لاگین درست حدس زده نشده)، به‌عنوان fallback چند حدس handler.method روی
// ibs-api رو هم امتحان می‌کنیم (که در تست‌های قبلی هیچ‌کدوم جواب نداده بودن، ولی
// محض احتیاط نگه داشته شده).
function ibsng_kickUser($uid) {
    $uid = (string)$uid;

    $native = ibsng_kickUserNative($uid);
    if (($native['error'] ?? null) === null) return $native;

    $candidates = [
        'ras.kickUser'          => ['user_id' => $uid],
        'report.kickUser'       => ['user_id' => $uid],
        'user.kickUser'         => ['user_id' => $uid],
        'onlineuser.kickUser'   => ['user_id' => $uid],
        'radius.kickUser'       => ['user_id' => $uid],
        'ras.disconnectUser'    => ['user_id' => $uid],
    ];
    foreach ($candidates as $label => $params) {
        $r = ibsng_call($label, $params);
        $err = $r['error'] ?? null;
        if ($err === null) { ibsng_clearUserCache($uid); return ['error' => null, 'method' => $label]; }
        if (preg_match('/Handler\s*--[^-]*--\s*(has not method|not found)/i', (string)$err)) continue;
        return ['error' => "$label: $err", 'method' => $label];
    }
    return $native;
}

// ─── فرمت مدت اتصال ───
function ibsng_formatDuration($secs) {
    $secs = (int)$secs;
    if ($secs < 0) return '—'; // نامشخص (مثلاً از روش جایگزین native که مدت اتصال رو نداره)
    $h = (int)floor($secs / 3600);
    $m = (int)floor(($secs % 3600) / 60);
    $s = $secs % 60;
    if ($h > 0) return "{$h}h {$m}m";
    if ($m > 0) return "{$m}m {$s}s";
    return "{$s}s";
}

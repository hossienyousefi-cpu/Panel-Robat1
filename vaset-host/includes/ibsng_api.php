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
function ibsng_call($method, $params = [], $cacheSec = 0) {
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
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    if ($curlErr) return ['error' => $curlErr, 'result' => null];
    $result = json_decode($response, true);
    if ($result === null) return ['error' => 'Invalid JSON from IBS API', 'result' => null];
    if (isset($result['error']) && !empty($result['error'])) return $result;

    if ($cacheSec > 0 && isset($cKey)) {
        @file_put_contents($cKey, json_encode($result));
    }
    return $result;
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

// ─── RAS ها با کش 10 دقیقه ───
function ibsng_getRasList() {
    $r = ibsng_call('ras.getAllRasNames', [], 600);
    if (!empty($r['result']) && is_array($r['result'])) return $r['result'];
    // fallback: از طریق کاربران آنلاین یا searchUser نمی‌شه گرفت
    return [];
}

// ─── تعداد کاربران یک ISP با کش 60 ثانیه ───
function ibsng_getIspUserCount($ispName) {
    $r = ibsng_call('user.searchUser', [
        'conds'    => ['isp_name' => [$ispName], 'isp_name_op' => 'equals'],
        'from'     => 0,
        'to'       => 1,
        'order_by' => 'user_id',
        'desc'     => false,
    ], 60);
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
function ibsng_getAllUidsForIsp($conds) {
    $batchSize = 500;
    $from      = 0;
    $allUids   = [];
    // سقف زمانی: اگر شرط isp_name/group_name به هر دلیلی سمت  فیلتر نکند (یا total
    // درست برنگردد)، این حلقه می‌تواند صدها request پشت‌سرهم بزند و کل هاست را برای
    // بقیه‌ی کاربران هم کند/بلاک کند. بعد از ۸ ثانیه با هرچی تا الان جمع شده برمی‌گردیم.
    $deadline = microtime(true) + 8;
    do {
        $r     = ibsng_call('user.searchUser', [
            'conds' => $conds, 'from' => $from, 'to' => $from + $batchSize,
            'order_by' => 'user_id', 'desc' => true,
        ]);
        $total  = (int)($r['result'][0] ?? 0);
        $batch  = $r['result'][2] ?? [];
        if (empty($batch)) break;
        $allUids = array_merge($allUids, $batch);
        $from  += $batchSize;
    } while (count($allUids) < $total && microtime(true) < $deadline);
    return $allUids;
}

// ─── تبدیل یک UID به row ───
function ibsng_uidToRow($uid, $u, $ispName) {
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
        'online'   => false,
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

    $conds = ['isp_name' => [$ispName], 'isp_name_op' => 'equals'];
    if ($groupFilter !== '') $conds['group_name'] = $groupFilter;

    $uids = ibsng_getAllUidsForIsp($conds);
    if (empty($uids)) {
        @unlink($lockFile);
        @file_put_contents($cKey, '[]');
        return [];
    }

    $rows = [];
    foreach (array_chunk($uids, 100) as $chunk) {
        $inf = ibsng_call('user.getUserInfo', ['user_id' => implode(',', $chunk)]);
        foreach ($inf['result'] ?? [] as $uid => $u) {
            $row = ibsng_uidToRow($uid, $u, $ispName);
            if ($row) $rows[] = $row;
        }
    }
    @unlink($lockFile);
    @file_put_contents($cKey, json_encode($rows));
    return $rows;
}

// ─── گرفتن سریع صفحه اول بدون کش کامل ───
// فقط UIDs صفحه اول رو میگیره + getUserInfo - خیلی سریعتر
function ibsng_getIspUsersPage($ispName, $groupFilter, $search, $sortBy, $sortDir, $page, $perPage) {
    if ($ispName === '') return ['total' => 0, 'total_isp' => 0, 'rows' => [], 'cached' => false];

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
    $conds = ['isp_name' => [$ispName], 'isp_name_op' => 'equals'];
    if ($groupFilter !== '') $conds['group_name'] = $groupFilter;

    // تعداد کل
    $rCount = ibsng_call('user.searchUser', ['conds'=>$conds,'from'=>0,'to'=>1,'order_by'=>'user_id','desc'=>true]);
    $totalIsp = (int)($rCount['result'][0] ?? 0);

    // اگه search داریم باید همه رو بگیریم (کند) وگرنه فقط صفحه اول
    if ($search !== '') {
        $allUids = ibsng_getAllUidsForIsp($conds);
        $inf = [];
        foreach (array_chunk($allUids, 100) as $chunk) {
            $r2 = ibsng_call('user.getUserInfo', ['user_id' => implode(',', $chunk)]);
            $inf += $r2['result'] ?? [];
        }
        $allRows = [];
        foreach ($allUids as $uid) {
            $u = $inf[(string)$uid] ?? $inf[$uid] ?? null;
            if (!$u) continue;
            $row = ibsng_uidToRow($uid, $u, $ispName);
            if ($row && stripos($row['username'], $search) !== false) $allRows[] = $row;
        }
        $total = count($allRows);
        return ['total'=>$total,'total_isp'=>$totalIsp,'rows'=>array_slice($allRows,$page*$perPage,$perPage),'cached'=>false];
    }

    // بدون search: فقط صفحه فعلی رو بگیر
    $rPage = ibsng_call('user.searchUser', ['conds'=>$conds,'from'=>$page*$perPage,'to'=>($page+1)*$perPage,'order_by'=>'user_id','desc'=>true]);
    $pageUids = $rPage['result'][2] ?? [];
    $rows = [];
    if (!empty($pageUids)) {
        $inf = ibsng_call('user.getUserInfo', ['user_id' => implode(',', $pageUids)]);
        foreach ($pageUids as $uid) {
            $u = ($inf['result'][(string)$uid] ?? $inf['result'][$uid] ?? null);
            if (!$u) continue;
            $row = ibsng_uidToRow($uid, $u, $ispName);
            if ($row) $rows[] = $row;
        }
    }
    return ['total'=>$totalIsp,'total_isp'=>$totalIsp,'rows'=>$rows,'cached'=>false];
}

function ibsng_searchUsersForReseller($ispName, $search, $group, $page, $perPage = 50) {
    $conds = [];
    if ($ispName !== '') { $conds['isp_name'] = [$ispName]; $conds['isp_name_op'] = 'equals'; }
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
    if (file_exists($cKey) && (time() - filemtime($cKey)) < 20) {
        $c = json_decode(file_get_contents($cKey), true);
        if (is_array($c)) return ['error' => '', 'data' => $c];
    }
    $r = ibsng_call('report.getOnlineUsers', [
        'normal_sort_by' => 'username',
        'normal_desc'    => false,
        'voip_sort_by'   => 'username',
        'voip_desc'      => false,
        'conds'          => [],
    ]);
    if (!empty($r['error'])) return ['error' => (string)$r['error'], 'data' => []];

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
        $uids = ibsng_getAllUidsForIsp(['isp_name' => [$ispName], 'isp_name_op' => 'equals']);
        if (empty($uids)) { @file_put_contents($cKey, '{}'); return []; }
        $map = [];
        foreach (array_chunk($uids, 100) as $chunk) {
            $inf = ibsng_call('user.getUserInfo', ['user_id' => implode(',', $chunk)]);
            foreach ($inf['result'] ?? [] as $u) {
                $un = $u['attrs']['normal_username'] ?? $u['attrs']['username'] ?? '';
                if ($un !== '') $map[$un] = true;
            }
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

// ─── فرمت مدت اتصال ───
function ibsng_formatDuration($secs) {
    $secs = (int)$secs;
    $h = (int)floor($secs / 3600);
    $m = (int)floor(($secs % 3600) / 60);
    $s = $secs % 60;
    if ($h > 0) return "{$h}h {$m}m";
    if ($m > 0) return "{$m}m {$s}s";
    return "{$s}s";
}

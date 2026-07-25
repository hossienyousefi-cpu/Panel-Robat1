<?php
// منطق ربات تلگرام مشتریان مستقیم + پنل کنترلی ادمین در تلگرام.
// telegram/webhook.php این فایل را require می‌کند و به‌ازای هر update ورودی
// tg_handle_update() را صدا می‌زند. نیازمند config.php + ibsng_api.php +
// telegram_api.php + db_backup.php (همه قبل از این فایل require شده‌اند).

// ───────────────────────────── ورودی اصلی ─────────────────────────────
// $resellerId=0 یعنی بات اصلی/سراسری پنل (مثل قبل)؛ >0 یعنی بات اختصاصی همون
// ریسلر (telegram/webhook.php این عدد رو از پارامتر ?r= آدرس وبهوک تشخیص
// می‌ده و از همینجا به همه‌ی توابع پایین‌دستی پاس می‌ده تا مشتری/سفارش/پکیج/
// موجودی هرکدوم کاملاً جدا از بقیه بمونه).
function tg_handle_update(array $update, int $resellerId = 0) {
    if (isset($update['callback_query'])) {
        tg_handle_callback($update['callback_query'], $resellerId);
        return;
    }
    if (isset($update['message'])) {
        tg_handle_message($update['message'], $resellerId);
        return;
    }
}

// ───────────────────────────── کمکی‌های مشترک ─────────────────────────────
// ba_is_admin_chat/ba_is_owner_chat/ba_all_admin_chat_ids (از includes/bot_admins.php)
// جای این تشخیص‌ها رو گرفته‌ن (چند ادمین به‌ازای هر بات)؛ فقط تشخیص «ادمین
// واقعی پنل» (نه ادمین اضافه‌ی bot_admins) هنوز لازمه - برای ستون FK
// reviewed_by و دستورهای حساس /export و /import.
function tg_admin_id_by_chat($chatId): ?int {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE telegram_chat_id = ?");
    $stmt->execute([(string)$chatId]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

// تلگرام رنگ واقعی به دکمه‌ها نمی‌ده (نه Reply Keyboard نه Inline)، پس برای
// حس «دکمه‌ی رنگی» از یک دایره‌ی رنگی به‌عنوان پیشوند هر دکمه استفاده می‌کنیم -
// ترفند رایج بات‌های تلگرامی برای متمایز کردن بصریِ گزینه‌ها. دکمه‌ی دانلود
// کانفیگ OpenVPN فقط وقتی نشون داده می‌شه که حداقل یک فایل برای این بات
// (یا برای بات اصلی) آپلود شده باشه.
function tg_main_keyboard(int $resellerId = 0): array {
    $rows = [
        ['🟢 خرید سرویس جدید', '🔵 تمدید سرویس'],
        ['🟣 سرویس‌های من', '🟡 اطلاعات پرداخت'],
        ['🔴 پشتیبانی'],
    ];
    if (tg_list_ovpn_files($resellerId)) $rows[] = ['🟠 دانلود کانفیگ OpenVPN'];
    return ['keyboard' => $rows, 'resize_keyboard' => true];
}

// ─── دایره‌های رنگی که به‌ترتیب برای شماره‌گذاری بصری پکیج‌ها/سرویس‌ها توی
// دکمه‌های inline استفاده می‌شن (فقط ظاهری - تلگرام رنگ واقعی روی دکمه نداره) ─
function tg_color_dot(int $i): string {
    static $dots = ['🟢', '🔵', '🟣', '🟡', '🟠', '🔴', '⚪️', '🟤'];
    return $dots[$i % count($dots)];
}

// ─── اسم فروشگاه: برای بات ریسلر از shop_name خودش (یا در نبودش از اسم
// کاربری‌اش)، برای بات اصلی از تنظیمات سراسری سایت ───
function tg_shop_name(int $resellerId): string {
    global $pdo;
    if ($resellerId > 0) {
        $stmt = $pdo->prepare("SELECT rb.shop_name, r.username, r.full_name FROM reseller_bots rb
            JOIN resellers r ON r.id = rb.reseller_id WHERE rb.reseller_id=?");
        $stmt->execute([$resellerId]);
        $row = $stmt->fetch();
        if ($row) {
            if (!empty($row['shop_name'])) return $row['shop_name'];
            if (!empty($row['full_name'])) return $row['full_name'];
            return $row['username'] ?? 'فروشگاه اینترنت';
        }
        return 'فروشگاه اینترنت';
    }
    return getSetting('site_name', 'فروشگاه اینترنت');
}

// ─── پیشوند یوزرنیم خودکار یک ریسلر (تنظیم‌شده توی reseller/telegram.php).
// اگه خالی باشه یعنی این ریسلر هنوز از حالت قدیمی (خودِ مشتری یوزرنیم
// انتخاب می‌کنه) استفاده می‌کنه. بات اصلی (resellerId=0) پیشوند نداره. ───
function tg_username_prefix(int $resellerId): string {
    if ($resellerId <= 0) return '';
    global $pdo;
    $stmt = $pdo->prepare("SELECT username_prefix FROM reseller_bots WHERE reseller_id=?");
    $stmt->execute([$resellerId]);
    $v = $stmt->fetchColumn();
    return $v ?: '';
}

// ─── اولین یوزرنیمِ آزادِ prefix+شماره (مثلاً ars1، ars2، ...). اول از
// جدول محلی users بزرگ‌ترین شماره‌ی قبلاً استفاده‌شده رو پیدا می‌کنه (سریع،
// بدون تماس با IBSng)، بعد فقط برای همون یکی/دو تا کاندیدای بعدی (نه از
// صفر) با IBSng چک می‌کنه که واقعاً آزاده - برای اطمینان از اینکه کاربری که
// شاید مستقیم توی IBSng یا از پنل وب ساخته شده رو دوباره نساخته باشیم. ───
function tg_next_username(string $prefix, int $resellerId): string {
    global $pdo;
    $stmt = $pdo->prepare("SELECT username FROM users WHERE reseller_id=? AND username LIKE ? ORDER BY id DESC LIMIT 500");
    $stmt->execute([$resellerId, $prefix . '%']);
    $max = 0;
    $pattern = '/^' . preg_quote($prefix, '/') . '(\d+)$/';
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $un) {
        if (preg_match($pattern, $un, $m)) $max = max($max, (int)$m[1]);
    }
    $n = $max + 1;
    for ($i = 0; $i < 50; $i++) {
        $candidate = $prefix . $n;
        $chk = ibsng_call('user.doesUserExists', ['normal_username' => $candidate]);
        if (empty($chk['result'])) return $candidate;
        $n++;
    }
    return $prefix . $n;
}

// ─── متن خوش‌آمدگویی /start: اگه ریسلر پیام سفارشی نوشته باشه همون، وگرنه
// یک قالب پیش‌فرض خوشگل با اسم فروشگاه و اسم مشتری ───
function tg_welcome_message(int $resellerId, string $name): string {
    global $pdo;
    $shop = htmlspecialchars(tg_shop_name($resellerId), ENT_QUOTES, 'UTF-8');
    $nameSafe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    if ($resellerId > 0) {
        $stmt = $pdo->prepare("SELECT welcome_message FROM reseller_bots WHERE reseller_id=?");
        $stmt->execute([$resellerId]);
        $custom = $stmt->fetchColumn();
        if (!empty($custom)) {
            return "🎉 <b>{$shop}</b>\n\n" . htmlspecialchars($custom, ENT_QUOTES, 'UTF-8')
                . "\n\n👇 از منوی زیر یکی رو انتخاب کن:";
        }
    }
    return "🎉 سلام {$nameSafe} 👋\nبه <b>{$shop}</b> خوش آمدید!\n\n✨ از منوی زیر یکی از گزینه‌ها رو انتخاب کن:";
}

// رمز ۴ رقمی عددی (به‌جای حروف/عدد ترکیبی قبلی) - ساده‌تر برای تایپ کردن
// مشتری‌هایی که از تلگرام خرید می‌کنن.
function tg_generate_password(int $len = 4): string {
    $pw = '';
    for ($i = 0; $i < $len; $i++) $pw .= (string)random_int(0, 9);
    return $pw;
}

// چون chat_id تلگرام همون آیدی عددی کاربره (نه چیزی مخصوص یک بات)، اگه یک نفر
// هم با بات اصلی هم با بات یک ریسلر حرف بزنه، توی هر دو یک chat_id یکسان
// داره - برای همین رکورد مشتری باید جدا-جدا به‌ازای هر ریسلر (reseller_id=0
// یعنی بات اصلی) نگه‌داری بشه، وگرنه state/سرویس‌های دو بات با هم قاطی می‌شد.
function tg_get_or_create_customer($chatId, ?string $tgUsername, ?string $fullName, int $resellerId = 0): array {
    global $pdo;
    $chatId = (string)$chatId;
    $stmt = $pdo->prepare("SELECT * FROM telegram_customers WHERE chat_id=? AND reseller_id=?");
    $stmt->execute([$chatId, $resellerId]);
    $c = $stmt->fetch();
    if ($c) {
        $pdo->prepare("UPDATE telegram_customers SET tg_username=?, full_name=? WHERE id=?")
            ->execute([$tgUsername, $fullName, $c['id']]);
        $c['tg_username'] = $tgUsername;
        $c['full_name'] = $fullName;
        return $c;
    }
    $pdo->prepare("INSERT INTO telegram_customers (chat_id, reseller_id, tg_username, full_name) VALUES (?,?,?,?)")
        ->execute([$chatId, $resellerId, $tgUsername, $fullName]);
    $stmt->execute([$chatId, $resellerId]);
    return $stmt->fetch();
}

function tg_set_state(int $customerId, ?string $state, ?array $data = null): void {
    global $pdo;
    $pdo->prepare("UPDATE telegram_customers SET state=?, state_data=? WHERE id=?")
        ->execute([$state, $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, $customerId]);
}

function tg_get_state_data(array $customer): array {
    if (empty($customer['state_data'])) return [];
    $decoded = json_decode($customer['state_data'], true);
    return is_array($decoded) ? $decoded : [];
}

// ─── منبع پکیج‌ها: برای بات اصلی (reseller_id=0) همون جدول سراسری
// direct_packages، برای بات یک ریسلر مستقیم از خودِ reseller_groups (همون
// گروه/قیمتی که ریسلر توی پنل وب برای ساخت کاربر تنظیم کرده - نیازی به تعریف
// جدا برای بات نیست، isp هم isp اختصاصی خودِ ریسلره). قیمت ۰ یعنی «قیمت
// پیش‌فرض سیستم» (دقیقاً مثل ساخت دستی کاربر توی reseller/users.php). ───
// ─── 'price' یعنی همیشه چیزی که به مشتریِ بات نشون داده/ازش گرفته می‌شه
// (customer_price اگه ریسلر تنظیم کرده باشه، وگرنه همون قیمت پایه)؛ 'cost_price'
// یعنی هزینه‌ی واقعی خودِ ریسلر که فقط برای کسر از موجودی/تراکنش‌های داخلی
// استفاده می‌شه، هیچ‌وقت به مشتری نشون داده نمی‌شه - مابه‌التفاوتشون سود ریسلره ───
function tg_list_packages(int $resellerId = 0): array {
    global $pdo;
    if ($resellerId > 0) {
        $stmt = $pdo->prepare("SELECT rg.id, rg.group_name, rg.price, rg.customer_price, r.isp_name FROM reseller_groups rg
            JOIN resellers r ON r.id = rg.reseller_id WHERE rg.reseller_id=? ORDER BY rg.group_name");
        $stmt->execute([$resellerId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $cost = (float)$row['price'] > 0 ? (float)$row['price'] : (float)getSetting('user_create_price', 5000);
            $customerPrice = (float)($row['customer_price'] ?? 0) > 0 ? (float)$row['customer_price'] : $cost;
            $out[] = ['id' => (int)$row['id'], 'group_name' => $row['group_name'], 'title' => $row['group_name'],
                'price' => $customerPrice, 'cost_price' => $cost, 'isp_name' => $row['isp_name'], 'is_active' => 1];
        }
        return $out;
    }
    return $pdo->query("SELECT * FROM direct_packages WHERE is_active=1 ORDER BY sort_order, id")->fetchAll();
}

function tg_get_package(int $id, int $resellerId = 0): ?array {
    global $pdo;
    if ($resellerId > 0) {
        $stmt = $pdo->prepare("SELECT rg.id, rg.group_name, rg.price, rg.customer_price, r.isp_name FROM reseller_groups rg
            JOIN resellers r ON r.id = rg.reseller_id WHERE rg.id=? AND rg.reseller_id=?");
        $stmt->execute([$id, $resellerId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $cost = (float)$row['price'] > 0 ? (float)$row['price'] : (float)getSetting('user_create_price', 5000);
        $customerPrice = (float)($row['customer_price'] ?? 0) > 0 ? (float)$row['customer_price'] : $cost;
        return ['id' => (int)$row['id'], 'group_name' => $row['group_name'], 'title' => $row['group_name'],
            'price' => $customerPrice, 'cost_price' => $cost, 'isp_name' => $row['isp_name'], 'is_active' => 1];
    }
    $stmt = $pdo->prepare("SELECT * FROM direct_packages WHERE id=?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function tg_get_package_by_group(string $groupName, int $resellerId = 0): ?array {
    global $pdo;
    if ($resellerId > 0) {
        $stmt = $pdo->prepare("SELECT rg.id, rg.group_name, rg.price, rg.customer_price, r.isp_name FROM reseller_groups rg
            JOIN resellers r ON r.id = rg.reseller_id WHERE rg.reseller_id=? AND rg.group_name=?");
        $stmt->execute([$resellerId, $groupName]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $cost = (float)$row['price'] > 0 ? (float)$row['price'] : (float)getSetting('user_create_price', 5000);
        $customerPrice = (float)($row['customer_price'] ?? 0) > 0 ? (float)$row['customer_price'] : $cost;
        return ['id' => (int)$row['id'], 'group_name' => $row['group_name'], 'title' => $row['group_name'],
            'price' => $customerPrice, 'cost_price' => $cost, 'isp_name' => $row['isp_name'], 'is_active' => 1];
    }
    $stmt = $pdo->prepare("SELECT * FROM direct_packages WHERE group_name=?");
    $stmt->execute([$groupName]);
    $r = $stmt->fetch();
    return $r ?: null;
}

// ─── متن کارت پرداخت: اول حساب‌های ساختاریافته‌ی payment_accounts (اگه یکی
// «فعال» باشه) در اولویته، وگرنه به متن آزاد قدیمی (reseller_bots برای بات
// ریسلر، settings سراسری برای بات اصلی) برمی‌گرده - سازگار با تنظیمات قبلی ───
function tg_payment_card_info(int $resellerId): string {
    $active = pa_getActiveText($resellerId);
    if ($active !== null) return $active;

    if ($resellerId > 0) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT payment_card_info FROM reseller_bots WHERE reseller_id=?");
        $stmt->execute([$resellerId]);
        $v = $stmt->fetchColumn();
        return ($v !== false && $v !== null && $v !== '') ? $v : 'هنوز تنظیم نشده.';
    }
    return getSetting('payment_card_info', 'هنوز تنظیم نشده.');
}

function tg_support_message(int $resellerId): string {
    if ($resellerId > 0) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT support_message FROM reseller_bots WHERE reseller_id=?");
        $stmt->execute([$resellerId]);
        $v = $stmt->fetchColumn();
        return ($v !== false && $v !== null && $v !== '') ? $v : 'هنوز تنظیم نشده.';
    }
    return getSetting('support_contact_message', 'هنوز تنظیم نشده.');
}

function tg_extract_receipt_file_id(array $msg): ?string {
    if (!empty($msg['photo']) && is_array($msg['photo'])) {
        $photos = $msg['photo'];
        $last = end($photos);
        return $last['file_id'] ?? null;
    }
    if (!empty($msg['document']['file_id'])) {
        return $msg['document']['file_id'];
    }
    return null;
}

// ───────────────────────────── دانلود کانفیگ OpenVPN ─────────────────────────────
function tg_list_ovpn_files(int $resellerId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM ovpn_files WHERE reseller_id=? ORDER BY sort_order, id");
    $stmt->execute([$resellerId]);
    return $stmt->fetchAll();
}

function tg_show_ovpn_list(array $customer, int $resellerId): void {
    $chatId = $customer['chat_id'];
    $files = tg_list_ovpn_files($resellerId);
    if (!$files) { tg_sendMessage($chatId, 'در حال حاضر فایل کانفیگی برای دانلود ثبت نشده.'); return; }
    if (count($files) === 1) { tg_send_ovpn_file($chatId, (int)$files[0]['id'], null, $resellerId); return; }
    $buttons = [];
    foreach ($files as $i => $f) {
        $dot = tg_color_dot($i);
        $buttons[] = [['text' => "{$dot} " . $f['title'], 'callback_data' => 'ovpn_' . $f['id']]];
    }
    tg_sendMessage($chatId, '🔧 <b>کدام سرور را می‌خواهید؟</b>', ['inline_keyboard' => $buttons]);
}

function tg_send_ovpn_file($chatId, int $fileId, ?string $cqId, int $resellerId): void {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM ovpn_files WHERE id=? AND reseller_id=?");
    $stmt->execute([$fileId, $resellerId]);
    $f = $stmt->fetch();
    if ($cqId !== null) tg_answerCallbackQuery($cqId);
    if (!$f) { tg_sendMessage($chatId, 'این فایل دیگر در دسترس نیست.'); return; }
    $path = dirname(__DIR__) . '/' . $f['file_path'];
    if (!is_file($path)) { tg_sendMessage($chatId, 'فایل روی سرور پیدا نشد. با پشتیبانی تماس بگیرید.'); return; }
    tg_sendDocumentFile($chatId, $path, '🔧 ' . $f['title']);
}

// ───────────────────────────── پیام‌های عادی ─────────────────────────────
function tg_handle_message(array $msg, int $resellerId = 0): void {
    $chatId = $msg['chat']['id'] ?? null;
    if ($chatId === null) return;
    $from = $msg['from'] ?? [];
    $tgUsername = $from['username'] ?? null;
    $fullName = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: null;

    // نکته‌ی مهم: صاحب بات (ادمین/ریسلر) با همون chat_id شخصی‌اش ممکنه بخواد
    // تجربه‌ی مشتری (دکمه‌ها، خرید، چت پشتیبانی) رو هم با همون اکانت تلگرام
    // خودش تست کنه. قبلاً اینجا هر پیامی از این chat_id بی‌قیدوشرط جذب می‌شد
    // و دیگه هیچ‌وقت به جریان مشتری (پایین همین تابع) نمی‌رسید - یعنی صاحب بات
    // اصلاً نمی‌تونست /start بزنه و منوی مشتری رو ببینه. برای همین فقط وقتی
    // پیام واقعاً یک دستور شناخته‌شده‌ی ادمین (یا Reply به پیام یک مشتری) باشه
    // همینجا مدیریت و return می‌شه؛ در غیر این صورت (مثلاً /start یا دکمه‌های
    // منو) اجازه می‌دیم جریان عادی مشتری پایین همین تابع ادامه پیدا کنه.
    if (ba_is_admin_chat($resellerId, $chatId)) {
        if (tg_try_relay_owner_reply($resellerId, $chatId, $msg)) return;
        $handled = $resellerId === 0
            ? tg_handle_admin_message($chatId, $msg)
            : tg_handle_reseller_owner_message($resellerId, $chatId, $msg);
        if ($handled) return;
    }

    $customer = tg_get_or_create_customer($chatId, $tgUsername, $fullName, $resellerId);
    if (!empty($customer['is_blocked'])) return;

    $text = trim($msg['text'] ?? '');

    if ($text === '/start') {
        tg_set_state((int)$customer['id'], null, null);
        $name = $fullName ?: 'دوست عزیز';
        tg_sendMessage($chatId, tg_welcome_message($resellerId, $name), tg_main_keyboard($resellerId));
        return;
    }

    if ($text === '🟢 خرید سرویس جدید') { tg_start_new_purchase($customer, $resellerId); return; }
    if ($text === '🔵 تمدید سرویس') { tg_start_renew($customer, $resellerId); return; }
    if ($text === '🟣 سرویس‌های من') { tg_show_my_services($customer); return; }
    if ($text === '🟡 اطلاعات پرداخت') { tg_sendMessage($chatId, "💳 <b>اطلاعات پرداخت</b>\n\n" . htmlspecialchars(tg_payment_card_info($resellerId), ENT_QUOTES, 'UTF-8')); return; }
    if ($text === '🔴 پشتیبانی') {
        tg_set_state((int)$customer['id'], 'support_chat', null);
        tg_sendMessage($chatId, "🆘 <b>پشتیبانی</b>\n\n" . htmlspecialchars(tg_support_message($resellerId), ENT_QUOTES, 'UTF-8') . "\n\n💬 پیام خودتون رو همینجا بنویسید، به زودی پاسخ داده می‌شه:");
        return;
    }
    if ($text === '🟠 دانلود کانفیگ OpenVPN') { tg_show_ovpn_list($customer, $resellerId); return; }

    $state = $customer['state'];

    if ($state === 'awaiting_new_username' && $text !== '') {
        tg_receive_new_username($customer, $text, $resellerId);
        return;
    }

    if (in_array($state, ['awaiting_new_receipt', 'awaiting_renew_receipt'], true)) {
        $fileId = tg_extract_receipt_file_id($msg);
        if ($fileId !== null) { tg_receive_receipt($customer, $fileId, $resellerId); return; }
        tg_sendMessage($chatId, 'لطفاً تصویر یا فایل رسید پرداخت را ارسال کنید.');
        return;
    }

    if ($state === 'support_chat' && $text !== '') {
        tg_relay_customer_message($customer, $text, $resellerId);
        return;
    }

    tg_sendMessage($chatId, 'از دکمه‌های زیر استفاده کنید:', tg_main_keyboard($resellerId));
}

// ───────────────────────────── خرید سرویس جدید ─────────────────────────────
function tg_start_new_purchase(array $customer, int $resellerId = 0): void {
    $chatId = $customer['chat_id'];
    $packages = tg_list_packages($resellerId);
    if (!$packages) {
        tg_sendMessage($chatId, 'در حال حاضر بسته‌ای برای فروش تعریف نشده. لطفاً با پشتیبانی تماس بگیرید.');
        return;
    }
    $buttons = [];
    foreach ($packages as $i => $p) {
        $dot = tg_color_dot($i);
        $buttons[] = [['text' => "{$dot} {$p['title']} — " . money((float)$p['price']) . ' تومان', 'callback_data' => 'pkg_' . $p['id']]];
    }
    tg_set_state((int)$customer['id'], null, null);
    tg_sendMessage($chatId, "📦 <b>یکی از بسته‌های زیر را انتخاب کنید:</b>", ['inline_keyboard' => $buttons]);
}

function tg_customer_pick_package($chatId, int $pkgId, string $cqId, int $resellerId = 0): void {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM telegram_customers WHERE chat_id=? AND reseller_id=?");
    $stmt->execute([(string)$chatId, $resellerId]);
    $customer = $stmt->fetch();
    if (!$customer) { tg_answerCallbackQuery($cqId); return; }

    $pkg = tg_get_package($pkgId, $resellerId);
    if (!$pkg || !$pkg['is_active']) { tg_answerCallbackQuery($cqId, 'این بسته دیگر در دسترس نیست', true); return; }

    tg_answerCallbackQuery($cqId);
    $titleSafe = htmlspecialchars($pkg['title'], ENT_QUOTES, 'UTF-8');

    // اگه ریسلر پیشوند یوزرنیم تنظیم کرده باشه (مثلاً ars)، دیگه از مشتری
    // خواسته نمی‌شه یوزرنیم انتخاب کنه - خودِ بات به‌ترتیب می‌سازه (ars1،
    // ars2، ...) و مستقیم می‌ره سراغ مرحله‌ی پرداخت.
    $prefix = tg_username_prefix($resellerId);
    if ($prefix !== '') {
        $username = tg_next_username($prefix, $resellerId);
        tg_set_state((int)$customer['id'], 'awaiting_new_receipt', ['package_id' => (int)$pkg['id'], 'username' => $username]);
        $amount = (float)$pkg['price'];
        $card = htmlspecialchars(tg_payment_card_info($resellerId), ENT_QUOTES, 'UTF-8');
        $usernameSafe = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        tg_sendMessage($chatId, "✅ بسته انتخابی: <b>{$titleSafe}</b>\n👤 نام کاربری شما: <code>{$usernameSafe}</code>\n💰 مبلغ قابل پرداخت: <b>" . money($amount) . "</b> تومان\n\n💳 <b>اطلاعات پرداخت</b>\n{$card}\n\n📸 بعد از پرداخت، تصویر رسید را همین‌جا ارسال کنید.");
        return;
    }

    tg_set_state((int)$customer['id'], 'awaiting_new_username', ['package_id' => (int)$pkg['id']]);
    tg_sendMessage($chatId, "✅ بسته انتخابی: <b>{$titleSafe}</b>\n\n✏️ یک نام کاربری انگلیسی برای سرویس خود انتخاب کنید\n<i>(فقط حروف/عدد/آندرلاین، ۳ تا ۲۰ کاراکتر)</i>:");
}

function tg_receive_new_username(array $customer, string $username, int $resellerId = 0): void {
    $chatId = $customer['chat_id'];
    $username = trim($username);
    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        tg_sendMessage($chatId, '❌ نام کاربری نامعتبر است. فقط حروف انگلیسی، عدد و _ مجاز است (۳ تا ۲۰ کاراکتر). دوباره وارد کنید:');
        return;
    }
    $chk = ibsng_call('user.doesUserExists', ['normal_username' => $username]);
    if ($chk['result'] ?? false) {
        tg_sendMessage($chatId, '❌ این نام کاربری قبلاً استفاده شده. نام دیگری وارد کنید:');
        return;
    }

    $data = tg_get_state_data($customer);
    $data['username'] = $username;
    tg_set_state((int)$customer['id'], 'awaiting_new_receipt', $data);

    $pkg = tg_get_package((int)($data['package_id'] ?? 0), $resellerId);
    $amount = $pkg ? (float)$pkg['price'] : 0;
    $card = htmlspecialchars(tg_payment_card_info($resellerId), ENT_QUOTES, 'UTF-8');
    $usernameSafe = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    tg_sendMessage($chatId, "👤 نام کاربری: <b>{$usernameSafe}</b>\n💰 مبلغ قابل پرداخت: <b>" . money($amount) . "</b> تومان\n\n💳 <b>اطلاعات پرداخت</b>\n{$card}\n\n📸 بعد از پرداخت، تصویر رسید را همین‌جا ارسال کنید.");
}

// ───────────────────────────── تمدید سرویس ─────────────────────────────
function tg_start_renew(array $customer, int $resellerId = 0): void {
    global $pdo;
    $chatId = $customer['chat_id'];
    $links = $pdo->prepare("SELECT * FROM telegram_user_links WHERE telegram_customer_id=? ORDER BY id DESC");
    $links->execute([$customer['id']]);
    $links = $links->fetchAll();
    if (!$links) { tg_sendMessage($chatId, '📭 شما سرویس فعالی برای تمدید ثبت‌شده در ربات ندارید.'); return; }

    $buttons = [];
    foreach ($links as $i => $l) {
        $dot = tg_color_dot($i);
        $buttons[] = [['text' => "{$dot} {$l['ibs_username']} ({$l['group_name']})", 'callback_data' => 'renew_' . $l['id']]];
    }
    tg_sendMessage($chatId, '🔁 <b>کدام سرویس را می‌خواهید تمدید کنید؟</b>', ['inline_keyboard' => $buttons]);
}

function tg_customer_pick_renew($chatId, int $linkId, string $cqId, int $resellerId = 0): void {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM telegram_customers WHERE chat_id=? AND reseller_id=?");
    $stmt->execute([(string)$chatId, $resellerId]);
    $customer = $stmt->fetch();
    if (!$customer) { tg_answerCallbackQuery($cqId); return; }

    $link = $pdo->prepare("SELECT * FROM telegram_user_links WHERE id=? AND telegram_customer_id=?");
    $link->execute([$linkId, $customer['id']]);
    $link = $link->fetch();
    if (!$link) { tg_answerCallbackQuery($cqId, 'یافت نشد', true); return; }

    $pkg = tg_get_package_by_group($link['group_name'], $resellerId);
    if (!$pkg) {
        tg_answerCallbackQuery($cqId);
        tg_sendMessage($chatId, 'قیمت تمدید برای این گروه هنوز تعریف نشده. لطفاً با پشتیبانی تماس بگیرید.');
        return;
    }

    tg_set_state((int)$customer['id'], 'awaiting_renew_receipt', [
        'username' => $link['ibs_username'],
        'amount'   => (float)$pkg['price'],
        'link_id'  => (int)$link['id'],
    ]);
    tg_answerCallbackQuery($cqId);
    $card = htmlspecialchars(tg_payment_card_info($resellerId), ENT_QUOTES, 'UTF-8');
    $unSafe = htmlspecialchars($link['ibs_username'], ENT_QUOTES, 'UTF-8');
    tg_sendMessage($chatId, "🔁 تمدید سرویس: <b>{$unSafe}</b>\n💰 مبلغ: <b>" . money((float)$pkg['price']) . "</b> تومان\n\n💳 <b>اطلاعات پرداخت</b>\n{$card}\n\n📸 بعد از پرداخت، تصویر رسید را ارسال کنید.");
}

// ───────────────────────────── دریافت رسید (مشترک بین خرید و تمدید) ─────────────────────────────
function tg_receive_receipt(array $customer, string $fileId, int $resellerId = 0): void {
    global $pdo;
    $chatId = $customer['chat_id'];
    $file = tg_getFile($fileId);
    $filePath = $file['result']['file_path'] ?? null;
    if (!$filePath) { tg_sendMessage($chatId, 'خطا در دریافت فایل، دوباره تلاش کنید.'); return; }

    $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
    $localName = 'tg_' . preg_replace('/[^0-9]/', '', (string)$customer['chat_id']) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = dirname(__DIR__) . '/uploads/receipts/' . $localName;
    if (!tg_downloadFile($filePath, $dest)) { tg_sendMessage($chatId, 'خطا در دانلود فایل، دوباره تلاش کنید.'); return; }

    $data = tg_get_state_data($customer);
    $orderId = null;
    $summary = '';

    if ($customer['state'] === 'awaiting_new_receipt') {
        $pkg = tg_get_package((int)($data['package_id'] ?? 0), $resellerId);
        if (!$pkg) { tg_sendMessage($chatId, 'خطا: بسته یافت نشد. دوباره از منو شروع کنید.'); tg_set_state((int)$customer['id'], null, null); return; }
        $stmt = $pdo->prepare("INSERT INTO telegram_orders (telegram_customer_id, reseller_id, order_type, package_id, target_username, amount, receipt_file, status) VALUES (?,?,?,?,?,?,?,'pending')");
        $stmt->execute([$customer['id'], $resellerId, 'new', $pkg['id'], $data['username'], $pkg['price'], $localName]);
        $orderId = (int)$pdo->lastInsertId();
        $who = htmlspecialchars($customer['tg_username'] ? '@' . $customer['tg_username'] : ($customer['full_name'] ?: $chatId), ENT_QUOTES, 'UTF-8');
        $titleSafe = htmlspecialchars($pkg['title'], ENT_QUOTES, 'UTF-8');
        $unSafe = htmlspecialchars($data['username'], ENT_QUOTES, 'UTF-8');
        $summary = "🛒 <b>سفارش جدید #{$orderId}</b>\n👤 مشتری: {$who}\n📦 بسته: {$titleSafe}\n✏️ یوزرنیم درخواستی: <b>{$unSafe}</b>\n💰 مبلغ: <b>" . money((float)$pkg['price']) . '</b> تومان';
    } elseif ($customer['state'] === 'awaiting_renew_receipt') {
        $stmt = $pdo->prepare("INSERT INTO telegram_orders (telegram_customer_id, reseller_id, order_type, target_username, amount, receipt_file, status) VALUES (?,?,?,?,?,?,'pending')");
        $stmt->execute([$customer['id'], $resellerId, 'renew', $data['username'], $data['amount'], $localName]);
        $orderId = (int)$pdo->lastInsertId();
        $who = htmlspecialchars($customer['tg_username'] ? '@' . $customer['tg_username'] : ($customer['full_name'] ?: $chatId), ENT_QUOTES, 'UTF-8');
        $unSafe = htmlspecialchars($data['username'], ENT_QUOTES, 'UTF-8');
        $summary = "🔁 <b>سفارش تمدید #{$orderId}</b>\n👤 مشتری: {$who}\n✏️ یوزرنیم: <b>{$unSafe}</b>\n💰 مبلغ: <b>" . money((float)$data['amount']) . '</b> تومان';
    } else {
        return;
    }

    tg_set_state((int)$customer['id'], null, null);
    tg_sendMessage($chatId, "✅ <b>رسید شما ثبت شد.</b>\nپس از بررسی، نتیجه اطلاع‌رسانی می‌شود ⏳", tg_main_keyboard());

    $kb = ['inline_keyboard' => [[
        ['text' => '✅ تأیید', 'callback_data' => "ord_approve_{$orderId}"],
        ['text' => '❌ رد', 'callback_data' => "ord_reject_{$orderId}"],
    ]]];
    // کارت تأیید/رد برای «همه‌ی ادمین‌های این بات» فرستاده می‌شه (صاحب بات +
    // هر ادمین اضافه‌ای که از reseller/telegram.php یا admin/telegram.php
    // تعریف شده) - هرکدوم اول تأیید/رد کنه کافیه.
    $adminChats = ba_all_admin_chat_ids($resellerId);
    if (empty($adminChats)) {
        error_log("[tg_receive_receipt] reseller_id={$resellerId} order#{$orderId}: هیچ ادمینی برای این بات Chat ID ثبت نکرده - سفارش فقط توی پنل وب قابل بررسیه.");
    }
    foreach ($adminChats as $adminChatId) {
        $r = tg_sendPhotoByFileId($adminChatId, $fileId, $summary, $kb);
        if (!($r['ok'] ?? false)) error_log("[tg_receive_receipt] reseller_id={$resellerId} order#{$orderId}: ارسال کارت سفارش به ادمین ({$adminChatId}) ناموفق بود: " . ($r['description'] ?? json_encode($r)));
    }
}

// ───────────────────────────── سرویس‌های من ─────────────────────────────
function tg_show_my_services(array $customer): void {
    global $pdo;
    $chatId = $customer['chat_id'];
    $links = $pdo->prepare("SELECT * FROM telegram_user_links WHERE telegram_customer_id=? ORDER BY id DESC");
    $links->execute([$customer['id']]);
    $links = $links->fetchAll();
    if (!$links) { tg_sendMessage($chatId, '📭 شما هنوز سرویسی از این ربات نخریده‌اید.'); return; }

    $lines = ['📋 <b>سرویس‌های شما</b>', ''];
    foreach ($links as $l) {
        $exp = '-';
        $status = '-';
        $password = null;
        if ($l['ibs_uid']) {
            $inf = ibsng_call('user.getUserInfo', ['user_id' => $l['ibs_uid']]);
            $basic = $inf['result'][$l['ibs_uid']]['basic_info'] ?? [];
            $attrs = $inf['result'][$l['ibs_uid']]['attrs'] ?? [];
            $exp = !empty($basic['nearest_exp_date']) ? substr($basic['nearest_exp_date'], 0, 10) : '∞';
            $status = $basic['status'] ?? '-';
            $password = $attrs['normal_password'] ?? null;
        }
        $statusDot = $status === 'Recharged' ? '🟢' : ($status === 'Disable' ? '🔴' : '⚪️');
        $unSafe = htmlspecialchars($l['ibs_username'], ENT_QUOTES, 'UTF-8');
        $grpSafe = htmlspecialchars($l['group_name'], ENT_QUOTES, 'UTF-8');
        $pwLine = $password ? "\n🔑 رمز: <code>" . htmlspecialchars($password, ENT_QUOTES, 'UTF-8') . '</code>' : '';
        $lines[] = "👤 <b>{$unSafe}</b> ({$grpSafe}){$pwLine}\n{$statusDot} وضعیت: {$status} | 📅 انقضا: {$exp}";
    }
    tg_sendMessage($chatId, implode("\n\n", $lines));
}

// ───────────────────────────── چت پشتیبانی دوطرفه ─────────────────────────────
// مشتری توی حالت support_chat هر متنی بفرسته، عیناً برای ریسلر/ادمین (صاحب
// همین بات) فوروارد می‌شه؛ نگاشت «کدوم پیامِ فوروارد‌شده مال کدوم مشتریه»
// توی telegram_chat_relay ذخیره می‌شه تا وقتی صاحب بات روی همون پیام Reply
// زد (تلگرام خودش reply_to_message.message_id رو توی آپدیت بعدی می‌فرسته)
// بتونیم جواب رو دقیقاً برای همون مشتری برگردونیم.
// ─── تیکت باز (open/claimed) فعلی مشتری رو برمی‌گردونه، یا اگه نداره یکی
// می‌سازه. بستن تیکت خودکار باعث می‌شه پیام بعدی مشتری یک تیکت تازه بسازه ───
function tg_open_or_get_ticket(int $resellerId, int $customerId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE reseller_id=? AND telegram_customer_id=? AND status IN ('open','claimed') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$resellerId, $customerId]);
    $t = $stmt->fetch();
    if ($t) return $t;
    $pdo->prepare("INSERT INTO support_tickets (reseller_id, telegram_customer_id, status) VALUES (?,?,'open')")
        ->execute([$resellerId, $customerId]);
    $stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE id=?");
    $stmt->execute([(int)$pdo->lastInsertId()]);
    return $stmt->fetch();
}

// ─── پیام پشتیبانی مشتری: اگه تیکتش هنوز کسی نپذیرفته، به همه‌ی ادمین‌های بات
// (صاحب + bot_admins) با دکمه‌ی «پذیرش تیکت» فرستاده می‌شه (اولین کسی که
// پذیرفت ادامه‌ی چت رو می‌گیره)؛ اگه از قبل یک ادمین پذیرفته، پیام‌های بعدی
// فقط برای همون یک نفر می‌ره - نه بقیه (تا قاطی نشه کی داره جواب می‌ده). ───
function tg_relay_customer_message(array $customer, string $text, int $resellerId): void {
    global $pdo;
    $chatId = $customer['chat_id'];
    $who = htmlspecialchars($customer['tg_username'] ? '@' . $customer['tg_username'] : ($customer['full_name'] ?: $chatId), ENT_QUOTES, 'UTF-8');
    $textSafe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    $ticket = tg_open_or_get_ticket($resellerId, (int)$customer['id']);

    if ($ticket['status'] === 'claimed' && $ticket['claimed_by_chat_id']) {
        $body = "💬 <b>{$who}</b>:\n\n{$textSafe}\n\n<i>برای پاسخ، روی همین پیام Reply بزنید.</i>";
        $res = tg_sendMessage($ticket['claimed_by_chat_id'], $body);
        if ($res['ok'] ?? false) {
            $mid = $res['result']['message_id'] ?? null;
            if ($mid) {
                try {
                    $pdo->prepare("INSERT INTO telegram_chat_relay (reseller_id, customer_id, owner_message_id) VALUES (?,?,?)")
                        ->execute([$resellerId, $customer['id'], $mid]);
                } catch (Throwable $e) {
                    error_log("[tg_relay_customer_message] reseller_id={$resellerId} ticket={$ticket['id']}: ثبت نگاشت telegram_chat_relay ناموفق بود: " . $e->getMessage());
                }
            }
            tg_sendMessage($chatId, '✅ پیام شما ارسال شد.');
        } else {
            error_log("[tg_relay_customer_message] reseller_id={$resellerId} ticket={$ticket['id']}: ارسال به ادمین پذیرنده ناموفق بود: " . ($res['description'] ?? json_encode($res, JSON_UNESCAPED_UNICODE)));
            tg_sendMessage($chatId, '⚠️ ارسال پیام با خطا مواجه شد. لطفاً بعداً دوباره امتحان کنید.');
        }
        return;
    }

    $targets = ba_all_admin_chat_ids($resellerId);
    if (empty($targets)) {
        error_log("[tg_relay_customer_message] reseller_id={$resellerId} ticket={$ticket['id']}: هیچ ادمینی برای این بات ثبت نشده.");
        tg_sendMessage($chatId, '⚠️ در حال حاضر امکان ارسال پیام پشتیبانی وجود نداره. لطفاً بعداً امتحان کنید.');
        return;
    }

    $initialText = "🎫 <b>تیکت جدید #{$ticket['id']}</b>\nاز طرف: {$who}\n\n{$textSafe}";
    $kb = ['inline_keyboard' => [[['text' => '✅ پذیرش تیکت', 'callback_data' => 'tkt_claim_' . $ticket['id']]]]];
    $broadcast = [];
    foreach ($targets as $adminChat) {
        $r = tg_sendMessage($adminChat, $initialText, $kb);
        if ($r['ok'] ?? false) {
            $broadcast[$adminChat] = $r['result']['message_id'];
        } else {
            error_log("[tg_relay_customer_message] reseller_id={$resellerId} ticket={$ticket['id']}: ارسال تیکت به ادمین ({$adminChat}) ناموفق بود: " . ($r['description'] ?? json_encode($r, JSON_UNESCAPED_UNICODE)));
        }
    }
    if ($broadcast) {
        $pdo->prepare("UPDATE support_tickets SET initial_text=?, broadcast_json=? WHERE id=?")
            ->execute([$initialText, json_encode($broadcast, JSON_UNESCAPED_UNICODE), $ticket['id']]);
        tg_sendMessage($chatId, '✅ تیکت شما ثبت شد. به‌زودی یکی از پشتیبان‌ها پاسخ می‌ده ⏳');
    } else {
        tg_sendMessage($chatId, '⚠️ ارسال پیام پشتیبانی با خطا مواجه شد. لطفاً بعداً دوباره امتحان کنید.');
    }
}

// ─── اولین ادمینی که «پذیرش تیکت» رو بزنه، ادامه‌ی گفتگو رو می‌گیره. با
// UPDATE شرطی (status='open') از race condition (دو ادمین هم‌زمان بزنن)
// جلوگیری می‌شه - فقط یکی موفق می‌شه. کارت بقیه‌ی ادمین‌ها هم ویرایش می‌شه
// تا بدونن دیگه لازم نیست جواب بدن. ───
function tg_handle_ticket_claim(int $resellerId, $chatId, $messageId, int $ticketId, string $cqId): void {
    global $pdo;
    if (!ba_is_admin_chat($resellerId, $chatId)) { tg_answerCallbackQuery($cqId, 'دسترسی ندارید', true); return; }

    $name = ba_display_name($resellerId, $chatId);
    $upd = $pdo->prepare("UPDATE support_tickets SET status='claimed', claimed_by_chat_id=?, claimed_by_name=?, claimed_at=NOW() WHERE id=? AND reseller_id=? AND status='open'");
    $upd->execute([(string)$chatId, $name, $ticketId, $resellerId]);
    if ($upd->rowCount() === 0) { tg_answerCallbackQuery($cqId, 'این تیکت قبلاً توسط شخص دیگری پذیرفته شده', true); return; }

    tg_answerCallbackQuery($cqId, 'تیکت پذیرفته شد ✅');

    $stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE id=?");
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch();
    $baseText = $ticket['initial_text'] ?? "🎫 تیکت #{$ticketId}";
    $nameSafe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    $broadcast = json_decode($ticket['broadcast_json'] ?? '[]', true) ?: [];
    $closeKb = ['inline_keyboard' => [[['text' => '🔒 بستن تیکت', 'callback_data' => 'tkt_close_' . $ticketId]]]];
    foreach ($broadcast as $adminChat => $mid) {
        if ((string)$adminChat === (string)$chatId) {
            tg_editMessageText($adminChat, $mid, $baseText . "\n\n✅ پذیرفته شد توسط شما.\n\nبرای پاسخ به مشتری، روی همین پیام Reply بزنید.", $closeKb);
            try {
                $pdo->prepare("INSERT INTO telegram_chat_relay (reseller_id, customer_id, owner_message_id) VALUES (?,?,?)")
                    ->execute([$resellerId, $ticket['telegram_customer_id'], $mid]);
            } catch (Throwable $e) {
                error_log("[tg_handle_ticket_claim] reseller_id={$resellerId} ticket={$ticketId}: ثبت نگاشت relay ناموفق بود: " . $e->getMessage());
            }
        } else {
            tg_editMessageText($adminChat, $mid, $baseText . "\n\n✅ توسط «{$nameSafe}» پذیرفته شد.");
        }
    }

    $custStmt = $pdo->prepare("SELECT chat_id FROM telegram_customers WHERE id=?");
    $custStmt->execute([$ticket['telegram_customer_id']]);
    $custChat = $custStmt->fetchColumn();
    if ($custChat) tg_sendMessage($custChat, '👨‍💻 یکی از پشتیبان‌ها پیام شما رو دید، به‌زودی پاسخ می‌گیرید.');

    logActivity($resellerId === 0 ? 'admin' : 'reseller', $resellerId, 'claim_support_ticket', "تیکت #{$ticketId} توسط {$name} پذیرفته شد");
}

// ─── بستن تیکت توسط هر ادمین این بات (نه فقط پذیرنده) - بعد از بسته شدن،
// پیام بعدی همون مشتری خودکار یک تیکت تازه‌ی «open» می‌سازه ───
function tg_handle_ticket_close(int $resellerId, $chatId, $messageId, int $ticketId, string $cqId): void {
    global $pdo;
    if (!ba_is_admin_chat($resellerId, $chatId)) { tg_answerCallbackQuery($cqId, 'دسترسی ندارید', true); return; }

    $upd = $pdo->prepare("UPDATE support_tickets SET status='closed', closed_at=NOW() WHERE id=? AND reseller_id=? AND status<>'closed'");
    $upd->execute([$ticketId, $resellerId]);
    if ($upd->rowCount() === 0) { tg_answerCallbackQuery($cqId, 'این تیکت قبلاً بسته شده', true); return; }

    tg_answerCallbackQuery($cqId, 'تیکت بسته شد 🔒');
    if ($messageId) tg_editMessageReplyMarkup($chatId, $messageId, null);

    $stmt = $pdo->prepare("SELECT telegram_customer_id FROM support_tickets WHERE id=?");
    $stmt->execute([$ticketId]);
    $custId = $stmt->fetchColumn();
    if ($custId) {
        $custStmt = $pdo->prepare("SELECT chat_id FROM telegram_customers WHERE id=?");
        $custStmt->execute([$custId]);
        $custChat = $custStmt->fetchColumn();
        if ($custChat) tg_sendMessage($custChat, '🔒 گفتگوی پشتیبانی شما بسته شد. برای تیکت جدید دوباره پیام بدید.');
    }

    logActivity($resellerId === 0 ? 'admin' : 'reseller', $resellerId, 'close_support_ticket', "تیکت #{$ticketId} بسته شد توسط " . ba_display_name($resellerId, $chatId));
}

// ─── وقتی صاحب بات (ادمین برای بات اصلی، خودِ ریسلر برای بات اختصاصی‌اش) با
// Reply روی یک پیامِ فوروارد‌شده‌ی مشتری جواب می‌ده، این تابع تشخیصش می‌ده و
// جواب رو مستقیم برای همون مشتری می‌فرسته. اگه پیام Reply نبود یا به پیام
// مربوط به یک مشتری اشاره نمی‌کرد، false برمی‌گردونه تا جریان عادی (دستورهای
// /export و... ) ادامه پیدا کنه. ───
function tg_try_relay_owner_reply(int $resellerId, $ownerChatId, array $msg): bool {
    global $pdo;
    $replyToId = $msg['reply_to_message']['message_id'] ?? null;
    $text = trim($msg['text'] ?? '');
    if ($replyToId === null || $text === '') return false;

    try {
        $stmt = $pdo->prepare("SELECT customer_id FROM telegram_chat_relay WHERE reseller_id=? AND owner_message_id=? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$resellerId, $replyToId]);
        $customerId = $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log("[tg_try_relay_owner_reply] reseller_id={$resellerId}: خواندن telegram_chat_relay ناموفق بود (احتمالاً جدول ساخته نشده - migration 007 رو چک کنید): " . $e->getMessage());
        return false;
    }
    if (!$customerId) return false;

    $cStmt = $pdo->prepare("SELECT * FROM telegram_customers WHERE id=?");
    $cStmt->execute([$customerId]);
    $customer = $cStmt->fetch();
    if (!$customer) return false;

    $textSafe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    tg_sendMessage($customer['chat_id'], "💬 <b>پاسخ پشتیبانی:</b>\n\n{$textSafe}");
    tg_sendMessage($ownerChatId, '✅ پیام برای مشتری ارسال شد.');
    return true;
}

// ───────────────────────────── callback ها ─────────────────────────────
function tg_handle_callback(array $cq, int $resellerId = 0): void {
    $chatId = $cq['message']['chat']['id'] ?? null;
    $messageId = $cq['message']['message_id'] ?? null;
    $data = $cq['data'] ?? '';
    $cqId = $cq['id'];
    if ($chatId === null) return;

    if (str_starts_with($data, 'ord_')) {
        if (!ba_is_admin_chat($resellerId, $chatId)) { tg_answerCallbackQuery($cqId, 'دسترسی ندارید', true); return; }
        tg_handle_order_decision($chatId, $messageId, $data, $cqId, $resellerId);
        return;
    }

    if (str_starts_with($data, 'tkt_claim_')) {
        tg_handle_ticket_claim($resellerId, $chatId, $messageId, (int)substr($data, 10), $cqId);
        return;
    }

    if (str_starts_with($data, 'tkt_close_')) {
        tg_handle_ticket_close($resellerId, $chatId, $messageId, (int)substr($data, 10), $cqId);
        return;
    }

    if (str_starts_with($data, 'ovpn_')) {
        tg_send_ovpn_file($chatId, (int)substr($data, 5), $cqId, $resellerId);
        return;
    }

    if (str_starts_with($data, 'pkg_')) {
        tg_customer_pick_package($chatId, (int)substr($data, 4), $cqId, $resellerId);
        return;
    }

    if (str_starts_with($data, 'renew_')) {
        tg_customer_pick_renew($chatId, (int)substr($data, 6), $cqId, $resellerId);
        return;
    }

    tg_answerCallbackQuery($cqId);
}

// چه‌کسی داره تأیید/رد می‌کنه: ممکنه ادمین اصلی، یک ادمین اضافه‌ی بی‌پنل
// (bot_admins)، خودِ ریسلر صاحب بات، یا یک ادمین اضافه‌ی همون ریسلر باشه.
// برای ستون reviewed_by (که FK به admins هست) فقط وقتی actorType==='admin'
// و واقعاً یک ردیف admins با همین Chat ID پیدا بشه مقدار می‌گیره؛ در غیر این
// صورت NULL می‌مونه ولی reviewed_by_name همیشه اسم واقعیِ همون شخص رو (چه
// ادمین پنل چه ادمین فقط-تلگرامی) نگه می‌داره - دقیقاً برای اینکه بشه فهمید
// «کدوم ادمین» تأیید/رد کرده، حتی وقتی چند ادمین روی یک بات کار می‌کنن.
function tg_handle_order_decision($chatId, $messageId, string $data, string $cqId, int $resellerId = 0): void {
    global $pdo;
    $approve = str_starts_with($data, 'ord_approve_');
    $orderId = (int)substr($data, $approve ? 12 : 11);

    $order = $pdo->prepare("SELECT * FROM telegram_orders WHERE id=? AND status='pending'");
    $order->execute([$orderId]);
    $order = $order->fetch();
    if (!$order) { tg_answerCallbackQuery($cqId, 'این سفارش قبلاً بررسی شده', true); return; }

    $customerStmt = $pdo->prepare("SELECT * FROM telegram_customers WHERE id=?");
    $customerStmt->execute([$order['telegram_customer_id']]);
    $customer = $customerStmt->fetch();

    $actorType = $resellerId === 0 ? 'admin' : 'reseller';
    $realAdminId = $resellerId === 0 ? tg_admin_id_by_chat($chatId) : null;
    $actorId = $realAdminId ?? $resellerId;
    $reviewedByAdminId = $realAdminId;
    $reviewerName = ba_display_name($resellerId, $chatId) . ' (بات)';

    if (!$approve) {
        $pdo->prepare("UPDATE telegram_orders SET status='rejected', reviewed_by=?, reviewed_by_name=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$reviewedByAdminId, $reviewerName, $orderId]);
        tg_answerCallbackQuery($cqId, 'رد شد');
        if ($customer) tg_sendMessage($customer['chat_id'], '❌ متأسفانه رسید پرداخت شما تأیید نشد. برای پیگیری با پشتیبانی تماس بگیرید.');
        if ($messageId) tg_editMessageReplyMarkup($chatId, $messageId, null);
        logActivity($actorType, $actorId, 'reject_direct_order', "سفارش تلگرام #$orderId توسط {$reviewerName} رد شد");
        return;
    }

    $result = $order['order_type'] === 'new'
        ? tg_provision_new_order($order, $customer, $resellerId)
        : tg_provision_renew_order($order, $customer, $resellerId);

    if (!$result['ok']) {
        tg_answerCallbackQuery($cqId, 'خطا: ' . $result['error'], true);
        return;
    }

    $pdo->prepare("UPDATE telegram_orders SET status='approved', ibs_uid=?, reviewed_by=?, reviewed_by_name=?, reviewed_at=NOW() WHERE id=?")
        ->execute([$result['ibs_uid'] ?? $order['ibs_uid'], $reviewedByAdminId, $reviewerName, $orderId]);
    tg_answerCallbackQuery($cqId, 'تأیید شد ✅');
    if ($messageId) tg_editMessageReplyMarkup($chatId, $messageId, null);
    logActivity($actorType, $actorId, 'approve_direct_order', "سفارش تلگرام #$orderId توسط {$reviewerName} تأیید شد");
}

function tg_provision_new_order(array $order, ?array $customer, int $resellerId = 0): array {
    global $pdo;
    $pkg = tg_get_package((int)$order['package_id'], $resellerId);
    if (!$pkg) return ['ok' => false, 'error' => 'بسته یافت نشد'];

    $username = $order['target_username'];
    $chk = ibsng_call('user.doesUserExists', ['normal_username' => $username]);
    if ($chk['result'] ?? false) return ['ok' => false, 'error' => 'این نام کاربری در همین حین توسط شخص دیگری گرفته شده'];

    // برای کسر از موجودی/ثبت تراکنش ریسلر همیشه هزینه‌ی واقعی خودش (cost_price
    // که ادمین تنظیم کرده) استفاده می‌شه، نه قیمتی که با سود خودش به مشتری
    // نشون داده (pkg['price']) - مابه‌التفاوت سود خودِ ریسلره و کسر نمی‌شه.
    $price = (float)($pkg['cost_price'] ?? $pkg['price']);
    if ($resellerId > 0 && $price > 0) {
        $rStmt = $pdo->prepare("SELECT balance FROM resellers WHERE id=?");
        $rStmt->execute([$resellerId]);
        if ((float)$rStmt->fetchColumn() < $price) return ['ok' => false, 'error' => 'موجودی ریسلر کافی نیست'];
    }

    $password = tg_generate_password();
    $gi = ibsng_call('group.getGroupInfo', ['group_name' => $pkg['group_name']]);
    $gc = $gi['result']['attrs']['group_credit'] ?? 100;
    $ga = $gi['result']['raw_attrs'] ?? [];

    $cr = ibsng_call('user.addNewUsers', [
        'count' => 1, 'credit' => ['1' => (float)$gc],
        'isp_name' => $pkg['isp_name'], 'group_name' => $pkg['group_name'],
        'credit_comment' => $resellerId > 0 ? 'خرید مستقیم تلگرام (ریسلر)' : 'خرید مستقیم تلگرام',
    ]);
    if ($cr['error'] ?? null) return ['ok' => false, 'error' => $cr['error']];
    $newUID = $cr['result'][0];

    $r2 = ibsng_call('user.updateUserAttrs', [
        'user_id' => (string)$newUID,
        'attrs' => ['normal_user_spec' => ['normal_username' => $username, 'normal_password' => $password]],
        'to_del_attrs' => [],
    ]);
    if ($r2['error'] ?? null) {
        ibsng_call('user.delUser', ['user_id' => (string)$newUID, 'delete_comment' => 'rollback', 'del_connection_logs' => false, 'del_audit_logs' => false]);
        return ['ok' => false, 'error' => $r2['error']];
    }

    $pdo->prepare("INSERT INTO telegram_user_links (telegram_customer_id, ibs_username, ibs_uid, group_name) VALUES (?,?,?,?)")
        ->execute([$order['telegram_customer_id'], $username, $newUID, $pkg['group_name']]);

    ibsng_cacheUpsertUser($pdo, $newUID, $pkg['isp_name']);

    if ($resellerId > 0) {
        // دقیقاً مثل ساخت دستی کاربر از reseller/users.php: کسر از موجودی خودِ
        // ریسلر + ثبت تراکنش + ثبت توی جدول users برای گزارش‌های خودش.
        if ($price > 0) {
            $pdo->prepare("UPDATE resellers SET balance=GREATEST(0,balance-?) WHERE id=?")->execute([$price, $resellerId]);
            $pdo->prepare("INSERT INTO transactions (reseller_id,type,amount,description) VALUES (?,?,?,?)")
                ->execute([$resellerId, 'user_create', $price, "خرید تلگرام - $username"]);
        }
        $expD = date('Y-m-d', time() + (int)($ga['rel_exp_date'] ?? 2592000));
        $pdo->prepare("INSERT INTO users (reseller_id,username,password,ibs_username,package_name,isp_name,price,start_date,expire_date) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$resellerId, $username, $password, $username, $pkg['group_name'], $pkg['isp_name'], $price, date('Y-m-d'), $expD]);
    }

    if ($customer) {
        $unSafe = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $pwSafe = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
        tg_sendMessage($customer['chat_id'], "🎉 <b>سرویس شما فعال شد!</b>\n\n👤 نام کاربری: <code>{$unSafe}</code>\n🔑 رمز عبور: <code>{$pwSafe}</code>\n\n⚠️ این اطلاعات را نزد خود نگه دارید.");
    }

    return ['ok' => true, 'ibs_uid' => $newUID];
}

function tg_provision_renew_order(array $order, ?array $customer, int $resellerId = 0): array {
    global $pdo;
    $username = $order['target_username'];

    $link = $pdo->prepare("SELECT * FROM telegram_user_links WHERE telegram_customer_id=? AND ibs_username=? ORDER BY id DESC LIMIT 1");
    $link->execute([$order['telegram_customer_id'], $username]);
    $link = $link->fetch();

    $uid = $link['ibs_uid'] ?? null;
    if (!$uid) {
        $srch = ibsng_call('user.searchUser', ['conds' => ['normal_username' => $username], 'from' => 0, 'to' => 1, 'order_by' => 'user_id', 'desc' => false]);
        $uid = $srch['result'][2][0] ?? null;
    }
    if (!$uid) return ['ok' => false, 'error' => 'کاربر در IBSng یافت نشد'];

    $inf = ibsng_call('user.getUserInfo', ['user_id' => $uid]);
    $basic = $inf['result'][$uid]['basic_info'] ?? [];
    $gn = $basic['group_name'] ?? '';

    // هزینه‌ی واقعی کسر از موجودی ریسلر همیشه هزینه‌ی پایه‌ی خودِ گروه (چیزی که
    // ادمین تنظیم کرده) هست، نه order.amount که قیمتِ با-سودِ نمایش‌داده‌شده به
    // مشتری در لحظه‌ی سفارشه - این دو ممکنه دیگه یکی نباشن.
    $price = (float)$order['amount'];
    if ($resellerId > 0) {
        $pkg = tg_get_package_by_group($gn, $resellerId);
        if ($pkg) $price = (float)($pkg['cost_price'] ?? $pkg['price']);
    }
    if ($resellerId > 0 && $price > 0) {
        $rStmt = $pdo->prepare("SELECT balance FROM resellers WHERE id=?");
        $rStmt->execute([$resellerId]);
        if ((float)$rStmt->fetchColumn() < $price) return ['ok' => false, 'error' => 'موجودی ریسلر کافی نیست'];
    }

    $gi = ibsng_call('group.getGroupInfo', ['group_name' => $gn]);
    $gc = $gi['result']['attrs']['group_credit'] ?? ($basic['credit'] ?? 100);
    $ga = $gi['result']['raw_attrs'] ?? [];

    ibsng_call('user.changeCredit', ['user_id' => $uid, 'credit' => (float)$gc, 'is_absolute_change' => true, 'credit_comment' => 'تمدید مستقیم تلگرام']);
    if (!empty($ga['rel_exp_date'])) {
        // انقضا باید بر اساس Relative Expiration Date گروه از اولین اتصال بعدی کاربر
        // شمرده بشه، نه از همین لحظه‌ی تمدید. پس دیگه abs_exp_date رو ست نمی‌کنیم (و اگه
        // از قبل روی کاربر مونده باشه پاکش می‌کنیم)، فقط first_login/real_first_login رو
        // ریست می‌کنیم تا شمارش از اولین لاگین بعدی از نو شروع بشه.
        ibsng_call('user.updateUserAttrs', ['user_id' => $uid, 'attrs' => (object)[], 'to_del_attrs' => ['abs_exp_date', 'abs_exp_date_unit', 'first_login', 'real_first_login']]);
    }
    ibsng_call('user.changeStatus', ['user_id' => $uid, 'status' => 'Recharged']);

    $pdo->prepare("INSERT INTO renewal_logs (ibs_username,isp_name,group_name,price,renewed_by,reseller_id) VALUES (?,?,?,?,?,?)")
        ->execute([$username, $basic['isp_name'] ?? '', $gn, $price, 'telegram', $resellerId > 0 ? $resellerId : null]);

    ibsng_cacheUpsertUser($pdo, $uid, $basic['isp_name'] ?? '');

    if ($resellerId > 0 && $price > 0) {
        $pdo->prepare("UPDATE resellers SET balance=GREATEST(0,balance-?) WHERE id=?")->execute([$price, $resellerId]);
        $pdo->prepare("INSERT INTO transactions (reseller_id,type,amount,description) VALUES (?,?,?,?)")
            ->execute([$resellerId, 'user_renew', $price, "تمدید تلگرام - $username"]);
    }

    if ($customer) {
        $unSafe = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        tg_sendMessage($customer['chat_id'], "🎉 <b>سرویس «{$unSafe}» با موفقیت تمدید شد!</b> ✅");
    }

    return ['ok' => true, 'ibs_uid' => $uid];
}

// ───────────────────────────── پنل کنترلی خودِ ریسلر توی بات اختصاصی‌اش ─────────────────────────────
function tg_handle_reseller_owner_message(int $resellerId, $chatId, array $msg): bool {
    global $pdo;
    $text = trim($msg['text'] ?? '');

    if ($text === '/pending') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM telegram_orders WHERE status='pending' AND reseller_id=?");
        $stmt->execute([$resellerId]);
        tg_sendMessage($chatId, "🛒 سفارش‌های در انتظار بررسی: " . (int)$stmt->fetchColumn());
        return true;
    }

    if ($text === '/stats') {
        $stmt = $pdo->prepare("SELECT balance, isp_name FROM resellers WHERE id=?");
        $stmt->execute([$resellerId]);
        $r = $stmt->fetch();
        $userCount = !empty($r['isp_name']) ? ibsng_getIspUserCount($r['isp_name']) : 0;
        tg_sendMessage($chatId, "💰 موجودی: " . money((float)($r['balance'] ?? 0)) . " تومان\n👥 تعداد کاربران: {$userCount}");
        return true;
    }

    // عمداً /start رو اینجا مدیریت نمی‌کنیم - اگه صاحب بات /start بزنه، باید
    // دقیقاً همون چیزی رو ببینه که یک مشتری واقعی می‌بینه (برای تست). دستور
    // ادمین جداگانه‌ی /help هست.
    if ($text === '/help') {
        tg_sendMessage($chatId, "👋 پنل کنترلی بات شما\n\n/pending - تعداد سفارش‌های در انتظار تأیید\n/stats - آمار سریع\n\nسفارش‌های خرید/تمدید مشتری‌ها و تیکت‌های پشتیبانی به‌صورت خودکار با دکمه‌های تأیید/پذیرش برای شما (و ادمین‌های اضافه‌ای که تعریف کرده‌اید) ارسال می‌شوند.\n\n💡 برای دیدن منوی مشتری (تست) دستور /start رو بزنید.");
        return true;
    }

    return false;
}

// ───────────────────────────── پنل کنترلی ادمین (دستورهای متنی) ─────────────────────────────
// $chatId ممکنه یک ادمین واقعی پنل (جدول admins) باشه یا یک ادمین اضافه‌ی
// فقط-تلگرامی (bot_admins، بدون لاگین پنل) - تشخیصش با ba_is_owner_chat/
// tg_admin_id_by_chat. دستورهای حساس (/export، /import که کل دیتابیس رو
// می‌ده/جایگزین می‌کنه) فقط برای ادمین واقعی پنل مجازه.
function tg_handle_admin_message($chatId, array $msg): bool {
    global $pdo;
    $text = trim($msg['text'] ?? '');
    $caption = trim($msg['caption'] ?? '');
    $isRealAdmin = ba_is_owner_chat(0, $chatId);

    if (!empty($msg['document']) && strcasecmp($caption, '/import') === 0) {
        if (!$isRealAdmin) { tg_sendMessage($chatId, '⛔️ این قابلیت فقط برای ادمین اصلی پنل در دسترسه.'); return true; }
        tg_admin_do_import($chatId, $msg['document']);
        return true;
    }

    if ($text === '/export') {
        if (!$isRealAdmin) { tg_sendMessage($chatId, '⛔️ این قابلیت فقط برای ادمین اصلی پنل در دسترسه.'); return true; }
        tg_sendMessage($chatId, '⏳ در حال ساخت فایل Export...');
        $dir = dirname(__DIR__) . '/uploads/backups/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . 'backup_' . date('Ymd_His') . '.sql';
        if (db_export_to_file($pdo, $file)) {
            tg_sendDocumentFile($chatId, $file, '📦 Export دیتابیس - ' . date('Y-m-d H:i'));
            @unlink($file);
        } else {
            tg_sendMessage($chatId, '❌ ساخت فایل Export ناموفق بود.');
        }
        return true;
    }

    if ($text === '/pending') {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM telegram_orders WHERE status='pending'")->fetchColumn();
        $cnt2 = (int)$pdo->query("SELECT COUNT(*) FROM payment_requests WHERE status='pending'")->fetchColumn();
        tg_sendMessage($chatId, "🛒 سفارش‌های مستقیم در انتظار: {$cnt}\n🧾 فیش‌های ریسلر در انتظار: {$cnt2}");
        return true;
    }

    if ($text === '/stats') {
        $totalResellers = (int)$pdo->query("SELECT COUNT(*) FROM resellers")->fetchColumn();
        $totalDebt = (float)($pdo->query("SELECT SUM(debt) FROM resellers")->fetchColumn() ?: 0);
        $online = ibsng_call('report.getOnlineUsersCount', [], 30, 5);
        $onlineCount = $online['result']['internet_onlines'] ?? '?';
        tg_sendMessage($chatId, "👥 ریسلرها: {$totalResellers}\n💰 کل بدهی: " . money($totalDebt) . " تومان\n🟢 آنلاین: {$onlineCount}");
        return true;
    }

    // عمداً /start رو اینجا مدیریت نمی‌کنیم - اگه ادمین /start بزنه، باید
    // دقیقاً همون چیزی رو ببینه که یک مشتری واقعی می‌بینه (برای تست).
    if ($text === '/help') {
        $extra = $isRealAdmin ? "/export - دریافت فایل Export دیتابیس\n/import - (به‌عنوان caption روی فایل .sql ارسالی) بازگردانی دیتابیس\n" : '';
        tg_sendMessage($chatId, "👋 پنل کنترلی ادمین در تلگرام\n\n{$extra}/pending - تعداد موارد در انتظار تأیید\n/stats - آمار سریع\n\nسفارش‌های خرید/تمدید مستقیم و تیکت‌های پشتیبانی به‌صورت خودکار با دکمه‌های تأیید/پذیرش برای شما ارسال می‌شوند.\n\n💡 برای دیدن منوی مشتری (تست) دستور /start رو بزنید.");
        return true;
    }

    return false;
}

function tg_admin_do_import($chatId, array $document): void {
    global $pdo;
    $name = strtolower($document['file_name'] ?? '');
    if (!str_ends_with($name, '.sql')) {
        tg_sendMessage($chatId, '❌ فقط فایل .sql پذیرفته می‌شود.');
        return;
    }
    $file = tg_getFile($document['file_id']);
    $path = $file['result']['file_path'] ?? null;
    if (!$path) { tg_sendMessage($chatId, '❌ دریافت فایل از تلگرام ناموفق بود.'); return; }

    $dir = dirname(__DIR__) . '/uploads/backups/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $dest = $dir . 'import_' . time() . '.sql';
    if (!tg_downloadFile($path, $dest)) {
        tg_sendMessage($chatId, '❌ دانلود فایل ناموفق بود (ممکن است حجم فایل بیش از حد مجاز تلگرام برای دانلود توسط ربات - حدود ۲۰ مگابایت - باشد؛ در این صورت از فرم Import در admin/telegram.php استفاده کنید).');
        return;
    }

    tg_sendMessage($chatId, '⏳ در حال بازگردانی دیتابیس...');
    $result = db_import_from_file($pdo, $dest);
    @unlink($dest);

    if (!$result['ok']) { tg_sendMessage($chatId, '❌ ' . ($result['error'] ?? 'خطای نامشخص')); return; }
    $out = "✅ بازگردانی انجام شد.\nدستورهای موفق: {$result['executed']}\nخطاها: {$result['failed']}";
    if (!empty($result['errors'])) $out .= "\n\nنمونه خطاها:\n" . implode("\n", array_slice($result['errors'], 0, 3));
    tg_sendMessage($chatId, $out);
}

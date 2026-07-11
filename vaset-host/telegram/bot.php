<?php
// منطق ربات تلگرام مشتریان مستقیم + پنل کنترلی ادمین در تلگرام.
// telegram/webhook.php این فایل را require می‌کند و به‌ازای هر update ورودی
// tg_handle_update() را صدا می‌زند. نیازمند config.php + ibsng_api.php +
// telegram_api.php + db_backup.php (همه قبل از این فایل require شده‌اند).

// ───────────────────────────── ورودی اصلی ─────────────────────────────
function tg_handle_update(array $update) {
    if (isset($update['callback_query'])) {
        tg_handle_callback($update['callback_query']);
        return;
    }
    if (isset($update['message'])) {
        tg_handle_message($update['message']);
        return;
    }
}

// ───────────────────────────── کمکی‌های مشترک ─────────────────────────────
function tg_admin_chat_ids(): array {
    global $pdo;
    return $pdo->query("SELECT telegram_chat_id FROM admins WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id <> ''")
        ->fetchAll(PDO::FETCH_COLUMN);
}

function tg_admin_id_by_chat($chatId): ?int {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE telegram_chat_id = ?");
    $stmt->execute([(string)$chatId]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function tg_main_keyboard(): array {
    return [
        'keyboard' => [
            ['🛒 خرید سرویس جدید', '🔄 تمدید سرویس'],
            ['📋 سرویس‌های من', '💳 اطلاعات پرداخت'],
            ['💬 پشتیبانی'],
        ],
        'resize_keyboard' => true,
    ];
}

function tg_generate_password(int $len = 6): string {
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $pw = '';
    for ($i = 0; $i < $len; $i++) $pw .= $chars[random_int(0, strlen($chars) - 1)];
    return $pw;
}

function tg_get_or_create_customer($chatId, ?string $tgUsername, ?string $fullName): array {
    global $pdo;
    $chatId = (string)$chatId;
    $stmt = $pdo->prepare("SELECT * FROM telegram_customers WHERE chat_id=?");
    $stmt->execute([$chatId]);
    $c = $stmt->fetch();
    if ($c) {
        $pdo->prepare("UPDATE telegram_customers SET tg_username=?, full_name=? WHERE id=?")
            ->execute([$tgUsername, $fullName, $c['id']]);
        $c['tg_username'] = $tgUsername;
        $c['full_name'] = $fullName;
        return $c;
    }
    $pdo->prepare("INSERT INTO telegram_customers (chat_id, tg_username, full_name) VALUES (?,?,?)")
        ->execute([$chatId, $tgUsername, $fullName]);
    $stmt->execute([$chatId]);
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

function tg_get_package(int $id): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM direct_packages WHERE id=?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
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

// ───────────────────────────── پیام‌های عادی ─────────────────────────────
function tg_handle_message(array $msg): void {
    $chatId = $msg['chat']['id'] ?? null;
    if ($chatId === null) return;
    $from = $msg['from'] ?? [];
    $tgUsername = $from['username'] ?? null;
    $fullName = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: null;

    $adminId = tg_admin_id_by_chat($chatId);
    if ($adminId !== null) {
        if (!tg_handle_admin_message($adminId, $chatId, $msg)) {
            tg_sendMessage($chatId, "دستور ناشناخته.\n\n/export - دریافت فایل Export دیتابیس\n/import - (به‌عنوان caption روی فایل .sql) بازگردانی دیتابیس\n/pending - موارد در انتظار تأیید\n/stats - آمار سریع");
        }
        return;
    }

    $customer = tg_get_or_create_customer($chatId, $tgUsername, $fullName);
    if (!empty($customer['is_blocked'])) return;

    $text = trim($msg['text'] ?? '');

    if ($text === '/start') {
        tg_set_state((int)$customer['id'], null, null);
        $name = $fullName ?: 'دوست عزیز';
        tg_sendMessage($chatId, "سلام {$name} 👋\nبه ربات فروش اینترنت خوش آمدید.\nاز منوی زیر یکی را انتخاب کنید:", tg_main_keyboard());
        return;
    }

    if ($text === '🛒 خرید سرویس جدید') { tg_start_new_purchase($customer); return; }
    if ($text === '🔄 تمدید سرویس') { tg_start_renew($customer); return; }
    if ($text === '📋 سرویس‌های من') { tg_show_my_services($customer); return; }
    if ($text === '💳 اطلاعات پرداخت') { tg_sendMessage($chatId, getSetting('payment_card_info', 'هنوز تنظیم نشده.')); return; }
    if ($text === '💬 پشتیبانی') { tg_sendMessage($chatId, getSetting('support_contact_message', 'هنوز تنظیم نشده.')); return; }

    $state = $customer['state'];

    if ($state === 'awaiting_new_username' && $text !== '') {
        tg_receive_new_username($customer, $text);
        return;
    }

    if (in_array($state, ['awaiting_new_receipt', 'awaiting_renew_receipt'], true)) {
        $fileId = tg_extract_receipt_file_id($msg);
        if ($fileId !== null) { tg_receive_receipt($customer, $fileId); return; }
        tg_sendMessage($chatId, 'لطفاً تصویر یا فایل رسید پرداخت را ارسال کنید.');
        return;
    }

    tg_sendMessage($chatId, 'از دکمه‌های زیر استفاده کنید:', tg_main_keyboard());
}

// ───────────────────────────── خرید سرویس جدید ─────────────────────────────
function tg_start_new_purchase(array $customer): void {
    global $pdo;
    $chatId = $customer['chat_id'];
    $packages = $pdo->query("SELECT * FROM direct_packages WHERE is_active=1 ORDER BY sort_order, id")->fetchAll();
    if (!$packages) {
        tg_sendMessage($chatId, 'در حال حاضر بسته‌ای برای فروش تعریف نشده. لطفاً با پشتیبانی تماس بگیرید.');
        return;
    }
    $buttons = [];
    foreach ($packages as $p) {
        $buttons[] = [['text' => $p['title'] . ' - ' . number_format((float)$p['price']) . ' تومان', 'callback_data' => 'pkg_' . $p['id']]];
    }
    tg_set_state((int)$customer['id'], null, null);
    tg_sendMessage($chatId, '📦 یکی از بسته‌های زیر را انتخاب کنید:', ['inline_keyboard' => $buttons]);
}

function tg_customer_pick_package($chatId, int $pkgId, string $cqId): void {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM telegram_customers WHERE chat_id=?");
    $stmt->execute([(string)$chatId]);
    $customer = $stmt->fetch();
    if (!$customer) { tg_answerCallbackQuery($cqId); return; }

    $pkg = tg_get_package($pkgId);
    if (!$pkg || !$pkg['is_active']) { tg_answerCallbackQuery($cqId, 'این بسته دیگر در دسترس نیست', true); return; }

    tg_set_state((int)$customer['id'], 'awaiting_new_username', ['package_id' => (int)$pkg['id']]);
    tg_answerCallbackQuery($cqId);
    tg_sendMessage($chatId, "بسته انتخابی: {$pkg['title']}\n\nیک نام کاربری انگلیسی برای سرویس خود انتخاب کنید (فقط حروف/عدد/آندرلاین، ۳ تا ۲۰ کاراکتر):");
}

function tg_receive_new_username(array $customer, string $username): void {
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

    $pkg = tg_get_package((int)($data['package_id'] ?? 0));
    $amount = $pkg ? (float)$pkg['price'] : 0;
    $card = getSetting('payment_card_info', '—');
    tg_sendMessage($chatId, "نام کاربری: {$username}\nمبلغ قابل پرداخت: " . number_format($amount) . " تومان\n\n{$card}\n\nبعد از پرداخت، تصویر رسید را همین‌جا ارسال کنید 📸");
}

// ───────────────────────────── تمدید سرویس ─────────────────────────────
function tg_start_renew(array $customer): void {
    global $pdo;
    $chatId = $customer['chat_id'];
    $links = $pdo->prepare("SELECT * FROM telegram_user_links WHERE telegram_customer_id=? ORDER BY id DESC");
    $links->execute([$customer['id']]);
    $links = $links->fetchAll();
    if (!$links) { tg_sendMessage($chatId, 'شما سرویس فعالی برای تمدید ثبت‌شده در ربات ندارید.'); return; }

    $buttons = [];
    foreach ($links as $l) {
        $buttons[] = [['text' => $l['ibs_username'] . ' (' . $l['group_name'] . ')', 'callback_data' => 'renew_' . $l['id']]];
    }
    tg_sendMessage($chatId, '🔄 کدام سرویس را می‌خواهید تمدید کنید؟', ['inline_keyboard' => $buttons]);
}

function tg_customer_pick_renew($chatId, int $linkId, string $cqId): void {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM telegram_customers WHERE chat_id=?");
    $stmt->execute([(string)$chatId]);
    $customer = $stmt->fetch();
    if (!$customer) { tg_answerCallbackQuery($cqId); return; }

    $link = $pdo->prepare("SELECT * FROM telegram_user_links WHERE id=? AND telegram_customer_id=?");
    $link->execute([$linkId, $customer['id']]);
    $link = $link->fetch();
    if (!$link) { tg_answerCallbackQuery($cqId, 'یافت نشد', true); return; }

    $pkg = $pdo->prepare("SELECT * FROM direct_packages WHERE group_name=?");
    $pkg->execute([$link['group_name']]);
    $pkg = $pkg->fetch();
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
    $card = getSetting('payment_card_info', '—');
    tg_sendMessage($chatId, "تمدید سرویس: {$link['ibs_username']}\nمبلغ: " . number_format((float)$pkg['price']) . " تومان\n\n{$card}\n\nبعد از پرداخت، تصویر رسید را ارسال کنید 📸");
}

// ───────────────────────────── دریافت رسید (مشترک بین خرید و تمدید) ─────────────────────────────
function tg_receive_receipt(array $customer, string $fileId): void {
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
        $pkg = tg_get_package((int)($data['package_id'] ?? 0));
        if (!$pkg) { tg_sendMessage($chatId, 'خطا: بسته یافت نشد. دوباره از منو شروع کنید.'); tg_set_state((int)$customer['id'], null, null); return; }
        $stmt = $pdo->prepare("INSERT INTO telegram_orders (telegram_customer_id, order_type, package_id, target_username, amount, receipt_file, status) VALUES (?,?,?,?,?,?,'pending')");
        $stmt->execute([$customer['id'], 'new', $pkg['id'], $data['username'], $pkg['price'], $localName]);
        $orderId = (int)$pdo->lastInsertId();
        $who = $customer['tg_username'] ? '@' . $customer['tg_username'] : ($customer['full_name'] ?: $chatId);
        $summary = "🛒 سفارش جدید #{$orderId}\nمشتری: {$who}\nبسته: {$pkg['title']}\nیوزرنیم درخواستی: {$data['username']}\nمبلغ: " . number_format((float)$pkg['price']) . ' تومان';
    } elseif ($customer['state'] === 'awaiting_renew_receipt') {
        $stmt = $pdo->prepare("INSERT INTO telegram_orders (telegram_customer_id, order_type, target_username, amount, receipt_file, status) VALUES (?,?,?,?,?,'pending')");
        $stmt->execute([$customer['id'], 'renew', $data['username'], $data['amount'], $localName]);
        $orderId = (int)$pdo->lastInsertId();
        $who = $customer['tg_username'] ? '@' . $customer['tg_username'] : ($customer['full_name'] ?: $chatId);
        $summary = "🔄 سفارش تمدید #{$orderId}\nمشتری: {$who}\nیوزرنیم: {$data['username']}\nمبلغ: " . number_format((float)$data['amount']) . ' تومان';
    } else {
        return;
    }

    tg_set_state((int)$customer['id'], null, null);
    tg_sendMessage($chatId, '✅ رسید شما ثبت شد. پس از بررسی ادمین نتیجه اطلاع‌رسانی می‌شود.', tg_main_keyboard());

    $kb = ['inline_keyboard' => [[
        ['text' => '✅ تأیید', 'callback_data' => "ord_approve_{$orderId}"],
        ['text' => '❌ رد', 'callback_data' => "ord_reject_{$orderId}"],
    ]]];
    foreach (tg_admin_chat_ids() as $adminChatId) {
        tg_sendPhotoByFileId($adminChatId, $fileId, $summary, $kb);
    }
}

// ───────────────────────────── سرویس‌های من ─────────────────────────────
function tg_show_my_services(array $customer): void {
    global $pdo;
    $chatId = $customer['chat_id'];
    $links = $pdo->prepare("SELECT * FROM telegram_user_links WHERE telegram_customer_id=? ORDER BY id DESC");
    $links->execute([$customer['id']]);
    $links = $links->fetchAll();
    if (!$links) { tg_sendMessage($chatId, 'شما هنوز سرویسی از این ربات نخریده‌اید.'); return; }

    $lines = [];
    foreach ($links as $l) {
        $exp = '-';
        $status = '-';
        if ($l['ibs_uid']) {
            $inf = ibsng_call('user.getUserInfo', ['user_id' => $l['ibs_uid']]);
            $basic = $inf['result'][$l['ibs_uid']]['basic_info'] ?? [];
            $exp = !empty($basic['nearest_exp_date']) ? substr($basic['nearest_exp_date'], 0, 10) : '∞';
            $status = $basic['status'] ?? '-';
        }
        $lines[] = "👤 {$l['ibs_username']} ({$l['group_name']})\nوضعیت: {$status} | انقضا: {$exp}";
    }
    tg_sendMessage($chatId, implode("\n\n", $lines));
}

// ───────────────────────────── callback ها ─────────────────────────────
function tg_handle_callback(array $cq): void {
    $chatId = $cq['message']['chat']['id'] ?? null;
    $messageId = $cq['message']['message_id'] ?? null;
    $data = $cq['data'] ?? '';
    $cqId = $cq['id'];
    if ($chatId === null) return;

    if (str_starts_with($data, 'ord_')) {
        $adminId = tg_admin_id_by_chat($chatId);
        if ($adminId === null) { tg_answerCallbackQuery($cqId, 'دسترسی ندارید', true); return; }
        tg_handle_order_decision($adminId, $chatId, $messageId, $data, $cqId);
        return;
    }

    if (str_starts_with($data, 'pkg_')) {
        tg_customer_pick_package($chatId, (int)substr($data, 4), $cqId);
        return;
    }

    if (str_starts_with($data, 'renew_')) {
        tg_customer_pick_renew($chatId, (int)substr($data, 6), $cqId);
        return;
    }

    tg_answerCallbackQuery($cqId);
}

function tg_handle_order_decision(int $adminId, $chatId, $messageId, string $data, string $cqId): void {
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

    if (!$approve) {
        $pdo->prepare("UPDATE telegram_orders SET status='rejected', reviewed_by=?, reviewed_at=NOW() WHERE id=?")->execute([$adminId, $orderId]);
        tg_answerCallbackQuery($cqId, 'رد شد');
        if ($customer) tg_sendMessage($customer['chat_id'], '❌ متأسفانه رسید پرداخت شما تأیید نشد. برای پیگیری با پشتیبانی تماس بگیرید.');
        if ($messageId) tg_editMessageReplyMarkup($chatId, $messageId, null);
        logActivity('admin', $adminId, 'reject_direct_order', "سفارش تلگرام #$orderId رد شد");
        return;
    }

    $result = $order['order_type'] === 'new'
        ? tg_provision_new_order($order, $customer)
        : tg_provision_renew_order($order, $customer);

    if (!$result['ok']) {
        tg_answerCallbackQuery($cqId, 'خطا: ' . $result['error'], true);
        return;
    }

    $pdo->prepare("UPDATE telegram_orders SET status='approved', ibs_uid=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
        ->execute([$result['ibs_uid'] ?? $order['ibs_uid'], $adminId, $orderId]);
    tg_answerCallbackQuery($cqId, 'تأیید شد ✅');
    if ($messageId) tg_editMessageReplyMarkup($chatId, $messageId, null);
    logActivity('admin', $adminId, 'approve_direct_order', "سفارش تلگرام #$orderId تأیید شد");
}

function tg_provision_new_order(array $order, ?array $customer): array {
    global $pdo;
    $pkg = tg_get_package((int)$order['package_id']);
    if (!$pkg) return ['ok' => false, 'error' => 'بسته یافت نشد'];

    $username = $order['target_username'];
    $chk = ibsng_call('user.doesUserExists', ['normal_username' => $username]);
    if ($chk['result'] ?? false) return ['ok' => false, 'error' => 'این نام کاربری در همین حین توسط شخص دیگری گرفته شده'];

    $password = tg_generate_password();
    $gi = ibsng_call('group.getGroupInfo', ['group_name' => $pkg['group_name']]);
    $gc = $gi['result']['attrs']['group_credit'] ?? 100;

    $cr = ibsng_call('user.addNewUsers', [
        'count' => 1, 'credit' => ['1' => (float)$gc],
        'isp_name' => $pkg['isp_name'], 'group_name' => $pkg['group_name'],
        'credit_comment' => 'خرید مستقیم تلگرام',
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

    if ($customer) {
        tg_sendMessage($customer['chat_id'], "✅ سرویس شما فعال شد!\n\nنام کاربری: {$username}\nرمز عبور: {$password}\n\nاین اطلاعات را نزد خود نگه دارید.");
    }

    return ['ok' => true, 'ibs_uid' => $newUID];
}

function tg_provision_renew_order(array $order, ?array $customer): array {
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
    if (!$uid) return ['ok' => false, 'error' => 'کاربر در  یافت نشد'];

    $inf = ibsng_call('user.getUserInfo', ['user_id' => $uid]);
    $basic = $inf['result'][$uid]['basic_info'] ?? [];
    $gn = $basic['group_name'] ?? '';
    $gi = ibsng_call('group.getGroupInfo', ['group_name' => $gn]);
    $gc = $gi['result']['attrs']['group_credit'] ?? ($basic['credit'] ?? 100);
    $ga = $gi['result']['raw_attrs'] ?? [];

    ibsng_call('user.changeCredit', ['user_id' => $uid, 'credit' => (float)$gc, 'is_absolute_change' => true, 'credit_comment' => 'تمدید مستقیم تلگرام']);
    if (!empty($ga['rel_exp_date'])) {
        $m = max(1, (int)round((int)$ga['rel_exp_date'] / (30 * 24 * 3600)));
        ibsng_call('user.updateUserAttrs', ['user_id' => $uid, 'attrs' => ['abs_exp_date' => $m, 'abs_exp_date_unit' => 'months'], 'to_del_attrs' => []]);
    }
    ibsng_call('user.changeStatus', ['user_id' => $uid, 'status' => 'Recharged']);

    if ($customer) {
        tg_sendMessage($customer['chat_id'], "✅ سرویس «{$username}» با موفقیت تمدید شد.");
    }

    return ['ok' => true, 'ibs_uid' => $uid];
}

// ───────────────────────────── پنل کنترلی ادمین (دستورهای متنی) ─────────────────────────────
function tg_handle_admin_message(int $adminId, $chatId, array $msg): bool {
    global $pdo;
    $text = trim($msg['text'] ?? '');
    $caption = trim($msg['caption'] ?? '');

    if (!empty($msg['document']) && strcasecmp($caption, '/import') === 0) {
        tg_admin_do_import($chatId, $msg['document']);
        return true;
    }

    if ($text === '/export') {
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
        $online = ibsng_call('report.getOnlineUsersCount', []);
        $onlineCount = $online['result']['internet_onlines'] ?? '?';
        tg_sendMessage($chatId, "👥 ریسلرها: {$totalResellers}\n💰 کل بدهی: " . number_format($totalDebt) . " تومان\n🟢 آنلاین: {$onlineCount}");
        return true;
    }

    if ($text === '/start' || $text === '/help') {
        tg_sendMessage($chatId, "👋 پنل کنترلی ادمین در تلگرام\n\n/export - دریافت فایل Export دیتابیس\n/import - (به‌عنوان caption روی فایل .sql ارسالی) بازگردانی دیتابیس\n/pending - تعداد موارد در انتظار تأیید\n/stats - آمار سریع\n\nسفارش‌های خرید/تمدید مستقیم به‌صورت خودکار با دکمه تأیید/رد برای شما ارسال می‌شوند.");
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

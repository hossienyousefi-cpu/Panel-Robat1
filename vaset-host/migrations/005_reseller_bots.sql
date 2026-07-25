-- Migration 005: بات تلگرام اختصاصی هر ریسلر (بجای اینکه همه از یک بات
-- سراسری استفاده کنن، هر ریسلر می‌تونه توکن بات تلگرام خودش رو توی
-- reseller/telegram.php وارد کنه و مشتری‌های خودش مستقیم از همون بات خرید/
-- تمدید کنن). از طریق phpMyAdmin روی دیتابیس همین سیستم اجرا کنید.

CREATE TABLE IF NOT EXISTS reseller_bots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT NOT NULL,
    bot_token VARCHAR(150) NOT NULL,
    bot_username VARCHAR(64) NULL,
    webhook_secret VARCHAR(64) NOT NULL,
    payment_card_info TEXT NULL,
    support_message TEXT NULL,
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_reseller_bot (reseller_id),
    FOREIGN KEY (reseller_id) REFERENCES resellers(id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Chat ID شخصی خودِ ریسلر (برای دریافت کارت تأیید/رد سفارش‌های بات خودش،
-- دقیقاً هم‌خانواده‌ی admins.telegram_chat_id)
ALTER TABLE resellers ADD COLUMN telegram_chat_id VARCHAR(32) NULL AFTER phone;

-- chat_id تلگرام همون آیدی عددی کاربره، نه چیزی مخصوص یک بات؛ اگه یک نفر هم
-- با بات اصلی هم با بات یک ریسلر حرف بزنه، توی هر دو chat_id یکسانی داره -
-- برای همین باید رکورد مشتری به‌ازای هر بات (reseller_id=0 یعنی بات اصلی)
-- جدا نگه‌داری بشه، وگرنه state/سرویس‌های دو بات با هم قاطی می‌شد.
ALTER TABLE telegram_customers ADD COLUMN reseller_id INT NOT NULL DEFAULT 0 AFTER id;
ALTER TABLE telegram_customers DROP INDEX chat_id;
ALTER TABLE telegram_customers ADD UNIQUE KEY uniq_chat_reseller (chat_id, reseller_id);

-- برای تفکیک سفارش‌های بات هر ریسلر از سفارش‌های بات اصلی (فروش مستقیم ادمین)
ALTER TABLE telegram_orders ADD COLUMN reseller_id INT NOT NULL DEFAULT 0 AFTER telegram_customer_id;
ALTER TABLE telegram_orders ADD INDEX idx_orders_reseller (reseller_id);

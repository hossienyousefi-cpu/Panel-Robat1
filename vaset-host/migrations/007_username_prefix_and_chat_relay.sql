-- Migration 007: پیشوند یوزرنیم خودکار برای بات هر ریسلر + چت پشتیبانی
-- دوطرفه (مشتری توی بات پیام می‌ده، ریسلر با Reply روی همون پیام توی
-- تلگرام جواب می‌ده و مستقیم برای مشتری می‌ره). از طریق phpMyAdmin روی
-- دیتابیس همین سیستم اجرا کنید.

ALTER TABLE reseller_bots ADD COLUMN username_prefix VARCHAR(20) NULL AFTER shop_name;

-- نگاشت «پیام فوروارد‌شده به ریسلر/ادمین» → «کدوم مشتری» تا وقتی ریسلر روی
-- اون پیام Reply زد بدونیم جواب مال کدوم مشتریه و برگردونیمش بهش.
CREATE TABLE IF NOT EXISTS telegram_chat_relay (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT NOT NULL DEFAULT 0,
    customer_id INT NOT NULL,
    owner_message_id BIGINT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_relay_lookup (reseller_id, owner_message_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

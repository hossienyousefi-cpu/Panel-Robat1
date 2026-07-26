-- Migration 012: حالت مکالمه‌ی ادمین‌های بات (برای دکمه‌هایی مثل «پیام همگانی»
-- و «تغییر قیمت» که به یک پیام بعدی از خودِ ادمین نیاز دارن - مشابه همون
-- state/state_data که برای مشتری‌ها توی telegram_customers هست). از طریق
-- phpMyAdmin روی دیتابیس همین سیستم اجرا کنید.

CREATE TABLE IF NOT EXISTS bot_admin_states (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT NOT NULL DEFAULT 0,
    chat_id VARCHAR(32) NOT NULL,
    state VARCHAR(50) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_admin_state (reseller_id, chat_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

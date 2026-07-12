-- Migration 002: ربات تلگرام برای مشتریان مستقیم + کنترل پنل تلگرام ادمین
-- این فایل فقط چیزهای جدید را اضافه می‌کند و برای دیتابیس‌هایی که از قبل
-- نصب شده‌اند (payment_requests / reseller_groups از قبل موجودند) امن است.
-- از طریق phpMyAdmin روی دیتابیس همین سیستم اجرا کنید.

-- ===== چت آیدی تلگرام ادمین (برای اعلان سفارش‌ها و دستورات کنترلی) =====
-- «ADD COLUMN IF NOT EXISTS» روی نسخه‌های قدیمی‌تر MySQL/MariaDB وجود ندارد، پس با
-- information_schema چک می‌کنیم که آیا ستون از قبل هست یا نه.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'telegram_chat_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE admins ADD COLUMN telegram_chat_id VARCHAR(32) DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ===== مشتریان مستقیم ربات تلگرام =====
CREATE TABLE IF NOT EXISTS telegram_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chat_id VARCHAR(32) UNIQUE NOT NULL,
    tg_username VARCHAR(64),
    full_name VARCHAR(150),
    phone VARCHAR(20),
    state VARCHAR(50) DEFAULT NULL,
    state_data TEXT DEFAULT NULL,
    is_blocked TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ===== بسته‌های فروش مستقیم (گروه + قیمت + ISP) =====
CREATE TABLE IF NOT EXISTS direct_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_name VARCHAR(100) NOT NULL,
    title VARCHAR(150) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    isp_name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    UNIQUE KEY uniq_group (group_name)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ===== سفارش‌های مشتریان مستقیم (خرید جدید / تمدید) =====
CREATE TABLE IF NOT EXISTS telegram_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telegram_customer_id INT NOT NULL,
    order_type ENUM('new','renew') NOT NULL DEFAULT 'new',
    package_id INT NULL,
    target_username VARCHAR(50) NULL,
    chosen_password VARCHAR(50) NULL,
    ibs_uid VARCHAR(50) NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    receipt_file VARCHAR(255) NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    admin_note VARCHAR(255) NULL,
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (telegram_customer_id) REFERENCES telegram_customers(id),
    FOREIGN KEY (package_id) REFERENCES direct_packages(id),
    FOREIGN KEY (reviewed_by) REFERENCES admins(id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ===== لینک کاربران واقعی  به مشتری تلگرامی که آن را خریده (برای "سرویس‌های من") =====
CREATE TABLE IF NOT EXISTS telegram_user_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telegram_customer_id INT NOT NULL,
    ibs_username VARCHAR(50) NOT NULL,
    ibs_uid VARCHAR(50) NULL,
    group_name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (telegram_customer_id) REFERENCES telegram_customers(id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ===== migration برای نصب‌های قدیمی که این جداول را نداشتند =====
CREATE TABLE IF NOT EXISTS payment_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description TEXT,
    receipt_file VARCHAR(255) NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    admin_note VARCHAR(255) NULL,
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reseller_id) REFERENCES resellers(id),
    FOREIGN KEY (reviewed_by) REFERENCES admins(id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reseller_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT NOT NULL,
    group_name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_reseller_group (reseller_id, group_name),
    FOREIGN KEY (reseller_id) REFERENCES resellers(id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- balance/debt از همان نسخه‌ی اول install.sql همیشه در resellers بوده‌اند، نیازی به
-- ALTER جداگانه نیست.

-- ===== تنظیمات جدید =====
-- ibs_api_url جایگزین ibs_url قدیمی (که برای XML-RPC بود و includes/ibsng_api.php
-- اصلاً آن را نمی‌خواند) می‌شود تا آدرس API از پنل ادمین واقعاً روی اتصال اثر بگذارد.
INSERT INTO settings (setting_key, setting_value) VALUES
('ibs_api_url', 'http://194.59.214.84/ibs-api/'),
('telegram_bot_token', ''),
('telegram_webhook_secret', ''),
('direct_isp_name', ''),
('support_contact_message', 'برای پشتیبانی به آیدی @your_support_id در تلگرام پیام دهید.'),
('payment_card_info', 'شماره کارت: 6037-XXXX-XXXX-XXXX به نام ...')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- مهم: اگر از قبل ردیف‌های ibs_admin_user / ibs_admin_pass در جدول settings دارید که
-- هرگز واقعاً استفاده نمی‌شدند (چون includes/ibsng_api.php مقدار ثابت جدا داشت)، ممکن
-- است مقدار درستی نداشته باشند. این دو ردیف را حذف می‌کنیم تا کد به طور خودکار از
-- همان IBS_ADMIN/IBS_PASS داخل includes/config.php (که هم‌اکنون درست تنظیم است)
-- استفاده کند؛ هر وقت از admin/settings.php این دو فیلد را دوباره ذخیره کنید، مقدار
-- تازه در همین جدول ثبت و از آن پس در دیتابیس اولویت پیدا می‌کند.
DELETE FROM settings WHERE setting_key IN ('ibs_admin_user', 'ibs_admin_pass');

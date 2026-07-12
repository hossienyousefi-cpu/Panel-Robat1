-- IBSng Panel Database Schema
-- توجه: این فایل عمداً CREATE DATABASE/USE ندارد. روی هاست‌های cPanel دیتابیس از قبل
-- با پیشوند اسم کاربری (مثلاً csdfacij_panel) توسط خودتان ساخته شده و یوزر دیتابیس
-- معمولاً اجازه‌ی ساخت دیتابیس جدید را ندارد. قبل از Import کردن این فایل در
-- phpMyAdmin، از سمت چپ همان دیتابیسی که ساختید (نه ibs_panel) را انتخاب کنید تا
-- جدول‌ها داخل همان دیتابیس درست ساخته شوند.

-- Admins table
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Resellers table
CREATE TABLE IF NOT EXISTS resellers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(20),
    balance DECIMAL(10,2) DEFAULT 0.00,
    debt DECIMAL(10,2) DEFAULT 0.00,
    ibs_username VARCHAR(50),
    ibs_password VARCHAR(255),
    ibs_group VARCHAR(50),
    isp_name VARCHAR(100) DEFAULT NULL,
    can_delete_users TINYINT(1) DEFAULT 0,
    can_renew_users TINYINT(1) DEFAULT 1,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES admins(id)
);

-- Migration برای نصب‌های قدیمی
ALTER TABLE resellers ADD COLUMN IF NOT EXISTS isp_name VARCHAR(100) DEFAULT NULL;
ALTER TABLE resellers ADD COLUMN IF NOT EXISTS can_delete_users TINYINT(1) DEFAULT 0;
ALTER TABLE resellers ADD COLUMN IF NOT EXISTS can_renew_users TINYINT(1) DEFAULT 1;

-- Users (created by resellers)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT NOT NULL,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    ibs_username VARCHAR(50),
    ibs_uid VARCHAR(50),
    package_name VARCHAR(100),
    duration_days INT DEFAULT 30,
    price DECIMAL(10,2) DEFAULT 0.00,
    start_date DATE,
    expire_date DATE,
    status ENUM('active','inactive','expired') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reseller_id) REFERENCES resellers(id)
);

-- Transactions table
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT NOT NULL,
    user_id INT,
    type ENUM('charge','debit','credit','user_create','user_renew') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description TEXT,
    created_by_admin INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reseller_id) REFERENCES resellers(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (created_by_admin) REFERENCES admins(id)
);

-- Activity logs
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_type ENUM('admin','reseller') NOT NULL,
    actor_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Settings
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Payment requests (فیش پرداخت ریسلرها)
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
);

-- گروه‌های مجاز و قیمت اختصاصی هر ریسلر
CREATE TABLE IF NOT EXISTS reseller_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT NOT NULL,
    group_name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_reseller_group (reseller_id, group_name),
    FOREIGN KEY (reseller_id) REFERENCES resellers(id)
);

-- ===== ربات تلگرام مشتریان مستقیم =====
ALTER TABLE admins ADD COLUMN IF NOT EXISTS telegram_chat_id VARCHAR(32) DEFAULT NULL;

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
);

CREATE TABLE IF NOT EXISTS direct_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_name VARCHAR(100) NOT NULL,
    title VARCHAR(150) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    isp_name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    UNIQUE KEY uniq_group (group_name)
);

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
);

CREATE TABLE IF NOT EXISTS telegram_user_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telegram_customer_id INT NOT NULL,
    ibs_username VARCHAR(50) NOT NULL,
    ibs_uid VARCHAR(50) NULL,
    group_name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (telegram_customer_id) REFERENCES telegram_customers(id)
);

-- Default admin (password: Admin@1234)
INSERT INTO admins (username, password, email) VALUES
('admin', '$2y$12$xKBPbKBPbKBPbKBPbKBPbOQfHjQfHjQfHjQfHjQfHjQfHjQfHjQf2', 'admin@example.com')
ON DUPLICATE KEY UPDATE id=id;

-- Default settings
-- توجه: ibs_admin_user/ibs_admin_pass عمداً اینجا seed نمی‌شوند - وقتی این دو ردیف در
-- جدول وجود نداشته باشند، includes/ibsng_api.php به‌طور خودکار از IBS_ADMIN/IBS_PASS
-- داخل includes/config.php استفاده می‌کند؛ از admin/settings.php هر وقت خواستید
-- override کنید همین‌جا ثبت می‌شود و از آن پس اولویت با دیتابیس است.
INSERT INTO settings (setting_key, setting_value) VALUES
('ibs_api_url', 'http://194.59.214.84/ibs-api/'),
('site_name', 'پنل مدیریت'),
('currency', 'تومان'),
('user_create_price', '5000'),
('user_renew_price_per_day', '200'),
('telegram_bot_token', ''),
('telegram_webhook_secret', ''),
('direct_isp_name', ''),
('support_contact_message', 'برای پشتیبانی به آیدی @your_support_id در تلگرام پیام دهید.'),
('payment_card_info', 'شماره کارت: 6037-XXXX-XXXX-XXXX به نام ...')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

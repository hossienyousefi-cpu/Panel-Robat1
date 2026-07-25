-- Migration 008: ادمین‌های چندگانه‌ی بات + سیستم تیکت پشتیبانی + حساب‌های
-- دریافت وجه + فایل‌های OpenVPN + قیمت جداگانه برای نمایش به مشتری + لاگ
-- اینکه کدوم ادمین سفارش رو تأیید/رد کرده. از طریق phpMyAdmin روی دیتابیس
-- همین سیستم اجرا کنید.

-- ─── ادمین‌های اضافه‌ی هر بات. reseller_id=0 یعنی بات اصلی/سراسری پنل، عدد
-- دیگه یعنی بات اختصاصی همون ریسلر. این جدول جدای از "صاحب" بات (خودِ ادمین
-- اصلی برای بات اصلی، خودِ ریسلر برای بات اختصاصی‌اش) است - برای دادن دسترسیِ
-- تأیید سفارش/پاسخ تیکت به کسی که لازم نیست حتماً لاگین پنل وب داشته باشه ───
CREATE TABLE IF NOT EXISTS bot_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT NOT NULL DEFAULT 0,
    telegram_chat_id VARCHAR(32) NOT NULL,
    display_name VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_bot_admin (reseller_id, telegram_chat_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ─── حساب‌های بانکی/کارت برای دریافت وجه از مشتری. چند حساب می‌شه اضافه کرد،
-- فقط یکی برای هر بات «فعال» (نمایش داده‌شده به مشتری) است ───
CREATE TABLE IF NOT EXISTS payment_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT NOT NULL DEFAULT 0,
    bank_name VARCHAR(100) NULL,
    card_number VARCHAR(40) NULL,
    account_holder VARCHAR(100) NULL,
    extra_note VARCHAR(255) NULL,
    is_active TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ─── فایل‌های کانفیگ OpenVPN قابل دانلود برای مشتری (چند فایل برای چند
-- سرور مختلف، مشترک بین همه‌ی پلن‌ها) ───
CREATE TABLE IF NOT EXISTS ovpn_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT NOT NULL DEFAULT 0,
    title VARCHAR(100) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ─── تیکت‌های پشتیبانی دوطرفه: مشتری توی بات پیام می‌ده، همه‌ی ادمین‌های بات
-- (صاحب + bot_admins) با دکمه‌ی «پذیرش» مطلع می‌شن، اولین کسی که پذیرفت
-- claimed_by می‌شه و فقط اون ادامه‌ی گفتگو رو می‌گیره تا وقتی ببنده‌ش ───
CREATE TABLE IF NOT EXISTS support_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT NOT NULL DEFAULT 0,
    telegram_customer_id INT NOT NULL,
    status ENUM('open','claimed','closed') DEFAULT 'open',
    initial_text TEXT NULL,
    broadcast_json TEXT NULL,
    claimed_by_chat_id VARCHAR(32) NULL,
    claimed_by_name VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    claimed_at TIMESTAMP NULL,
    closed_at TIMESTAMP NULL,
    FOREIGN KEY (telegram_customer_id) REFERENCES telegram_customers(id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ─── قیمت اختصاصیِ نمایش به مشتریِ بات (پایه + سود ریسلر)، جدای از قیمتی که
-- ادمین برای خودِ ریسلر (هزینه واقعی) تنظیم کرده. اگه خالی/صفر باشه، همون
-- قیمت پایه نمایش داده می‌شه (رفتار قبلی حفظ می‌شه) ───
ALTER TABLE reseller_groups ADD COLUMN customer_price DECIMAL(10,2) NULL AFTER price;

-- ─── اینکه دقیقاً کدوم ادمین (چه از پنل وب چه از تلگرام) یک سفارش رو
-- تأیید/رد کرده - برای لاگ و شفافیت وقتی چند ادمین روی یک بات کار می‌کنن ───
ALTER TABLE telegram_orders ADD COLUMN reviewed_by_name VARCHAR(150) NULL AFTER reviewed_by;

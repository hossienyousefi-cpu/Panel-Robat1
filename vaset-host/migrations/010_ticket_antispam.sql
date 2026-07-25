-- Migration 010: جلوگیریِ قطعی (در سطح دیتابیس) از باز شدن بیش از یک تیکت
-- پشتیبانیِ باز/در-حال-بررسی به‌ازای هر مشتری - حتی اگه چند پیام تلگرام
-- تقریباً هم‌زمان برسند (race condition). ستون open_lock فقط وقتی مقدار
-- می‌گیره که تیکت هنوز open یا claimed باشه (بسته‌ها NULL می‌مونن و NULL
-- توی MySQL باعث تداخل با UNIQUE نمی‌شه). از طریق phpMyAdmin روی دیتابیس
-- همین سیستم اجرا کنید.

ALTER TABLE support_tickets
    ADD COLUMN open_lock INT GENERATED ALWAYS AS (
        CASE WHEN status IN ('open','claimed') THEN telegram_customer_id ELSE NULL END
    ) VIRTUAL;

ALTER TABLE support_tickets ADD UNIQUE KEY uniq_open_ticket (reseller_id, open_lock);

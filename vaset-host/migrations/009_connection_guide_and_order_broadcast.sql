-- Migration 009: متن راهنمای اتصال هر بات + نگاشت پیام‌های کارت سفارش که
-- برای همه‌ی ادمین‌های بات فرستاده می‌شه (تا بعد از تأیید/رد، خلاصه‌ی «کی
-- تأیید کرد» روی کپشن همه‌ی نسخه‌ها ویرایش بشه). از طریق phpMyAdmin روی
-- دیتابیس همین سیستم اجرا کنید.

ALTER TABLE reseller_bots ADD COLUMN connection_guide TEXT NULL AFTER support_message;

ALTER TABLE telegram_orders ADD COLUMN broadcast_json TEXT NULL AFTER receipt_file;

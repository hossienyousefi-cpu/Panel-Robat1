-- Migration 006: برندینگ بات هر ریسلر (اسم فروشگاه + پیام خوش‌آمد اختصاصی)
-- تا هر ریسلر بتونه ظاهر بات خودش رو با اسم مغازه/کسب‌وکار خودش شخصی‌سازی
-- کنه. از طریق phpMyAdmin روی دیتابیس همین سیستم اجرا کنید.

ALTER TABLE reseller_bots ADD COLUMN shop_name VARCHAR(150) NULL AFTER bot_username;
ALTER TABLE reseller_bots ADD COLUMN welcome_message TEXT NULL AFTER support_message;

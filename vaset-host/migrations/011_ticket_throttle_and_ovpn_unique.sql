-- Migration 011: جلوگیری از اسپم شدن کارت تیکت با پیام‌های پشت‌سرهم مشتری +
-- جلوگیری از ثبت دوباره‌ی یک فایل OpenVPN با همون عنوان. از طریق phpMyAdmin
-- روی دیتابیس همین سیستم اجرا کنید.

-- ─── قبل از این خط، اگه توی «فایل‌های OpenVPN» (admin/telegram.php یا
-- reseller/telegram.php) چند ردیف با عنوان یکسان می‌بینید (مثلاً از یک آپلود
-- تصادفیِ دوباره)، اول از همون‌جا با دکمه‌ی «حذف» همه‌شون رو پاک کنید و فقط
-- یکی نگه دارید - وگرنه همین خط پایین با خطای Duplicate entry متوقف می‌شه ───
ALTER TABLE ovpn_files ADD UNIQUE KEY uniq_ovpn_title (reseller_id, title);

ALTER TABLE support_tickets ADD COLUMN last_message_at TIMESTAMP NULL AFTER broadcast_json;

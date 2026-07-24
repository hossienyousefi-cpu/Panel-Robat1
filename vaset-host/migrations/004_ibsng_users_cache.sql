-- Migration 004: کش محلی کاربران IBSng برای سرچ سریع‌تر و بدون فشار روی IBSng
-- این جدول با اسکریپت cron/sync_ibsng_users.php هر چند دقیقه (پیشنهادی: ۵ دقیقه)
-- از IBSng پر می‌شود. صفحات سرچ کاربران (ادمین/ریسلر) اگر این جدول تازه باشد،
-- به‌جای زدن مستقیم به IBSng، از همین جدول (سریع‌تر و بدون فشار روی IBSng) می‌خوانند.
-- اگر جدول خالی/قدیمی باشد (مثلاً هنوز کرون اجرا نشده)، کد به‌طور خودکار به روش
-- قدیمی (زنده از IBSng) برمی‌گردد - یعنی نصب این migration به‌تنهایی هیچ‌چیزی را
-- خراب نمی‌کند، فقط بعد از راه‌اندازی کرون فعال می‌شود.

CREATE TABLE IF NOT EXISTS ibsng_users_cache (
    uid VARCHAR(20) PRIMARY KEY,
    username VARCHAR(50) NOT NULL DEFAULT '',
    password VARCHAR(100) NOT NULL DEFAULT '',
    status VARCHAR(30) NOT NULL DEFAULT '',
    group_name VARCHAR(100) NOT NULL DEFAULT '',
    isp_name VARCHAR(100) NOT NULL DEFAULT '',
    ras_ip VARCHAR(100) NOT NULL DEFAULT '',
    credit DECIMAL(12,2) NOT NULL DEFAULT 0,
    exp_date VARCHAR(20) NOT NULL DEFAULT '',
    exp_ts INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ibsng_cache_username (username),
    INDEX idx_ibsng_cache_isp (isp_name),
    INDEX idx_ibsng_cache_group (group_name),
    INDEX idx_ibsng_cache_isp_group (isp_name, group_name),
    INDEX idx_ibsng_cache_exp_ts (exp_ts),
    INDEX idx_ibsng_cache_updated (updated_at)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

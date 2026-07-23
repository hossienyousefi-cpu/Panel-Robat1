-- Migration 003: لاگ تمدیدها (برای گزارش «کاربران تمدیدشده» با فیلتر بازه‌ی تاریخ
-- در ادمین و ریسلر). قبل از این، هیچ رکورد قابل‌جستجویی از تمدیدها (نام کاربری +
-- تاریخ) نگه‌داری نمی‌شد. از طریق phpMyAdmin روی دیتابیس همین سیستم اجرا کنید.

CREATE TABLE IF NOT EXISTS renewal_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ibs_username VARCHAR(50) NOT NULL,
    isp_name VARCHAR(100) NOT NULL DEFAULT '',
    group_name VARCHAR(100) NOT NULL DEFAULT '',
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    renewed_by ENUM('admin','reseller','telegram') NOT NULL,
    reseller_id INT NULL,
    admin_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reseller_id) REFERENCES resellers(id),
    FOREIGN KEY (admin_id) REFERENCES admins(id),
    INDEX idx_renewal_created_at (created_at),
    INDEX idx_renewal_reseller (reseller_id),
    INDEX idx_renewal_username (ibs_username)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

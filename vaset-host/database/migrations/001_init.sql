-- Panel Vaset - schema for the dedicated system database (separate from IBSng's own DB)
-- Charset utf8mb4 for Persian text (usernames, names, notes).

CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(128) NOT NULL,
    telegram_chat_id BIGINT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resellers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(128) NOT NULL,
    phone VARCHAR(32) NULL,
    ibsng_isp VARCHAR(64) NOT NULL,
    balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibsng_groups_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_name VARCHAR(128) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ibsng_isps_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    isp_name VARCHAR(128) NOT NULL UNIQUE,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reseller_group_pricing (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reseller_id INT UNSIGNED NOT NULL,
    group_name VARCHAR(128) NOT NULL,
    price DECIMAL(14,2) NOT NULL,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uniq_reseller_group (reseller_id, group_name),
    CONSTRAINT fk_rgp_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS direct_catalog_pricing (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_name VARCHAR(128) NOT NULL UNIQUE,
    display_name VARCHAR(128) NULL,
    price DECIMAL(14,2) NOT NULL,
    is_visible TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL UNIQUE,
    first_name VARCHAR(128) NULL,
    tg_username VARCHAR(128) NULL,
    phone VARCHAR(32) NULL,
    balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    conversation_state VARCHAR(64) NULL,
    conversation_payload TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS managed_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ibsng_username VARCHAR(128) NOT NULL UNIQUE,
    owner_type ENUM('reseller','direct') NOT NULL,
    reseller_id INT UNSIGNED NULL,
    telegram_customer_id INT UNSIGNED NULL,
    group_name VARCHAR(128) NOT NULL,
    isp VARCHAR(64) NOT NULL,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reseller (reseller_id),
    KEY idx_telegram_customer (telegram_customer_id),
    KEY idx_expires (expires_at),
    CONSTRAINT fk_mu_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE SET NULL,
    CONSTRAINT fk_mu_tg_customer FOREIGN KEY (telegram_customer_id) REFERENCES telegram_customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_type ENUM('new','renew') NOT NULL,
    telegram_customer_id INT UNSIGNED NOT NULL,
    group_name VARCHAR(128) NOT NULL,
    target_username VARCHAR(128) NULL,
    price DECIMAL(14,2) NOT NULL,
    status ENUM('pending_payment','under_review','approved','rejected','expired_unpaid') NOT NULL DEFAULT 'pending_payment',
    provisioned_username VARCHAR(128) NULL,
    paid_notified_overdue TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    decided_by INT UNSIGNED NULL,
    KEY idx_status (status),
    KEY idx_tg_customer (telegram_customer_id),
    CONSTRAINT fk_order_tg_customer FOREIGN KEY (telegram_customer_id) REFERENCES telegram_customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_admin FOREIGN KEY (decided_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receipts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NULL,
    reseller_id INT UNSIGNED NULL,
    amount DECIMAL(14,2) NOT NULL,
    tracking_code VARCHAR(64) NULL,
    image_path VARCHAR(255) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    admin_note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    reviewed_by INT UNSIGNED NULL,
    KEY idx_status (status),
    CONSTRAINT fk_receipt_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_receipt_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE CASCADE,
    CONSTRAINT fk_receipt_admin FOREIGN KEY (reviewed_by) REFERENCES admins(id) ON DELETE SET NULL,
    CONSTRAINT chk_receipt_owner CHECK (
        (order_id IS NOT NULL AND reseller_id IS NULL) OR
        (order_id IS NULL AND reseller_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ledger_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_type ENUM('reseller','telegram_customer') NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    entry_type ENUM('credit_topup','debit_purchase','debit_renew','credit_refund','adjustment') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    balance_after DECIMAL(14,2) NOT NULL,
    reference VARCHAR(64) NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_account (account_type, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS online_sessions_cache (
    username VARCHAR(128) NOT NULL PRIMARY KEY,
    nas_ip VARCHAR(64) NULL,
    framed_ip VARCHAR(64) NULL,
    session_start DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type ENUM('admin','reseller','system') NOT NULL,
    actor_id INT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    target VARCHAR(128) NULL,
    meta TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_actor (actor_type, actor_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

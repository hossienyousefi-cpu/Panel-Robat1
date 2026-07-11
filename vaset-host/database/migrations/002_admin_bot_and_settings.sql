-- Task 4: runtime-editable connection settings + admin-side Telegram bot control panel
-- (database import/export via Telegram, and the ability to change how the bot talks to
-- the IBSng Agent without redeploying, in case the connection breaks).

ALTER TABLE admins
    ADD COLUMN conversation_state VARCHAR(64) NULL AFTER telegram_chat_id,
    ADD COLUMN conversation_payload TEXT NULL AFTER conversation_state;

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

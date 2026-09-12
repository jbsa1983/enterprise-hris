-- Migration: Telegram links (per-user chat id + link code). Safe to re-run.
--   mysql -u <db_user> -p <db_name> < db/migrate-telegram.sql
CREATE TABLE IF NOT EXISTS telegram_links (
  user_id INT PRIMARY KEY,
  chat_id VARCHAR(40) NULL,
  link_code VARCHAR(40) NULL,
  INDEX (link_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

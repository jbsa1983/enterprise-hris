-- Migration: in-app notifications (for the bell). Safe to run more than once.
--   mysql -u <db_user> -p <db_name> < db/migrate-notifications.sql
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  type VARCHAR(40) NOT NULL,
  title VARCHAR(200) NOT NULL,
  body VARCHAR(500) NULL,
  link VARCHAR(200) NULL,
  is_read TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id), INDEX (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

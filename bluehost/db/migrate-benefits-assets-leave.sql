-- Migration: benefits + beneficiaries, asset event history, and leave-credit use.
-- Safe to run more than once (IF NOT EXISTS / additive). Run once on the server:
--   mysql -u <db_user> -p <db_name> < db/migrate-benefits-assets-leave.sql

-- --- Benefits (health coverage, life, etc.) ----------------------------------
CREATE TABLE IF NOT EXISTS benefits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  person_id INT NOT NULL,
  engagement_id INT NULL,
  benefit_type VARCHAR(60) NOT NULL,
  provider VARCHAR(120) NULL,
  policy_number VARCHAR(80) NULL,
  coverage_amount DECIMAL(14,2) NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  status VARCHAR(20) DEFAULT 'ACTIVE',
  remarks TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (organization_id), INDEX (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS benefit_beneficiaries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  benefit_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  relationship VARCHAR(60) NULL,
  share_percent DECIMAL(6,2) NULL,
  contact VARCHAR(80) NULL,
  INDEX (benefit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Asset lifecycle history (assign / return / reassign, with remarks) ------
CREATE TABLE IF NOT EXISTS asset_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT NOT NULL,
  event_type VARCHAR(20) NOT NULL,       -- ASSIGN / RETURN / REASSIGN / NOTE
  person_id INT NULL,                     -- who it involved (assignee / returner)
  item_condition VARCHAR(50) NULL,
  remarks TEXT NULL,
  event_date DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

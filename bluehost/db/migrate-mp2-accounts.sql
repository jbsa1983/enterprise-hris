-- Pag-IBIG MP2 accounts (one or more per person, for employees and consultants).
-- Each has its own MP2 account number (separate from the compulsory HDMF number),
-- an employee monthly share and an employer monthly share. Idempotent.
CREATE TABLE IF NOT EXISTS mp2_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  engagement_id INT NOT NULL,
  account_number VARCHAR(60) NULL,
  employee_share DECIMAL(10,2) DEFAULT 0,
  employer_share DECIMAL(10,2) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (organization_id), INDEX (engagement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

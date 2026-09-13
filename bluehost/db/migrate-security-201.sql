-- Security & accountability + Employee 201 files
-- Safe to run on an existing database (idempotent: IF NOT EXISTS).
-- Import via phpMyAdmin (select your DB → Import) or:
--   mysql -u USER -p DBNAME < migrate-security-201.sql

-- Login brute-force throttle: locks an email+IP after repeated failed logins.
CREATE TABLE IF NOT EXISTS login_throttle (
  identifier VARCHAR(255) PRIMARY KEY,   -- lowercased email + '|' + client IP
  fails INT DEFAULT 0,
  first_fail_at DATETIME NULL,
  locked_until DATETIME NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Employee 201-file documents (contracts, IDs, certificates...).
-- Files are stored under storage/documents and served only through PHP with auth.
CREATE TABLE IF NOT EXISTS employee_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  person_id INT NOT NULL,
  category VARCHAR(60) NULL,
  title VARCHAR(255) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  object_key VARCHAR(255) NOT NULL,
  content_type VARCHAR(120) NULL,
  size_bytes INT DEFAULT 0,
  uploaded_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (organization_id), INDEX (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

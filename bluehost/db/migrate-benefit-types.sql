-- Per-organization benefit-type catalog (so benefit types can be pre-defined
-- and copied to other orgs, like leave types). Safe to run more than once.
CREATE TABLE IF NOT EXISTS benefit_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

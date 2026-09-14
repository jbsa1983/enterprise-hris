-- Project (construction) payroll: short-cycle pay runs (weekly / every few days) for
-- daily-wage project workers. Pay = daily rate x days worked (+ OT/allowance); statutory
-- is prorated by time worked (days/22) and tagged to the project so SSS/PhilHealth/Pag-IBIG
-- and 13th month can be reported and remitted per project (DOLE). Idempotent.
CREATE TABLE IF NOT EXISTS project_pay_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  project_id INT NOT NULL,
  reference VARCHAR(120) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  pay_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  gross_total DECIMAL(16,2) DEFAULT 0,
  statutory_total DECIMAL(16,2) DEFAULT 0,
  deduction_total DECIMAL(16,2) DEFAULT 0,
  net_total DECIMAL(16,2) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (organization_id), INDEX (project_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_pay_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL,
  engagement_id INT NOT NULL,
  days_worked DECIMAL(6,2) DEFAULT 0,
  daily_rate DECIMAL(14,2) DEFAULT 0,
  ot_amount DECIMAL(14,2) DEFAULT 0,
  allowance DECIMAL(14,2) DEFAULT 0,
  basic_pay DECIMAL(14,2) DEFAULT 0,
  gross_pay DECIMAL(14,2) DEFAULT 0,
  sss DECIMAL(12,2) DEFAULT 0,
  philhealth DECIMAL(12,2) DEFAULT 0,
  pagibig DECIMAL(12,2) DEFAULT 0,
  withholding_tax DECIMAL(12,2) DEFAULT 0,
  other_deduction DECIMAL(12,2) DEFAULT 0,
  total_deductions DECIMAL(12,2) DEFAULT 0,
  net_pay DECIMAL(14,2) DEFAULT 0,
  remarks VARCHAR(255) NULL,
  INDEX (run_id), INDEX (engagement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

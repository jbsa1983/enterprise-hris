-- =============================================================================
-- GEEK Group Enterprise HRIS — MySQL schema (Bluehost / shared hosting build)
-- Import via phpMyAdmin (Import tab) or: mysql -u USER -p DBNAME < schema.sql
-- MySQL 8.0+ (JSON columns). Charset utf8mb4.
-- =============================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- --- Identity / RBAC ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT NOT NULL,
  permission_id INT NOT NULL,
  PRIMARY KEY (role_id, permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enterprises (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  code VARCHAR(50) NOT NULL UNIQUE,
  description TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS organizations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  enterprise_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  code VARCHAR(50) NOT NULL UNIQUE,
  legal_name VARCHAR(255) NULL,
  tin VARCHAR(50) NULL,
  address TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (enterprise_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS people (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NOT NULL,
  suffix VARCHAR(20) NULL,
  preferred_name VARCHAR(100) NULL,
  birth_date DATE NULL,
  gender VARCHAR(20) NULL,
  civil_status VARCHAR(20) NULL,
  email VARCHAR(255) NULL,
  mobile VARCHAR(50) NULL,
  address TEXT NULL,
  emergency_contact VARCHAR(255) NULL,
  tin VARCHAR(50) NULL,
  sss_number VARCHAR(50) NULL,
  philhealth_number VARCHAR(50) NULL,
  pagibig_number VARCHAR(50) NULL,
  bank_name VARCHAR(100) NULL,
  bank_account_number VARCHAR(50) NULL,
  bank_account_name VARCHAR(255) NULL,
  profile_image_key VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  full_name VARCHAR(255) NOT NULL,
  hashed_password VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_superadmin TINYINT(1) NOT NULL DEFAULT 0,
  person_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT NOT NULL,
  role_id INT NOT NULL,
  PRIMARY KEY (user_id, role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS organization_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  user_id INT NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_org_user (organization_id, user_id),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Org structure -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(50) NULL,
  parent_id INT NULL,
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS positions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  title VARCHAR(150) NOT NULL,
  job_grade VARCHAR(50) NULL,
  department_id INT NULL,
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cost_centers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL,
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Engagements / projects --------------------------------------------------
CREATE TABLE IF NOT EXISTS engagements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  person_id INT NOT NULL,
  organization_id INT NOT NULL,
  engagement_type VARCHAR(40) NOT NULL,
  employee_number VARCHAR(50) NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  regularization_date DATE NULL,
  department_id INT NULL,
  position_id INT NULL,
  supervisor_id INT NULL,
  job_grade VARCHAR(50) NULL,
  salary_basis VARCHAR(40) NULL,
  base_rate DECIMAL(14,2) NULL,
  payroll_group VARCHAR(50) NULL,
  cost_center VARCHAR(50) NULL,
  project_id INT NULL,
  work_site VARCHAR(100) NULL,
  tax_profile VARCHAR(50) NULL,
  statutory_profile VARCHAR(50) NULL,
  benefits_profile VARCHAR(50) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (person_id), INDEX (organization_id), INDEX (employee_number), INDEX (status), INDEX (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  project_code VARCHAR(50) NOT NULL,
  project_name VARCHAR(255) NOT NULL,
  client_id INT NULL,
  project_manager VARCHAR(255) NULL,
  start_date DATE NULL,
  target_end_date DATE NULL,
  actual_end_date DATE NULL,
  site VARCHAR(150) NULL,
  cost_center VARCHAR(50) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  project_budget DECIMAL(16,2) NULL,
  labor_budget DECIMAL(16,2) NULL,
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  engagement_id INT NOT NULL,
  project_id INT NOT NULL,
  assignment_start DATE NULL,
  assignment_end DATE NULL,
  site VARCHAR(150) NULL,
  supervisor VARCHAR(255) NULL,
  billable TINYINT(1) DEFAULT 0,
  billing_rate DECIMAL(14,2) NULL,
  pay_rate DECIMAL(14,2) NULL,
  shift VARCHAR(50) NULL,
  remarks TEXT NULL,
  INDEX (engagement_id), INDEX (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_budget_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  project_id INT NOT NULL,
  period_label VARCHAR(20) NOT NULL,
  amount DECIMAL(16,2) DEFAULT 0,
  UNIQUE KEY uq_project_period (project_id, period_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Statutory / payroll -----------------------------------------------------
CREATE TABLE IF NOT EXISTS statutory_rule_sets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rule_name VARCHAR(50) NOT NULL,
  rule_version VARCHAR(50) NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  is_prototype_data TINYINT(1) DEFAULT 1,
  parameters_json JSON NOT NULL,
  notes TEXT NULL,
  INDEX (rule_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_periods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  frequency VARCHAR(20) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  pay_date DATE NULL,
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  period_id INT NOT NULL,
  reference VARCHAR(80) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
  rule_version_snapshot JSON NULL,
  gross_total DECIMAL(16,2) DEFAULT 0,
  deduction_total DECIMAL(16,2) DEFAULT 0,
  net_total DECIMAL(16,2) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (organization_id), INDEX (period_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_run_people (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL,
  engagement_id INT NOT NULL,
  gross_pay DECIMAL(14,2) DEFAULT 0,
  total_deductions DECIMAL(14,2) DEFAULT 0,
  net_pay DECIMAL(14,2) DEFAULT 0,
  earnings JSON NULL,
  deductions JSON NULL,
  INDEX (run_id), INDEX (engagement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payslips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  payroll_run_id INT NOT NULL,
  engagement_id INT NOT NULL,
  person_id INT NOT NULL,
  document_type VARCHAR(20) DEFAULT 'PAYSLIP',
  version INT DEFAULT 1,
  is_current TINYINT(1) DEFAULT 1,
  status VARCHAR(20) DEFAULT 'ISSUED',
  net_pay DECIMAL(14,2) DEFAULT 0,
  snapshot JSON NOT NULL,
  pdf_object_key VARCHAR(255) NULL,
  generated_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (payroll_run_id), INDEX (engagement_id), INDEX (person_id), INDEX (is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Loans / obligations -----------------------------------------------------
CREATE TABLE IF NOT EXISTS loans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  person_id INT NOT NULL,
  engagement_id INT NULL,
  obligation_type VARCHAR(40) NOT NULL,
  reference_number VARCHAR(80) NULL,
  description VARCHAR(255) NULL,
  principal DECIMAL(14,2) DEFAULT 0,
  interest DECIMAL(14,2) DEFAULT 0,
  total_amount DECIMAL(14,2) DEFAULT 0,
  amount_paid DECIMAL(14,2) DEFAULT 0,
  balance DECIMAL(14,2) DEFAULT 0,
  installment_amount DECIMAL(14,2) DEFAULT 0,
  start_period VARCHAR(20) NULL,
  end_period VARCHAR(20) NULL,
  status VARCHAR(20) DEFAULT 'ACTIVE',
  payroll_deductible TINYINT(1) DEFAULT 1,
  direct_payment_allowed TINYINT(1) DEFAULT 0,
  INDEX (organization_id), INDEX (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loan_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loan_id INT NOT NULL,
  entry_type VARCHAR(30) NOT NULL,
  amount DECIMAL(14,2) DEFAULT 0,
  balance_after DECIMAL(14,2) DEFAULT 0,
  payroll_run_id INT NULL,
  period_label VARCHAR(30) NULL,
  entry_date DATE NULL,
  remarks TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (loan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Assets ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  asset_number VARCHAR(80) NOT NULL,
  item VARCHAR(150) NOT NULL,
  serial_number VARCHAR(120) NULL,
  is_employee_payable TINYINT(1) DEFAULT 0,
  assigned_person_id INT NULL,
  issue_date DATE NULL,
  cost DECIMAL(14,2) NULL,
  employee_share DECIMAL(14,2) NULL,
  installment DECIMAL(14,2) NULL,
  outstanding_balance DECIMAL(14,2) NULL,
  returned_date DATE NULL,
  `condition` VARCHAR(50) NULL,
  status VARCHAR(20) DEFAULT 'ISSUED',
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Attendance / leave / HR -------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  engagement_id INT NOT NULL,
  log_date DATE NOT NULL,
  time_in DATETIME NULL,
  time_out DATETIME NULL,
  hours_worked DECIMAL(6,2) DEFAULT 0,
  late_minutes INT DEFAULT 0,
  undertime_minutes INT DEFAULT 0,
  overtime_hours DECIMAL(6,2) DEFAULT 0,
  night_diff_hours DECIMAL(6,2) DEFAULT 0,
  source VARCHAR(20) DEFAULT 'MANUAL',
  status VARCHAR(20) DEFAULT 'PRESENT',
  INDEX (organization_id), INDEX (engagement_id), INDEX (log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leave_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  engagement_id INT NOT NULL,
  leave_type VARCHAR(40) NOT NULL,
  date_from DATE NULL,
  date_to DATE NULL,
  days DECIMAL(6,2) DEFAULT 0,
  status VARCHAR(20) DEFAULT 'PENDING',
  INDEX (organization_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS overtime_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  engagement_id INT NOT NULL,
  ot_date DATE NULL,
  hours DECIMAL(6,2) DEFAULT 0,
  status VARCHAR(20) DEFAULT 'PENDING',
  INDEX (organization_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Special pay (13th month / bonuses) --------------------------------------
CREATE TABLE IF NOT EXISTS special_pay_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  pay_type VARCHAR(20) NOT NULL,
  name VARCHAR(120) NOT NULL,
  year INT NOT NULL,
  status VARCHAR(20) DEFAULT 'DRAFT',
  total_amount DECIMAL(16,2) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS special_pay_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL,
  engagement_id INT NOT NULL,
  computed_amount DECIMAL(14,2) DEFAULT 0,
  override_amount DECIMAL(14,2) NULL,
  remarks TEXT NULL,
  INDEX (run_id), INDEX (engagement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Recruitment -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_requisitions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  title VARCHAR(150) NOT NULL,
  department_id INT NULL,
  project_id INT NULL,
  headcount INT DEFAULT 1,
  status VARCHAR(20) DEFAULT 'OPEN',
  job_description TEXT NULL,
  placement_type VARCHAR(20) DEFAULT 'OFFICE',
  employment_type VARCHAR(40) NULL,
  target_start_date DATE NULL,
  target_end_date DATE NULL,
  budget DECIMAL(14,2) NULL,
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS applicants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(255) NULL,
  mobile VARCHAR(50) NULL,
  resume_object_key VARCHAR(255) NULL,
  hired_person_id INT NULL,
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS job_applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  requisition_id INT NOT NULL,
  applicant_id INT NOT NULL,
  stage VARCHAR(20) DEFAULT 'NEW',
  notes TEXT NULL,
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Bank export -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bank_export_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  template_name VARCHAR(120) NOT NULL,
  bank_name VARCHAR(120) NOT NULL,
  template_version INT DEFAULT 1,
  file_type VARCHAR(20) DEFAULT 'CSV',
  delimiter VARCHAR(4) DEFAULT ',',
  encoding VARCHAR(20) DEFAULT 'utf-8',
  header_required TINYINT(1) DEFAULT 1,
  footer_required TINYINT(1) DEFAULT 0,
  date_format VARCHAR(30) DEFAULT '%Y-%m-%d',
  decimal_places INT DEFAULT 2,
  filename_pattern VARCHAR(120) DEFAULT '{bank}_{org}_{date}',
  active TINYINT(1) DEFAULT 1,
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bank_export_columns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_id INT NOT NULL,
  order_index INT DEFAULT 0,
  system_field VARCHAR(60) NOT NULL,
  output_header VARCHAR(60) NOT NULL,
  default_value VARCHAR(120) NULL,
  formatting VARCHAR(60) NULL,
  padding VARCHAR(30) NULL,
  required TINYINT(1) DEFAULT 0,
  INDEX (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Audit -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  user_email VARCHAR(255) NULL,
  organization_id INT NULL,
  action VARCHAR(100) NOT NULL,
  entity VARCHAR(100) NULL,
  entity_id VARCHAR(80) NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  ip_address VARCHAR(64) NULL,
  user_agent TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id), INDEX (organization_id), INDEX (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Leave config / timekeeping ---------------------------------------------
CREATE TABLE IF NOT EXISTS leave_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(80) NOT NULL,
  default_credits DECIMAL(6,2) DEFAULT 0,
  paid TINYINT(1) DEFAULT 1,
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leave_balances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  engagement_id INT NOT NULL,
  leave_type VARCHAR(80) NOT NULL,
  credits DECIMAL(6,2) DEFAULT 0,
  used DECIMAL(6,2) DEFAULT 0,
  INDEX (organization_id), INDEX (engagement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Performance -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS performance_cycles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  cycle_type VARCHAR(30) DEFAULT 'ANNUAL',
  period_start DATE NULL, period_end DATE NULL,
  status VARCHAR(20) DEFAULT 'OPEN',
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS performance_reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  cycle_id INT NOT NULL,
  engagement_id INT NOT NULL,
  self_score DECIMAL(5,2) NULL,
  supervisor_score DECIMAL(5,2) NULL,
  final_rating DECIMAL(5,2) NULL,
  status VARCHAR(20) DEFAULT 'DRAFT',
  comments TEXT NULL,
  INDEX (organization_id), INDEX (cycle_id), INDEX (engagement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Training ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS training_courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  title VARCHAR(150) NOT NULL,
  category VARCHAR(80) NULL,
  provider VARCHAR(120) NULL,
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS training_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  course_id INT NOT NULL,
  engagement_id INT NOT NULL,
  status VARCHAR(20) DEFAULT 'ASSIGNED',
  completed_date DATE NULL,
  certificate_expiry DATE NULL,
  INDEX (organization_id), INDEX (course_id), INDEX (engagement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Service desk ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  ticket_number VARCHAR(40) NOT NULL UNIQUE,
  engagement_id INT NULL,
  category VARCHAR(60) NOT NULL,
  priority VARCHAR(20) DEFAULT 'NORMAL',
  assigned_hr VARCHAR(120) NULL,
  status VARCHAR(20) DEFAULT 'OPEN',
  subject VARCHAR(200) NOT NULL,
  description TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (organization_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Onboarding / offboarding ------------------------------------------------
CREATE TABLE IF NOT EXISTS lifecycle_checklists (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  engagement_id INT NOT NULL,
  kind VARCHAR(20) DEFAULT 'ONBOARDING',
  item VARCHAR(150) NOT NULL,
  completed TINYINT(1) DEFAULT 0,
  completed_date DATE NULL,
  INDEX (organization_id), INDEX (engagement_id), INDEX (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Approval workflow -------------------------------------------------------
CREATE TABLE IF NOT EXISTS approval_workflows (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  transaction_type VARCHAR(40) NOT NULL,
  conditions_json JSON NULL,
  active TINYINT(1) DEFAULT 1,
  INDEX (organization_id), INDEX (transaction_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS approval_workflow_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  workflow_id INT NOT NULL,
  step_order INT DEFAULT 1,
  name VARCHAR(80) NOT NULL,
  approver_role VARCHAR(80) NULL,
  INDEX (workflow_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS approval_instances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  workflow_id INT NULL,
  transaction_type VARCHAR(40) NOT NULL,
  entity VARCHAR(60) NOT NULL,
  entity_id INT NOT NULL,
  amount DECIMAL(14,2) NULL,
  current_step INT DEFAULT 1,
  status VARCHAR(20) DEFAULT 'PENDING',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (organization_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS approval_actions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  instance_id INT NOT NULL,
  step_order INT DEFAULT 1,
  action VARCHAR(20) NOT NULL,
  actor_user_id INT NULL,
  remarks TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (instance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Bank export runs / report templates ------------------------------------
CREATE TABLE IF NOT EXISTS bank_export_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id INT NOT NULL,
  payroll_run_id INT NOT NULL,
  template_id INT NOT NULL,
  template_version INT NOT NULL,
  file_name VARCHAR(160) NOT NULL,
  file_hash VARCHAR(64) NOT NULL,
  row_count INT DEFAULT 0,
  total_amount DECIMAL(16,2) DEFAULT 0,
  object_key VARCHAR(255) NULL,
  filters_json JSON NULL,
  generated_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL UNIQUE,
  organization_id INT NULL,
  name VARCHAR(120) NOT NULL,
  dataset VARCHAR(60) NOT NULL,
  config_json JSON NOT NULL,
  created_by INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS interviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  scheduled_date DATE NULL,
  interviewer VARCHAR(150) NULL,
  result VARCHAR(20) NULL,
  remarks TEXT NULL,
  INDEX (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Benefits & beneficiaries ------------------------------------------------
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

-- --- Telegram notification links ---------------------------------------------
CREATE TABLE IF NOT EXISTS telegram_links (
  user_id INT PRIMARY KEY,
  chat_id VARCHAR(40) NULL,
  link_code VARCHAR(40) NULL,
  INDEX (link_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- In-app notifications ----------------------------------------------------
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

-- --- Asset lifecycle history -------------------------------------------------
CREATE TABLE IF NOT EXISTS asset_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT NOT NULL,
  event_type VARCHAR(20) NOT NULL,
  person_id INT NULL,
  item_condition VARCHAR(50) NULL,
  remarks TEXT NULL,
  event_date DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

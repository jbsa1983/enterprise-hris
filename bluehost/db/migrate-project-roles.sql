-- Project-scoped access: new permission scopes, the Project Manager / Project HR roles,
-- and a project_access table linking a user to the specific project(s) they may work on.
-- Idempotent.

-- 1. Assignment table.
CREATE TABLE IF NOT EXISTS project_access (
  id INT AUTO_INCREMENT PRIMARY KEY,
  organization_id INT NOT NULL,
  project_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_project_user (project_id, user_id),
  INDEX (organization_id), INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. New permission scopes (code is unique → safe to re-run).
INSERT INTO permissions (code, description) VALUES
  ('project.view',    'View projects & budgets'),
  ('project.manage',  'Edit projects & budgets'),
  ('project.worker',  'Manage project workers'),
  ('project.payroll', 'Run project payroll & payslips')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- 3. Ready-made roles (name is unique → safe to re-run).
INSERT INTO roles (name, description, is_system) VALUES
  ('Project Manager', 'Manages assigned projects: budget, workers and project payroll', 0),
  ('Project HR',      'Manages project workers and runs project payroll for assigned projects', 0)
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- 4. Grant each role its scopes (INSERT IGNORE skips ones already linked).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE r.name = 'Project Manager'
   AND p.code IN ('organization.view','project.view','project.manage','project.worker','project.payroll');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE r.name = 'Project HR'
   AND p.code IN ('organization.view','project.view','project.worker','project.payroll');

-- 5. Keep existing roles working: anyone who could edit employees gets full project
--    access; anyone who could view employees keeps project viewing (Projects was gated
--    on employee.view before this change). INSERT IGNORE = safe to re-run.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id
  FROM role_permissions rp
  JOIN permissions ep ON ep.id = rp.permission_id AND ep.code = 'employee.edit'
  JOIN permissions np ON np.code IN ('project.view','project.manage','project.worker','project.payroll');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id
  FROM role_permissions rp
  JOIN permissions ep ON ep.id = rp.permission_id AND ep.code = 'employee.view'
  JOIN permissions np ON np.code = 'project.view';

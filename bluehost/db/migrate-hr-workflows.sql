-- Complete Service Desk, Performance Reviews, and Approval workflow support.
-- Safe to run more than once on an existing GEEK HRIS database.
SET @db := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='service_tickets' AND column_name='resolution')=0,
 'ALTER TABLE service_tickets ADD COLUMN resolution TEXT NULL, ADD COLUMN created_by_user_id INT NULL, ADD COLUMN assigned_user_id INT NULL, ADD COLUMN updated_at DATETIME NULL, ADD COLUMN resolved_at DATETIME NULL, ADD COLUMN closed_at DATETIME NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS service_ticket_comments (
 id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, user_id INT NULL,
 body TEXT NOT NULL, is_internal TINYINT(1) DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX (ticket_id), INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='performance_reviews' AND column_name='employee_comments')=0,
 'ALTER TABLE performance_reviews ADD COLUMN employee_comments TEXT NULL, ADD COLUMN supervisor_comments TEXT NULL, ADD COLUMN hr_comments TEXT NULL, ADD COLUMN submitted_at DATETIME NULL, ADD COLUMN approved_by_user_id INT NULL, ADD COLUMN approved_at DATETIME NULL, ADD COLUMN acknowledged_at DATETIME NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='approval_instances' AND column_name='requested_by_user_id')=0,
 'ALTER TABLE approval_instances ADD COLUMN requested_by_user_id INT NULL, ADD COLUMN completed_at DATETIME NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT IGNORE INTO permissions (code, description) VALUES
 ('service_desk.view','View service desk tickets'),
 ('service_desk.manage','Assign and resolve service desk tickets'),
 ('performance.view','View performance reviews'),
 ('performance.manage','Create, score and submit performance reviews'),
 ('approval.view','View approval instances'),
 ('approval.act','Approve or reject assigned workflow steps'),
 ('approval.manage','Configure workflows and raise approval requests');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.name IN ('Super Admin','Enterprise Admin','Company Admin','HR Director','HR Manager')
  AND p.code IN ('service_desk.view','service_desk.manage','performance.view','performance.manage','approval.view','approval.act','approval.manage');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.name='HR Staff' AND p.code IN ('service_desk.view','service_desk.manage','performance.view','approval.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.name IN ('Department Head','Supervisor') AND p.code IN ('performance.view','performance.manage','approval.view','approval.act');

-- One default HR approval workflow per organization, unless already configured.
INSERT INTO approval_workflows (organization_id, name, transaction_type, active)
SELECT o.id, 'Performance Review Approval', 'PERFORMANCE_REVIEW', 1
FROM organizations o
WHERE NOT EXISTS (SELECT 1 FROM approval_workflows w WHERE w.organization_id=o.id AND w.transaction_type='PERFORMANCE_REVIEW');
INSERT INTO approval_workflow_steps (workflow_id, step_order, name, approver_role)
SELECT w.id, 1, 'HR Approval', 'HR Manager'
FROM approval_workflows w
WHERE w.transaction_type='PERFORMANCE_REVIEW'
  AND NOT EXISTS (SELECT 1 FROM approval_workflow_steps s WHERE s.workflow_id=w.id);

-- Project payroll: deduct each worker's cash advances / loans in the run, and let a
-- run's loan deductions be tracked & reversed. Adds project_pay_lines.loan_deduction
-- and loan_transactions.project_pay_run_id. Idempotent (guarded via information_schema).
SET @db := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'project_pay_lines' AND column_name = 'loan_deduction') = 0,
  'ALTER TABLE project_pay_lines ADD COLUMN loan_deduction DECIMAL(12,2) DEFAULT 0', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'loan_transactions' AND column_name = 'project_pay_run_id') = 0,
  'ALTER TABLE loan_transactions ADD COLUMN project_pay_run_id INT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

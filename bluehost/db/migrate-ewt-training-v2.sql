-- Per-consultant EWT rate + HR approval for self-added training points.
-- Idempotent (checks information_schema first).

SET @db := DATABASE();

-- Consultant Expanded Withholding Tax rate (e.g. 5.00 or 10.00). NULL = default 10%.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'engagements' AND column_name = 'ewt_rate') = 0,
  'ALTER TABLE engagements ADD COLUMN ewt_rate DECIMAL(5,2) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Whether a training's points are counted. HR-assigned completions are verified
-- automatically (1); employee self-added credentials start unverified (0) and
-- need HR approval before the points count.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'training_assignments' AND column_name = 'verified') = 0,
  'ALTER TABLE training_assignments ADD COLUMN verified TINYINT(1) DEFAULT 1', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

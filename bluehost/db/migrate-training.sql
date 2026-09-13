-- Training: due dates (timeline) + employee certificate submission.
-- Safe to run more than once (guards check information_schema first).

SET @db := DATABASE();

-- due_date (HR sets the deadline for the assigned training)
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'training_assignments' AND column_name = 'due_date') = 0,
  'ALTER TABLE training_assignments ADD COLUMN due_date DATE NULL AFTER status', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- certificate uploaded by the employee on completion
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'training_assignments' AND column_name = 'certificate_object_key') = 0,
  'ALTER TABLE training_assignments ADD COLUMN certificate_object_key VARCHAR(255) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'training_assignments' AND column_name = 'certificate_filename') = 0,
  'ALTER TABLE training_assignments ADD COLUMN certificate_filename VARCHAR(255) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'training_assignments' AND column_name = 'completion_note') = 0,
  'ALTER TABLE training_assignments ADD COLUMN completion_note VARCHAR(255) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Points (course value) + employee self-added credentials
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'training_courses' AND column_name = 'points') = 0,
  'ALTER TABLE training_courses ADD COLUMN points DECIMAL(6,2) DEFAULT 0', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'training_assignments' AND column_name = 'points') = 0,
  'ALTER TABLE training_assignments ADD COLUMN points DECIMAL(6,2) DEFAULT 0', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'training_assignments' AND column_name = 'source') = 0,
  "ALTER TABLE training_assignments ADD COLUMN source VARCHAR(10) DEFAULT 'ASSIGNED'", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'training_assignments' AND column_name = 'self_title') = 0,
  'ALTER TABLE training_assignments ADD COLUMN self_title VARCHAR(150) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'training_assignments' AND column_name = 'self_provider') = 0,
  'ALTER TABLE training_assignments ADD COLUMN self_provider VARCHAR(120) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Allow self-added credentials to have no linked course.
ALTER TABLE training_assignments MODIFY course_id INT NULL;

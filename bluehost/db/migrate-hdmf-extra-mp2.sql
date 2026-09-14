-- Pag-IBIG (HDMF) voluntary additional contribution + MP2 savings, per employee.
-- Fixed monthly amounts set manually (not computed from salary). Idempotent.
SET @db := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'engagements' AND column_name = 'hdmf_extra') = 0,
  'ALTER TABLE engagements ADD COLUMN hdmf_extra DECIMAL(10,2) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'engagements' AND column_name = 'hdmf_mp2') = 0,
  'ALTER TABLE engagements ADD COLUMN hdmf_mp2 DECIMAL(10,2) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

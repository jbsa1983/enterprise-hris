-- Profile / ID photos. A person's photo reuses the existing people.profile_image_key
-- column; this adds the user avatar column. Files live under storage/photos and stream
-- through PHP. Idempotent (guarded via information_schema).
SET @db := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = @db AND table_name = 'users' AND column_name = 'avatar_key') = 0,
  'ALTER TABLE users ADD COLUMN avatar_key VARCHAR(160) NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

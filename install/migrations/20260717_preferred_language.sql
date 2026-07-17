SET @has_preferred_language = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'preferred_language'
);
SET @sql = IF(
  @has_preferred_language = 0,
  'ALTER TABLE users ADD COLUMN preferred_language CHAR(2) NULL AFTER ai_friend_policy',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

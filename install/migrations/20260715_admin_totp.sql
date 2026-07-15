SET @totp_secret_sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN totp_secret varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL AFTER is_disabled',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'totp_secret'
);
PREPARE totp_secret_stmt FROM @totp_secret_sql;
EXECUTE totp_secret_stmt;
DEALLOCATE PREPARE totp_secret_stmt;

SET @totp_enabled_sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN totp_enabled_at timestamp NULL DEFAULT NULL AFTER totp_secret',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'totp_enabled_at'
);
PREPARE totp_enabled_stmt FROM @totp_enabled_sql;
EXECUTE totp_enabled_stmt;
DEALLOCATE PREPARE totp_enabled_stmt;

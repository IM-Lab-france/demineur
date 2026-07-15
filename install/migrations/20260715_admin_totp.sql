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

SET @totp_resize_sql = (
  SELECT IF(
    COALESCE(MAX(character_maximum_length), 0) < 512,
    'ALTER TABLE users MODIFY COLUMN totp_secret varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'totp_secret'
);
PREPARE totp_resize_stmt FROM @totp_resize_sql;
EXECUTE totp_resize_stmt;
DEALLOCATE PREPARE totp_resize_stmt;

SET @totp_recovery_sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN totp_recovery_codes json DEFAULT NULL AFTER totp_enabled_at',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'totp_recovery_codes'
);
PREPARE totp_recovery_stmt FROM @totp_recovery_sql;
EXECUTE totp_recovery_stmt;
DEALLOCATE PREPARE totp_recovery_stmt;

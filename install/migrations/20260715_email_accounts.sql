SET @email_sql = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE users ADD COLUMN email varchar(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER is_admin',
  'SELECT 1') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='email');
PREPARE email_stmt FROM @email_sql; EXECUTE email_stmt; DEALLOCATE PREPARE email_stmt;

SET @verified_missing = (SELECT COUNT(*)=0 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='email_verified_at');
SET @verified_sql = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE users ADD COLUMN email_verified_at timestamp NULL DEFAULT NULL AFTER email',
  'SELECT 1') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='email_verified_at');
PREPARE verified_stmt FROM @verified_sql; EXECUTE verified_stmt; DEALLOCATE PREPARE verified_stmt;

UPDATE users SET email_verified_at=CURRENT_TIMESTAMP WHERE @verified_missing=1 AND email IS NOT NULL AND email_verified_at IS NULL;

SET @email_unique_sql = (SELECT IF(COUNT(*)=0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)',
  'SELECT 1') FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='users' AND index_name='uq_users_email');
PREPARE email_unique_stmt FROM @email_unique_sql; EXECUTE email_unique_stmt; DEALLOCATE PREPARE email_unique_stmt;

CREATE TABLE IF NOT EXISTS account_tokens (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL,
  user_id int NOT NULL,
  purpose enum('verify_email','reset_password') NOT NULL,
  expires_at timestamp NOT NULL,
  used_at timestamp NULL DEFAULT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_account_tokens_hash (token_hash),
  KEY idx_account_tokens_user_purpose (user_id,purpose), KEY idx_account_tokens_expiry (expires_at),
  CONSTRAINT fk_account_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_outbox (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  payload_encrypted text COLLATE utf8mb4_bin NOT NULL,
  attempts tinyint unsigned NOT NULL DEFAULT 0,
  next_attempt_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error varchar(191) DEFAULT NULL,
  sent_at timestamp NULL DEFAULT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_email_outbox_pending (sent_at,next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

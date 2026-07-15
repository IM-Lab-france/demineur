ALTER TABLE users
  ADD COLUMN IF NOT EXISTS totp_secret varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL AFTER is_disabled,
  ADD COLUMN IF NOT EXISTS totp_enabled_at timestamp NULL DEFAULT NULL AFTER totp_secret;

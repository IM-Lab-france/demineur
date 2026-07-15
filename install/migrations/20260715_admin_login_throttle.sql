CREATE TABLE IF NOT EXISTS admin_login_attempts (
  identifier_hash char(64) NOT NULL,
  attempts smallint unsigned NOT NULL DEFAULT 0,
  window_started_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  blocked_until timestamp NULL DEFAULT NULL,
  last_attempt_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (identifier_hash),
  KEY idx_admin_login_attempts_last (last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

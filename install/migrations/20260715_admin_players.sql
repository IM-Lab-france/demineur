ALTER TABLE users
  ADD COLUMN is_disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_ai,
  ADD INDEX idx_users_disabled (is_disabled);

CREATE TABLE IF NOT EXISTS friendships (
  user_low_id INT NOT NULL,
  user_high_id INT NOT NULL,
  requester_id INT NOT NULL,
  status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  message VARCHAR(300) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_at TIMESTAMP NULL,
  declined_at TIMESTAMP NULL,
  PRIMARY KEY (user_low_id, user_high_id),
  KEY idx_friendships_requester (requester_id),
  CONSTRAINT fk_friendships_low FOREIGN KEY (user_low_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_friendships_high FOREIGN KEY (user_high_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_friendships_requester FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_friendship_distinct CHECK (user_low_id < user_high_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_blocks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  blocker_id INT NOT NULL,
  blocked_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unblocked_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_blocks_active_blocker (blocker_id, unblocked_at),
  KEY idx_blocks_active_blocked (blocked_id, unblocked_at),
  CONSTRAINT fk_blocks_blocker FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_blocks_blocked FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_block_distinct CHECK (blocker_id <> blocked_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  actor_id INT DEFAULT NULL,
  type ENUM('friend_request','friend_accepted','friend_removed') NOT NULL,
  message VARCHAR(300) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_social_notifications_unread (user_id, read_at, created_at),
  CONSTRAINT fk_social_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_social_notifications_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='friend_requests_enabled') = 0,
  'ALTER TABLE users ADD COLUMN friend_requests_enabled TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='ai_friend_policy') = 0,
  'ALTER TABLE users ADD COLUMN ai_friend_policy ENUM(''manual'',''auto_accept'',''reject'') NOT NULL DEFAULT ''manual''',
  'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
  (SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='friendships' AND column_name='status') NOT LIKE '%declined%',
  'ALTER TABLE friendships MODIFY status ENUM(''pending'',''accepted'',''declined'') NOT NULL DEFAULT ''pending''',
  'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='friendships' AND column_name='declined_at') = 0,
  'ALTER TABLE friendships ADD COLUMN declined_at TIMESTAMP NULL AFTER accepted_at',
  'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

DELETE FROM user_blocks WHERE unblocked_at IS NOT NULL AND unblocked_at < CURRENT_TIMESTAMP - INTERVAL 90 DAY;

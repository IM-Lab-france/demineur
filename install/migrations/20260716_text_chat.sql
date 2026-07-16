CREATE TABLE IF NOT EXISTS chat_conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind ENUM('direct','game') NOT NULL,
  user_low_id INT DEFAULT NULL,
  user_high_id INT DEFAULT NULL,
  game_id VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_message_at TIMESTAMP NULL,
  closed_at TIMESTAMP NULL,
  purge_after TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_chat_direct (kind,user_low_id,user_high_id),
  UNIQUE KEY uq_chat_game (kind,game_id),
  KEY idx_chat_activity (last_message_at),
  CONSTRAINT fk_chat_low FOREIGN KEY (user_low_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_chat_high FOREIGN KEY (user_high_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_participants (
  conversation_id BIGINT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  last_read_message_id BIGINT UNSIGNED DEFAULT NULL,
  muted TINYINT(1) NOT NULL DEFAULT 0,
  can_write TINYINT(1) NOT NULL DEFAULT 1,
  hidden_at TIMESTAMP NULL,
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (conversation_id,user_id),
  KEY idx_chat_participant_user (user_id,hidden_at),
  CONSTRAINT fk_chat_participant_conversation FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_chat_participant_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  sender_id INT DEFAULT NULL,
  message_type ENUM('user','system') NOT NULL DEFAULT 'user',
  body VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_chat_messages_conversation (conversation_id,id),
  CONSTRAINT fk_chat_message_conversation FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_chat_message_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_reactions (
  message_id BIGINT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  reaction VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (message_id,user_id,reaction),
  CONSTRAINT fk_chat_reaction_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_chat_reaction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='chat_enabled') = 0,
  'ALTER TABLE users ADD COLUMN chat_enabled TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN chat_sound_enabled TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN chat_layout ENUM(''floating'',''docked'') NOT NULL DEFAULT ''floating'', ADD COLUMN chat_position JSON DEFAULT NULL',
  'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='sound_enabled') = 0,
  'ALTER TABLE users ADD COLUMN sound_enabled TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

DELETE FROM chat_conversations WHERE purge_after IS NOT NULL AND purge_after < CURRENT_TIMESTAMP;
DELETE FROM chat_conversations WHERE kind='direct' AND COALESCE(last_message_at,created_at) < CURRENT_TIMESTAMP - INTERVAL 2 YEAR;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='elo_rating') = 0,
  'ALTER TABLE users ADD COLUMN elo_rating INT NOT NULL DEFAULT 1200 AFTER games_draw',
  'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='elo_games') = 0,
  'ALTER TABLE users ADD COLUMN elo_games INT UNSIGNED NOT NULL DEFAULT 0 AFTER elo_rating',
  'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='game_details' AND column_name='status') = 0,
  'ALTER TABLE game_details ADD COLUMN status ENUM(''finished'',''cancelled'',''forfeit'',''server_interrupted'') NOT NULL DEFAULT ''finished''',
  'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='game_details' AND column_name='flag_scores') = 0,
  'ALTER TABLE game_details ADD COLUMN flag_scores JSON NULL',
  'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='game_details' AND column_name='inviter_elo_change') = 0,
  'ALTER TABLE game_details ADD COLUMN inviter_elo_change SMALLINT NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='game_details' AND column_name='invitee_elo_change') = 0,
  'ALTER TABLE game_details ADD COLUMN invitee_elo_change SMALLINT NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

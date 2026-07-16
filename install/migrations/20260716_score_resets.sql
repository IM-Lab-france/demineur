SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='stats_reset_at') = 0,
  'ALTER TABLE users ADD COLUMN stats_reset_at TIMESTAMP NULL DEFAULT NULL AFTER elo_games',
  'SELECT 1'
);
PREPARE migration_stmt FROM @ddl; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

CREATE TABLE IF NOT EXISTS active_games (
  game_id VARCHAR(64) NOT NULL PRIMARY KEY,
  player1_id INT NOT NULL,
  player2_id INT NOT NULL,
  turn_user_id INT NOT NULL,
  state_json JSON NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_active_games_player1 (player1_id),
  INDEX idx_active_games_player2 (player2_id),
  CONSTRAINT fk_active_games_player1 FOREIGN KEY (player1_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_active_games_player2 FOREIGN KEY (player2_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

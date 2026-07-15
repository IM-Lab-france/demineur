ALTER TABLE users
  ADD CONSTRAINT chk_user_stats CHECK (games_played >= 0 AND games_won >= 0 AND games_draw >= 0 AND games_won <= games_played AND games_draw <= games_played);

ALTER TABLE invitations
  ADD INDEX idx_invitations_from (from_user_id),
  ADD INDEX idx_invitations_to (to_user_id),
  ADD CONSTRAINT fk_invitations_from FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_invitations_to FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE game_details
  ADD COLUMN status ENUM('finished','cancelled','forfeit','server_interrupted') NOT NULL DEFAULT 'finished',
  ADD UNIQUE INDEX uq_game_details_game_id (game_id),
  ADD INDEX idx_game_details_winner (winner_id),
  ADD INDEX idx_game_details_date (game_date),
  ADD CONSTRAINT fk_game_details_inviter FOREIGN KEY (inviter_id) REFERENCES users(id),
  ADD CONSTRAINT fk_game_details_invitee FOREIGN KEY (invitee_id) REFERENCES users(id),
  ADD CONSTRAINT fk_game_details_winner FOREIGN KEY (winner_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE game_moves
  ADD INDEX idx_game_moves_game_id (game_id),
  ADD INDEX idx_game_moves_created_at (created_at);

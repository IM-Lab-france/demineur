CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_active` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `games_played` int DEFAULT '0',
  `games_won` int DEFAULT '0',
  `is_ai` tinyint(1) DEFAULT '0',
  `is_disabled` tinyint(1) NOT NULL DEFAULT '0',
  `games_draw` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username_UNIQUE` (`username`),
  CONSTRAINT `chk_user_stats` CHECK (`games_played` >= 0 AND `games_won` >= 0 AND `games_draw` >= 0 AND `games_won` <= `games_played` AND `games_draw` <= `games_played`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invitations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `from_user_id` int NOT NULL,
  `to_user_id` int NOT NULL,
  `status` enum('pending','accepted','rejected') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `game_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_invitations_from` (`from_user_id`),
  KEY `idx_invitations_to` (`to_user_id`),
  CONSTRAINT `fk_invitations_from` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invitations_to` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `game_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `game_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `inviter_id` int NOT NULL,
  `invitee_id` int NOT NULL,
  `winner_id` int DEFAULT NULL,
  `game_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `moves` int NOT NULL,
  `explosion_area` json NOT NULL,
  `status` enum('finished','cancelled','forfeit','server_interrupted') NOT NULL DEFAULT 'finished',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_game_details_game_id` (`game_id`),
  KEY `idx_game_details_winner` (`winner_id`),
  KEY `idx_game_details_date` (`game_date`),
  CONSTRAINT `fk_game_details_inviter` FOREIGN KEY (`inviter_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_game_details_invitee` FOREIGN KEY (`invitee_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_game_details_winner` FOREIGN KEY (`winner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `game_moves` (
  `id` int NOT NULL AUTO_INCREMENT,
  `game_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `x` int NOT NULL,
  `y` int NOT NULL,
  `explosion_area` json NOT NULL,
  `result` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_game_moves_game_id` (`game_id`),
  KEY `idx_game_moves_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

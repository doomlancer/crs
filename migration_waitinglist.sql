-- Migration: Wartelisten-Tabelle anlegen
CREATE TABLE IF NOT EXISTS `waitinglist` (
  `id`                   INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`              INT NOT NULL,
  `event_id`             INT NOT NULL,
  `status`               ENUM('wartend','benachrichtigt','abgelaufen','gebucht') NOT NULL DEFAULT 'wartend',
  `benachrichtigt_token` VARCHAR(64) UNIQUE NULL,
  `token_expires`        DATETIME NULL,
  `erstellt_am`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_wl` (`user_id`, `event_id`),
  FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
  INDEX `idx_event_status` (`event_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

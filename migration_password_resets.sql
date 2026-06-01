-- Migration: Passwort-Reset-Tabelle anlegen
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `email`       VARCHAR(255) NOT NULL,
  `token`       VARCHAR(64)  NOT NULL UNIQUE,
  `expires_at`  DATETIME     NOT NULL,
  `used`        TINYINT(1)   NOT NULL DEFAULT 0,
  `erstellt_am` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_token` (`token`),
  INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

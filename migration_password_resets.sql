-- Migration: Passwort-Reset-Tabelle anlegen (MySQL 5.5 / utf8mb4 kompatibel)
-- Index-Präfix 191 Zeichen = 764 Bytes (unter dem 767-Byte-Limit von MySQL 5.5)

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `email`       VARCHAR(255) NOT NULL,
  `token`       VARCHAR(64)  NOT NULL,
  `expires_at`  DATETIME     NOT NULL,
  `used`        TINYINT(1)   NOT NULL DEFAULT 0,
  `erstellt_am` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_token` (`token`),
  INDEX `idx_email` (`email`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

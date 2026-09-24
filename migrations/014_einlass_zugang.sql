-- Migration 014: Einlass-Zugang (schlanke Rolle für Einlass-Helfer ohne volles Konto)
-- Je Helfer/Gerät ein eigener, kurzer Zugangscode statt E-Mail+Passwort.
-- Codes sind an genau ein Event gebunden; Check-ins über diesen Zugang
-- werden separat protokolliert (eingecheckt_von_einlass_id), damit
-- nachvollziehbar bleibt, welches Gerät/welcher Helfer eingecheckt hat.

CREATE TABLE IF NOT EXISTS `einlass_zugaenge` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `event_id` INT NOT NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `aktiv` TINYINT(1) NOT NULL DEFAULT 1,
  `erstellt_von` INT DEFAULT NULL,
  `erstellt_am` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `zuletzt_benutzt_am` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_einlass_event` (`event_id`),
  CONSTRAINT `fk_einlass_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_einlass_erstellt_von` FOREIGN KEY (`erstellt_von`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `reservations` ADD COLUMN `eingecheckt_von_einlass_id` INT DEFAULT NULL;
ALTER TABLE `reservations` ADD CONSTRAINT `fk_res_einlass`
  FOREIGN KEY (`eingecheckt_von_einlass_id`) REFERENCES `einlass_zugaenge` (`id`) ON DELETE SET NULL;

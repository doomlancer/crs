-- Karnevals-Reservierungssystem Datenbankschema
-- Kompatibel mit MariaDB 5.5.68+

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Datenbank erstellen (falls nicht vorhanden)
CREATE DATABASE IF NOT EXISTS `karneval_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `karneval_db`;

-- =====================
-- Tabelle: users
-- =====================
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `vorname` VARCHAR(100) NOT NULL,
  `nachname` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `passwort` VARCHAR(255) NOT NULL,
  `zahlungsart` ENUM('bar','ueberweisung','paypal') NOT NULL,
  `adresse` VARCHAR(255) DEFAULT NULL,
  `rolle` ENUM('user','kassierer','admin') NOT NULL DEFAULT 'user',
  `aktiv` TINYINT(1) NOT NULL DEFAULT 1,
  `login_versuche` INT NOT NULL DEFAULT 0,
  `gesperrt_bis` DATETIME DEFAULT NULL,
  `erstellt_am` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `geaendert_am` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================
-- Tabelle: events
-- =====================
CREATE TABLE IF NOT EXISTS `events` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `datum` DATE NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `beschreibung` TEXT DEFAULT NULL,
  `max_gaeste` INT NOT NULL,
  `status` ENUM('planung','aktiv','abgerechnet') NOT NULL DEFAULT 'aktiv',
  `erstellt_am` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================
-- Tabelle: tables
-- =====================
CREATE TABLE IF NOT EXISTS `tables` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `event_id` INT NOT NULL,
  `tischnummer` INT NOT NULL,
  `max_plaetze` INT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_tisch` (`event_id`, `tischnummer`),
  CONSTRAINT `fk_tables_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================
-- Tabelle: seats
-- =====================
CREATE TABLE IF NOT EXISTS `seats` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `table_id` INT NOT NULL,
  `sitzplatznummer` INT NOT NULL,
  `status` ENUM('verfuegbar','reserviert','besetzt') NOT NULL DEFAULT 'verfuegbar',
  PRIMARY KEY (`id`),
  UNIQUE KEY `table_sitz` (`table_id`, `sitzplatznummer`),
  CONSTRAINT `fk_seats_table` FOREIGN KEY (`table_id`) REFERENCES `tables` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================
-- Tabelle: reservations
-- =====================
CREATE TABLE IF NOT EXISTS `reservations` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `event_id` INT NOT NULL,
  `seat_id` INT NOT NULL,
  `buchungsnummer` VARCHAR(20) NOT NULL,
  `status` ENUM('geplant','eingecheckt','abgerechnet') NOT NULL DEFAULT 'geplant',
  `preis` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `erstellt_am` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `buchungsnummer` (`buchungsnummer`),
  UNIQUE KEY `seat_unique` (`seat_id`),
  CONSTRAINT `fk_res_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_res_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`),
  CONSTRAINT `fk_res_seat` FOREIGN KEY (`seat_id`) REFERENCES `seats` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================
-- Tabelle: payments
-- =====================
CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `reservation_id` INT NOT NULL,
  `zahlungsart` ENUM('bar','ueberweisung','paypal') NOT NULL,
  `status` ENUM('offen','bezahlt','storniert') NOT NULL DEFAULT 'offen',
  `betrag` DECIMAL(10,2) NOT NULL,
  `erstellt_am` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_pay_res` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================
-- Tabelle: audit_log
-- =====================
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT DEFAULT NULL,
  `aktion` VARCHAR(255) NOT NULL,
  `tabelle` VARCHAR(100) NOT NULL,
  `datensatz_id` INT DEFAULT NULL,
  `aenderung` TEXT DEFAULT NULL,
  `ip_adresse` VARCHAR(45) DEFAULT NULL,
  `zeitstempel` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================
-- Standard-Admin anlegen (Passwort: Admin1234!)
-- bcrypt hash für 'Admin1234!'
-- =====================
INSERT INTO `users` (`vorname`, `nachname`, `email`, `passwort`, `zahlungsart`, `rolle`, `aktiv`) VALUES
('System', 'Administrator', 'admin@karneval.local', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'bar', 'admin', 1);

-- Beispiel-Event
INSERT INTO `events` (`datum`, `name`, `beschreibung`, `max_gaeste`, `status`) VALUES
('2026-02-14', 'Karneval Eröffnungsfeier 2026', 'Die große Eröffnungsfeier unserer Karnevalssaison 2026. Freut euch auf Musik, Tanz und gute Laune!', 200, 'aktiv'),
('2026-02-21', 'Rosenmontagsparty 2026', 'Die traditionelle Rosenmontagsparty mit Live-Band und Kostümwettbewerb.', 300, 'aktiv');

-- Tische für Event 1
INSERT INTO `tables` (`event_id`, `tischnummer`, `max_plaetze`) VALUES
(1, 1, 4), (1, 2, 4), (1, 3, 6), (1, 4, 6), (1, 5, 4),
(1, 6, 4), (1, 7, 6), (1, 8, 6), (1, 9, 4), (1, 10, 8);

-- Tische für Event 2
INSERT INTO `tables` (`event_id`, `tischnummer`, `max_plaetze`) VALUES
(2, 1, 6), (2, 2, 6), (2, 3, 8), (2, 4, 8), (2, 5, 6),
(2, 6, 6), (2, 7, 8), (2, 8, 8), (2, 9, 6), (2, 10, 6);

-- Sitzplätze für Event 1 Tische (automatisch)
-- Tisch 1 (id=1, 4 Plätze)
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (1,1),(1,2),(1,3),(1,4);
-- Tisch 2 (id=2, 4 Plätze)
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (2,1),(2,2),(2,3),(2,4);
-- Tisch 3 (id=3, 6 Plätze)
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (3,1),(3,2),(3,3),(3,4),(3,5),(3,6);
-- Tisch 4 (id=4, 6 Plätze)
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (4,1),(4,2),(4,3),(4,4),(4,5),(4,6);
-- Tisch 5 (id=5, 4 Plätze)
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (5,1),(5,2),(5,3),(5,4);
-- Tisch 6 (id=6, 4 Plätze)
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (6,1),(6,2),(6,3),(6,4);
-- Tisch 7 (id=7, 6 Plätze)
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (7,1),(7,2),(7,3),(7,4),(7,5),(7,6);
-- Tisch 8 (id=8, 6 Plätze)
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (8,1),(8,2),(8,3),(8,4),(8,5),(8,6);
-- Tisch 9 (id=9, 4 Plätze)
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (9,1),(9,2),(9,3),(9,4);
-- Tisch 10 (id=10, 8 Plätze)
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (10,1),(10,2),(10,3),(10,4),(10,5),(10,6),(10,7),(10,8);

-- Sitzplätze für Event 2 Tische
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (11,1),(11,2),(11,3),(11,4),(11,5),(11,6);
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (12,1),(12,2),(12,3),(12,4),(12,5),(12,6);
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (13,1),(13,2),(13,3),(13,4),(13,5),(13,6),(13,7),(13,8);
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (14,1),(14,2),(14,3),(14,4),(14,5),(14,6),(14,7),(14,8);
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (15,1),(15,2),(15,3),(15,4),(15,5),(15,6);
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (16,1),(16,2),(16,3),(16,4),(16,5),(16,6);
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (17,1),(17,2),(17,3),(17,4),(17,5),(17,6),(17,7),(17,8);
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (18,1),(18,2),(18,3),(18,4),(18,5),(18,6),(18,7),(18,8);
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (19,1),(19,2),(19,3),(19,4),(19,5),(19,6);
INSERT INTO `seats` (`table_id`, `sitzplatznummer`) VALUES (20,1),(20,2),(20,3),(20,4),(20,5),(20,6);

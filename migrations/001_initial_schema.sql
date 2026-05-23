-- Migration 001: Initiales Schema
-- Entspricht database.sql (Ausgangszustand)

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `crs` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `crs`;

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
  UNIQUE KEY `email` (`email`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `tables` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `event_id` INT NOT NULL,
  `tischnummer` INT NOT NULL,
  `max_plaetze` INT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_tisch` (`event_id`, `tischnummer`),
  CONSTRAINT `fk_tables_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seats` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `table_id` INT NOT NULL,
  `sitzplatznummer` INT NOT NULL,
  `status` ENUM('verfuegbar','reserviert','besetzt') NOT NULL DEFAULT 'verfuegbar',
  PRIMARY KEY (`id`),
  UNIQUE KEY `table_sitz` (`table_id`, `sitzplatznummer`),
  CONSTRAINT `fk_seats_table` FOREIGN KEY (`table_id`) REFERENCES `tables` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

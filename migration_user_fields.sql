-- Migration: Zusätzliche Benutzerfelder hinzufügen
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `telefon`       VARCHAR(30)  NULL    AFTER `adresse`,
  ADD COLUMN IF NOT EXISTS `geburtsdatum`  DATE         NULL    AFTER `telefon`,
  ADD COLUMN IF NOT EXISTS `agb_akzeptiert` TINYINT(1) NOT NULL DEFAULT 0 AFTER `geburtsdatum`;

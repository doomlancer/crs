-- Migration: Preis-Feld zu events-Tabelle hinzufügen
-- Nur ausführen wenn die Spalte noch nicht existiert (Upgrade von Erstinstallation)

ALTER TABLE `events`
    ADD COLUMN IF NOT EXISTS `preis` DECIMAL(10,2) NOT NULL DEFAULT 15.00
    AFTER `max_gaeste`;

-- Bestehende Events auf Standard-Preis setzen (falls NULL)
UPDATE `events` SET `preis` = 15.00 WHERE `preis` IS NULL OR `preis` = 0;

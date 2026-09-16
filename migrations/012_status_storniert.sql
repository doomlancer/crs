-- Migration 012: Stornierung von Abrechnung trennen
--
-- `reservations.status` kannte bisher nur 'geplant', 'eingecheckt' und
-- 'abgerechnet'. Storniert wurde deshalb ebenfalls als 'abgerechnet'
-- geschrieben – derselbe Wert, den die Abrechnung eines Events nach der
-- Veranstaltung setzt. Beide Vorgänge waren in der Datenbank nicht mehr
-- unterscheidbar, mit Folgen bis an den Einlass: Die Gästeliste zeigte
-- stornierte Buchungen als Gäste an und zählte sie mit, und beim Check-in
-- meldete das System "Ticket ist nicht gültig (Status: abgerechnet)" für
-- einen Platz, der schlicht storniert war.
--
-- Wichtig: Die generierte Spalte seat_aktiv aus Migration 009 muss den neuen
-- Endzustand mit abdecken. Ohne diesen Schritt würde ein storniertes Ticket
-- den Sitzplatz wieder dauerhaft blockieren – genau der Fehler, den 009
-- behoben hat.
--
-- Bestandsdaten lassen sich nicht nachträglich aufteilen: Bei bereits
-- vorhandenen 'abgerechnet'-Zeilen ist nicht mehr feststellbar, ob sie
-- storniert oder abgerechnet wurden. Sie bleiben wie sie sind; ab hier ist
-- die Unterscheidung sauber.

-- Wie bei 009 sind alle Schritte wiederholbar formuliert, damit ein Abbruch
-- mittendrin den nächsten Containerstart nicht blockiert.

ALTER TABLE `reservations`
  MODIFY COLUMN `status` ENUM('geplant','eingecheckt','abgerechnet','storniert')
  NOT NULL DEFAULT 'geplant';

ALTER TABLE `reservations` DROP INDEX  IF EXISTS `uq_seat_aktiv`;
ALTER TABLE `reservations` DROP COLUMN IF EXISTS `seat_aktiv`;

ALTER TABLE `reservations`
  ADD COLUMN IF NOT EXISTS `seat_aktiv` INT
    AS (IF(`status` IN ('abgerechnet','storniert'), NULL, `seat_id`)) STORED;

ALTER TABLE `reservations`
  ADD UNIQUE KEY IF NOT EXISTS `uq_seat_aktiv` (`seat_aktiv`);

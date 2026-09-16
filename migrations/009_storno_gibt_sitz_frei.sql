-- Migration 009: Stornierte Sitzplätze wieder buchbar machen
--
-- Problem: `UNIQUE KEY seat_unique (seat_id)` erlaubte je Sitzplatz nur eine
-- einzige Reservierungszeile – überhaupt jemals. Storniert wird aber an
-- mehreren Stellen per Statuswechsel auf 'abgerechnet' (die Zeile bleibt also
-- bestehen), während `seats.status` gleichzeitig auf 'verfuegbar' gesetzt wird.
-- Ergebnis: Der Platz wurde überall als frei angezeigt, ein neuer INSERT lief
-- aber in einen Duplicate-Key-Fehler. Der Sitz war dauerhaft unverkäuflich,
-- und jeder angemeldete Nutzer konnte das durch Buchen + Stornieren gezielt
-- für den gesamten Saal auslösen.
--
-- Lösung: Der eindeutige Schlüssel gilt nur noch für AKTIVE Reservierungen.
-- Die generierte Spalte ist bei stornierten Zeilen NULL, und NULL-Werte
-- schließen sich in einem UNIQUE-Index gegenseitig nicht aus. Damit bleibt der
-- Schutz gegen Doppelbuchung vollständig erhalten, die Historie bleibt
-- erhalten, und stornierte Plätze werden wieder verkäuflich.

-- Alle Schritte sind mit IF (NOT) EXISTS formuliert und damit wiederholbar.
-- Grund: DDL ist in MariaDB nicht transaktionssicher. Bricht eine Datei nach
-- der ersten Anweisung ab, bleibt deren Wirkung bestehen, die Migration gilt
-- aber als nicht ausgeführt – der nächste Start scheitert dann an genau dieser
-- ersten Anweisung, und der Container kommt nicht mehr hoch.

ALTER TABLE `reservations`
  ADD COLUMN IF NOT EXISTS `seat_aktiv` INT
    AS (IF(`status` = 'abgerechnet', NULL, `seat_id`)) STORED;

-- seat_unique erfüllt zwei Aufgaben: Er verhindert Doppelbuchungen UND dient
-- dem Fremdschlüssel fk_res_seat als Index auf seat_id. InnoDB verweigert das
-- Löschen, solange kein anderer Index diese Spalte abdeckt ("Cannot drop index
-- 'seat_unique': needed in a foreign key constraint"). Deshalb zuerst einen
-- einfachen Index anlegen, der die Fremdschlüssel-Rolle übernimmt.
ALTER TABLE `reservations`
  ADD INDEX IF NOT EXISTS `idx_res_seat` (`seat_id`);

ALTER TABLE `reservations`
  DROP INDEX IF EXISTS `seat_unique`;

ALTER TABLE `reservations`
  ADD UNIQUE KEY IF NOT EXISTS `uq_seat_aktiv` (`seat_aktiv`);

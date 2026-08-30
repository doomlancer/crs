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

ALTER TABLE `reservations`
  ADD COLUMN `seat_aktiv` INT
    AS (IF(`status` = 'abgerechnet', NULL, `seat_id`)) STORED;

ALTER TABLE `reservations`
  DROP INDEX `seat_unique`;

ALTER TABLE `reservations`
  ADD UNIQUE KEY `uq_seat_aktiv` (`seat_aktiv`);

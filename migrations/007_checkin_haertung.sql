-- Migration 007: Check-in-Härtung
-- Bisher existierte der Check-in-Zeitpunkt nur indirekt im Audit-Log.
-- Eigene Spalten machen Auswertung, Live-Feed und Nachvollziehbarkeit möglich.

ALTER TABLE `reservations`
  ADD COLUMN `eingecheckt_am`  DATETIME DEFAULT NULL,
  ADD COLUMN `eingecheckt_von` INT      DEFAULT NULL;

-- Index für den Live-Feed des Kassierer-Dashboards (Abfrage nach Zeitpunkt)
ALTER TABLE `reservations`
  ADD INDEX `idx_eingecheckt_am` (`eingecheckt_am`);

-- Index für die häufige Filterung nach Event + Status (Gästeliste, Check-in)
ALTER TABLE `reservations`
  ADD INDEX `idx_event_status` (`event_id`, `status`);

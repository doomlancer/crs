-- Migration 006: Freie-Ticket-Events (ohne Sitzplan)
-- Fügt event_typ zu events hinzu und macht seat_id in reservations nullable.

ALTER TABLE `events`
  ADD COLUMN `event_typ` ENUM('tischplan','freie_tickets') NOT NULL DEFAULT 'tischplan',
  MODIFY COLUMN `max_gaeste` INT NULL;

ALTER TABLE `reservations` MODIFY COLUMN `seat_id` INT NULL;

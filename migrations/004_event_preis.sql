-- Migration 004: Ticketpreis pro Event
ALTER TABLE events ADD COLUMN preis DECIMAL(10,2) NOT NULL DEFAULT 15.00;
UPDATE events SET preis = 15.00 WHERE preis IS NULL OR preis = 0;

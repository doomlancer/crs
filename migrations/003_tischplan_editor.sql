-- Migration 003: Tischplan-Editor (visuelles Positionieren)
ALTER TABLE events ADD COLUMN tischplan_bild VARCHAR(255) DEFAULT NULL;
ALTER TABLE `tables` ADD COLUMN pos_x DECIMAL(5,2) DEFAULT NULL;
ALTER TABLE `tables` ADD COLUMN pos_y DECIMAL(5,2) DEFAULT NULL;

-- Migration: Zusätzliche Benutzerfelder hinzufügen (MySQL 5.5 kompatibel)
-- Spalten werden nur hinzugefügt wenn sie noch nicht existieren (via information_schema)

DROP PROCEDURE IF EXISTS _krs_add_col;

DELIMITER //
CREATE PROCEDURE _krs_add_col(
    IN p_table VARCHAR(64),
    IN p_col   VARCHAR(64),
    IN p_def   TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_col
    ) THEN
        SET @_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
        PREPARE _stmt FROM @_sql;
        EXECUTE _stmt;
        DEALLOCATE PREPARE _stmt;
    END IF;
END //
DELIMITER ;

CALL _krs_add_col('users', 'telefon',       'VARCHAR(30)  NULL    AFTER `adresse`');
CALL _krs_add_col('users', 'geburtsdatum',  'DATE         NULL    AFTER `telefon`');
CALL _krs_add_col('users', 'agb_akzeptiert','TINYINT(1)   NOT NULL DEFAULT 0 AFTER `geburtsdatum`');

DROP PROCEDURE IF EXISTS _krs_add_col;

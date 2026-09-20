-- Migration 013: Admin-pflegbare Übersetzungen
--
-- lang/de.php und lang/en.php bleiben die Vorgabe und die Wahrheit für neue
-- Installationen. Diese Tabelle enthält NUR Abweichungen, die ein Admin über
-- die Oberfläche einträgt — deshalb eine eigene Tabelle statt der
-- vorhandenen `settings`-Tabelle: settings ist ein flacher Ein-Wert-Speicher
-- für einzelne App-weite Werte (Farben, Logo-Dateiname, …), hier braucht
-- jeder Schlüssel zwei Werte (DE und EN) in einer Zeile, dazu wer wann
-- geändert hat. Ein Schlüssel ohne Override liest weiterhin direkt aus der
-- Sprachdatei — leere/NULL-Spalten hier bedeuten "keine Überschreibung".
--
-- lang_key entspricht exakt den Schlüsseln aus lang/de.php und lang/en.php
-- (Format "bereich.zweck", z.B. "nav.events"). Es gibt bewusst KEINE
-- Fremdschlüsselprüfung gegen eine Schlüsseltabelle — die Schlüssel leben in
-- den PHP-Dateien, nicht in der Datenbank. Die Admin-Oberfläche zeigt daher
-- zusätzlich an, welche Overrides zu keinem bekannten Schlüssel mehr
-- gehören (z.B. nach einer Umbenennung im Code).

CREATE TABLE IF NOT EXISTS `translation_overrides` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `lang_key`      VARCHAR(191) NOT NULL,
  `de`            TEXT DEFAULT NULL,
  `en`            TEXT DEFAULT NULL,
  `geaendert_am`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `geaendert_von` INT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lang_key` (`lang_key`),
  CONSTRAINT `fk_translation_user` FOREIGN KEY (`geaendert_von`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration 011: Sitzungen bei Passwortwechsel entwerten
--
-- Ändert ein Nutzer sein Passwort – typischerweise gerade weil er eine
-- Kompromittierung vermutet –, blieben bisher alle anderen offenen Sitzungen
-- desselben Kontos gültig. Ein Angreifer mit erbeutetem Session-Cookie behielt
-- vollen Zugriff, solange er die Sitzung am Leben hielt. Der Passwortwechsel,
-- also die naheliegendste Gegenmaßnahme, lief damit ins Leere.
--
-- Der Zeitstempel wird beim Login in die Session geschrieben und bei jedem
-- Request gegen die Datenbank geprüft. Weicht er ab, wurde das Passwort
-- zwischenzeitlich geändert und die Sitzung wird beendet.

ALTER TABLE `users`
  ADD COLUMN `passwort_geaendert_am` DATETIME DEFAULT NULL;

-- Bestandskonten bekommen einen Startwert, damit bestehende Sitzungen nicht
-- schon durch das Einspielen dieser Migration abgemeldet werden.
UPDATE `users` SET `passwort_geaendert_am` = COALESCE(`erstellt_am`, NOW())
 WHERE `passwort_geaendert_am` IS NULL;

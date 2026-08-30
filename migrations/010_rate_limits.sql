-- Migration 010: Rate-Limiting für Login und Passwort-Reset
--
-- Bisher hing die Bremse ausschließlich am Benutzerkonto (users.login_versuche).
-- Das hatte zwei Lücken:
--   1. Ein Angreifer konnte ein fremdes Konto gezielt aussperren, indem er
--      fünfmal ein falsches Passwort schickte (Denial of Service).
--   2. Password-Spraying – ein Versuch je Konto über die gesamte Nutzerbasis –
--      lief ungebremst, weil der Zähler pro Konto nie anschlug.
-- Ebenso war "Passwort vergessen" völlig ungedrosselt: beliebig viele Mails an
-- ein fremdes Postfach, und jede Anfrage entwertete den zuvor verschickten Link.
--
-- Diese Tabelle zählt Versuche je Aktion und Schlüssel (IP-Adresse oder
-- E-Mail-Adresse) in einem Zeitfenster. Sie ersetzt den Kontozähler nicht,
-- sondern ergänzt ihn.

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `aktion`     VARCHAR(32)  NOT NULL,
  `schluessel` VARCHAR(190) NOT NULL,
  `zeitpunkt`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_aktion_schluessel` (`aktion`, `schluessel`, `zeitpunkt`),
  INDEX `idx_zeitpunkt` (`zeitpunkt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

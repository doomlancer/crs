-- Migration 015: Warteliste reparieren, PayPal stilllegen
--
-- Warteliste: Bisher wurde bei jeder Stornierung immer derselbe erste
-- Eintrag benachrichtigt und nie markiert – wer an zweiter Stelle stand,
-- erfuhr nie von einem freien Platz. benachrichtigt_am hält fest, wer schon
-- eine Nachricht bekommen hat; der nächste freie Platz geht an den nächsten.
--
-- PayPal wird nicht mehr angeboten. Konten mit PayPal als bevorzugter
-- Zahlungsart und noch offene PayPal-Zahlungen werden auf Überweisung
-- umgestellt, damit die Gäste einen gültigen Zahlungsweg sehen. Bezahlte und
-- stornierte PayPal-Zahlungen bleiben als Historie unverändert.
--
-- Alle Schritte sind wiederholbar formuliert.

ALTER TABLE `waitlist` ADD COLUMN IF NOT EXISTS `benachrichtigt_am` DATETIME DEFAULT NULL;

UPDATE `users`    SET `zahlungsart` = 'ueberweisung' WHERE `zahlungsart` = 'paypal';
UPDATE `payments` SET `zahlungsart` = 'ueberweisung' WHERE `zahlungsart` = 'paypal' AND `status` = 'offen';

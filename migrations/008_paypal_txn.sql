-- Migration 008: PayPal-Transaktionen protokollieren (Wiedereinspiel-Schutz)
--
-- Der IPN-Handler markierte Zahlungen bisher allein anhand der Buchungsnummern
-- aus dem Feld "custom", das im Browser des Käufers gesetzt wird. Eine echte,
-- aber wiederholt zugestellte oder abgeänderte Benachrichtigung konnte damit
-- beliebige weitere Buchungen quittieren.
--
-- Jede Transaktions-ID darf nur genau einmal verbucht werden. Der UNIQUE-Key
-- erzwingt das auf Datenbankebene, also auch bei zwei gleichzeitig
-- eintreffenden Benachrichtigungen.

CREATE TABLE IF NOT EXISTS `paypal_transactions` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `txn_id`        VARCHAR(64)    NOT NULL,
  `mc_gross`      DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  `payer_email`   VARCHAR(255)   DEFAULT NULL,
  `buchungen`     TEXT           DEFAULT NULL,
  `verarbeitet_am` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_txn_id` (`txn_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

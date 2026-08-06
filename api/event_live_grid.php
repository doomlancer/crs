<?php
/**
 * API: Live-Ampel-Zustand eines Events (Voll-Snapshot je Poll).
 *
 * Bewusst kein Delta-Feed wie api/checkin_feed.php: reservations hat keinen
 * Zeitstempel für Stornierungen (nur erstellt_am/eingecheckt_am), ein
 * Delta-Feed könnte Stornierungen also nicht zuverlässig erfassen. Die
 * Datenmenge ist klein genug (üblicherweise < 200 Plätze), um bei jedem Poll
 * den vollen Zustand zu senden – der Client gleicht clientseitig ab.
 *
 * GET: event_id
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('kassierer', 'admin');

$eventId = (int)($_GET['event_id'] ?? 0);
if ($eventId < 1) {
    jsonResponse(['success' => false, 'message' => 'Kein Event angegeben', 'data' => null], 400);
}

$grid = getEventLiveGrid($eventId);
if ($grid === null) {
    jsonResponse(['success' => false, 'message' => 'Event nicht gefunden', 'data' => null], 404);
}

jsonResponse(['success' => true, 'message' => '', 'data' => $grid]);

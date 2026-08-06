<?php
/**
 * API: Ticket-Suche für Kassierer/Admin (Name, E-Mail oder Buchungsnummer).
 * Wird vom Suchfeld auf pages/event_live_dashboard.php genutzt, um ein
 * verlorenes Ticket per Namen zu finden.
 *
 * GET: q (min. 2 Zeichen), event_id (optional)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('kassierer', 'admin');

$query   = trim($_GET['q'] ?? '');
$eventId = isset($_GET['event_id']) && (int)$_GET['event_id'] > 0 ? (int)$_GET['event_id'] : null;

$results = findReservationsForLookup($query, $eventId, 20);

jsonResponse(['success' => true, 'message' => '', 'data' => ['results' => $results]]);

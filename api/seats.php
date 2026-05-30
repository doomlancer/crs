<?php
/**
 * API: Verfügbare Sitzplätze für ein Event laden (für Admin-Formulare)
 * GET: event_id, [status=verfuegbar]
 * Nur für Kassierer/Admin zugänglich
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!hasRole('kassierer', 'admin')) {
    jsonResponse(['error' => 'Nicht autorisiert'], 401);
}

$eventId      = (int)($_GET['event_id'] ?? 0);
$filterStatus = $_GET['status'] ?? 'verfuegbar';

if (!$eventId) {
    jsonResponse(['error' => 'event_id fehlt'], 400);
}

// Nur gültige Status-Werte erlauben
if (!in_array($filterStatus, ['verfuegbar', 'reserviert', 'besetzt'], true)) {
    $filterStatus = 'verfuegbar';
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare(
        'SELECT s.id, s.sitzplatznummer, s.status,
                t.tischnummer, t.id AS table_id
         FROM seats s
         JOIN tables t ON s.table_id = t.id
         WHERE t.event_id = ? AND s.status = ?
         ORDER BY t.tischnummer, s.sitzplatznummer'
    );
    $stmt->execute([$eventId, $filterStatus]);
    $seats = $stmt->fetchAll();

    jsonResponse([
        'event_id' => $eventId,
        'status'   => $filterStatus,
        'count'    => count($seats),
        'seats'    => $seats,
    ]);

} catch (PDOException $e) {
    error_log('api/seats.php Fehler: ' . $e->getMessage());
    jsonResponse(['error' => 'Datenbankfehler'], 500);
}

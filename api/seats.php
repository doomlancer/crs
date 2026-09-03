<?php
/**
 * API: Freie Sitzplätze eines Events auflisten.
 *
 * Wird vom Modal "Manuelle Reservierung" in pages/admin_events.php benutzt.
 * Die Datei fehlte bisher: das Sitzplatz-Auswahlfeld blieb dauerhaft auf
 * "Fehler beim Laden" stehen, und weil es ein Pflichtfeld ist, war die manuelle
 * Reservierung damit vollständig unbenutzbar – ausgerechnet der Handgriff, den
 * man am Veranstaltungsabend für einen Nachzügler braucht.
 *
 * GET: event_id, optional status (Standard: verfuegbar)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');

header('Content-Type: application/json; charset=utf-8');

$eventId = (int)($_GET['event_id'] ?? 0);
if ($eventId < 1) {
    echo json_encode(['seats' => [], 'message' => 'Kein Event angegeben']);
    exit;
}

// Nur bekannte Statuswerte zulassen – der Wert geht in die Abfrage ein.
$erlaubt = ['verfuegbar', 'reserviert', 'besetzt'];
$status  = in_array($_GET['status'] ?? '', $erlaubt, true) ? $_GET['status'] : 'verfuegbar';

$stmt = getDB()->prepare(
    'SELECT s.id, s.sitzplatznummer, t.tischnummer
     FROM seats s
     INNER JOIN `tables` t ON t.id = s.table_id
     WHERE t.event_id = ? AND s.status = ?
     ORDER BY t.tischnummer, s.sitzplatznummer'
);
$stmt->execute([$eventId, $status]);

$seats = array_map(static fn(array $r): array => [
    'id'              => (int)$r['id'],
    'tischnummer'     => (int)$r['tischnummer'],
    'sitzplatznummer' => (int)$r['sitzplatznummer'],
], $stmt->fetchAll());

echo json_encode(['seats' => $seats]);

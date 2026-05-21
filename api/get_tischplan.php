<?php
/**
 * API: Tischplan-Daten als JSON für AJAX-Polling
 * GET: event_id
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Nicht autorisiert', 'data' => null], 401);
}

$eventId = (int)($_GET['event_id'] ?? 0);
if (!$eventId) {
    jsonResponse(['success' => false, 'message' => 'Kein Event angegeben', 'data' => null], 400);
}

try {
    $pdo = getDB();
    $userId = (int)$_SESSION['user_id'];

    // Event prüfen
    $stmtEvent = $pdo->prepare('SELECT id, name, datum, status FROM events WHERE id = ?');
    $stmtEvent->execute([$eventId]);
    $event = $stmtEvent->fetch();

    if (!$event) {
        jsonResponse(['success' => false, 'message' => 'Event nicht gefunden', 'data' => null], 404);
    }

    // Tische und Sitze laden
    $stmt = $pdo->prepare(
        'SELECT t.id AS table_id, t.tischnummer, t.max_plaetze,
                s.id AS seat_id, s.sitzplatznummer, s.status AS seat_status
         FROM tables t
         LEFT JOIN seats s ON s.table_id = t.id
         WHERE t.event_id = ?
         ORDER BY t.tischnummer, s.sitzplatznummer'
    );
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll();

    // Meine Reservierungen
    $stmtMine = $pdo->prepare(
        'SELECT seat_id FROM reservations WHERE user_id = ? AND event_id = ? AND status != "abgerechnet"'
    );
    $stmtMine->execute([$userId, $eventId]);
    $meineSitze = array_column($stmtMine->fetchAll(), 'seat_id');

    // Daten strukturieren
    $tische = [];
    foreach ($rows as $row) {
        $tableId = $row['table_id'];
        if (!isset($tische[$tableId])) {
            $tische[$tableId] = [
                'id'          => $tableId,
                'tischnummer' => $row['tischnummer'],
                'max_plaetze' => $row['max_plaetze'],
                'sitze'       => [],
            ];
        }
        if ($row['seat_id']) {
            $isMein = in_array((int)$row['seat_id'], $meineSitze);
            $tische[$tableId]['sitze'][] = [
                'id'              => (int)$row['seat_id'],
                'sitzplatznummer' => $row['sitzplatznummer'],
                'status'          => $isMein ? 'mein_platz' : $row['seat_status'],
            ];
        }
    }

    // Statistik
    $allSitze = $rows;
    $gesamt   = count(array_filter($allSitze, fn($r) => $r['seat_id']));
    $belegt   = count(array_filter($allSitze, fn($r) => $r['seat_id'] && $r['seat_status'] !== 'verfuegbar'));

    jsonResponse([
        'success' => true,
        'message' => '',
        'data'    => [
            'event'    => $event,
            'tische'   => array_values($tische),
            'statistik' => [
                'gesamt'  => $gesamt,
                'belegt'  => $belegt,
                'frei'    => $gesamt - $belegt,
                'prozent' => $gesamt > 0 ? round($belegt / $gesamt * 100) : 0,
            ],
            'meine_sitze' => $meineSitze,
            'timestamp'   => time(),
        ],
    ]);

} catch (PDOException $e) {
    error_log('get_tischplan Fehler: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Datenbankfehler', 'data' => null], 500);
}

<?php
/**
 * API: Live-Feed der letzten Check-ins.
 *
 * Wird vom Kassierer-Dashboard im Sekundentakt abgefragt, um sofort ein
 * Popup „Vorname Nachname – Check-in" anzuzeigen, sobald am Einlass ein
 * Ticket gescannt wurde.
 *
 * GET: event_id (optional), since (ISO-Zeitstempel des letzten bekannten Eintrags)
 * Antwort: { success, data: { checkins: [...], counts: {...}, now: "..." } }
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('kassierer', 'admin');

$pdo     = getDB();
$eventId = isset($_GET['event_id']) && (int)$_GET['event_id'] > 0 ? (int)$_GET['event_id'] : null;

// "since": nur Check-ins nach diesem Zeitpunkt liefern.
$since = trim((string)($_GET['since'] ?? ''));
if ($since === '' || strtotime($since) === false) {
    // Erster Aufruf: nichts nachliefern, nur den aktuellen Stand melden
    $since = date('Y-m-d H:i:s');
    $initial = true;
} else {
    $since = date('Y-m-d H:i:s', strtotime($since));
    $initial = false;
}

$checkins = [];

if (!$initial) {
    $sql = "SELECT r.id, r.buchungsnummer, r.eingecheckt_am,
                   u.vorname, u.nachname,
                   e.name AS event_name,
                   t.tischnummer, s.sitzplatznummer,
                   p.status AS zahl_status, p.betrag
            FROM reservations r
            INNER JOIN users  u ON u.id = r.user_id
            INNER JOIN events e ON e.id = r.event_id
            LEFT  JOIN seats  s ON s.id = r.seat_id
            LEFT  JOIN tables t ON t.id = s.table_id
            LEFT  JOIN payments p ON p.reservation_id = r.id
            WHERE r.status = 'eingecheckt'
              AND r.eingecheckt_am IS NOT NULL
              AND r.eingecheckt_am > ?";
    $params = [$since];

    if ($eventId !== null) {
        $sql .= ' AND r.event_id = ?';
        $params[] = $eventId;
    }
    $sql .= ' ORDER BY r.eingecheckt_am ASC LIMIT 25';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $checkins[] = [
                'id'             => (int)$row['id'],
                'buchungsnummer' => $row['buchungsnummer'],
                'vorname'        => $row['vorname'],
                'nachname'       => $row['nachname'],
                'gast'           => trim($row['vorname'] . ' ' . $row['nachname']),
                'event_name'     => $row['event_name'],
                'platz'          => $row['sitzplatznummer']
                                      ? 'Tisch ' . $row['tischnummer'] . ' · Platz ' . $row['sitzplatznummer']
                                      : 'Freies Ticket',
                'zahl_status'    => $row['zahl_status'] ?? 'offen',
                'betrag'         => (float)($row['betrag'] ?? 0),
                'zeit'           => $row['eingecheckt_am'],
                'zeit_kurz'      => date('H:i', strtotime($row['eingecheckt_am'])),
            ];
        }
    } catch (PDOException $e) {
        // Migration 007 evtl. noch nicht gelaufen – Feed bleibt dann leer
        error_log('Check-in-Feed: ' . $e->getMessage());
    }
}

// Aktueller Zählerstand für die Live-Anzeige
$counts = ['eingecheckt' => 0, 'gesamt' => 0];
if ($eventId !== null) {
    try {
        $stmtC = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN status = 'eingecheckt' THEN 1 ELSE 0 END) AS eingecheckt,
                COUNT(*) AS gesamt
             FROM reservations
             WHERE event_id = ? AND status != 'abgerechnet'"
        );
        $stmtC->execute([$eventId]);
        $row = $stmtC->fetch();
        $counts = [
            'eingecheckt' => (int)($row['eingecheckt'] ?? 0),
            'gesamt'      => (int)($row['gesamt'] ?? 0),
        ];
    } catch (PDOException $e) {
        error_log('Check-in-Zähler: ' . $e->getMessage());
    }
}

// Neuester Zeitstempel für den nächsten Abruf
$last = $checkins ? end($checkins)['zeit'] : $since;

jsonResponse([
    'success' => true,
    'message' => '',
    'data'    => [
        'checkins' => $checkins,
        'counts'   => $counts,
        'since'    => $last,
    ],
]);

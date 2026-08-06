<?php
/**
 * API: Einzelnes Ticket nachschlagen und den signierten QR-Code anzeigen.
 * Für Kassierer/Admin – Ersatzanzeige bei verlorenem Ticket (der Gast steht
 * ohne Ticket am Einlass, der QR wird hier auf dem Bildschirm angezeigt und
 * mit dem Scanner eingescannt) sowie beim Klick auf eine Kachel im
 * Live-Event-Dashboard.
 *
 * GET: reservation_id
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/qrcode.php';

requireRole('kassierer', 'admin');

$reservationId = (int)($_GET['reservation_id'] ?? 0);
if ($reservationId < 1) {
    jsonResponse(['success' => false, 'message' => 'Ungültige ID', 'data' => null], 400);
}

$pdo  = getDB();
$stmt = $pdo->prepare(
    "SELECT r.id, r.buchungsnummer, r.status AS res_status, r.preis,
            u.vorname, u.nachname,
            e.name AS event_name, e.datum AS event_datum,
            t.tischnummer, s.sitzplatznummer,
            p.status AS zahl_status, p.betrag, p.zahlungsart
     FROM reservations r
     INNER JOIN users  u ON u.id = r.user_id
     INNER JOIN events e ON e.id = r.event_id
     LEFT  JOIN seats  s ON s.id = r.seat_id
     LEFT  JOIN tables t ON t.id = s.table_id
     LEFT  JOIN payments p ON p.reservation_id = r.id
     WHERE r.id = ?"
);
$stmt->execute([$reservationId]);
$res = $stmt->fetch();

if (!$res) {
    jsonResponse(['success' => false, 'message' => 'Ticket nicht gefunden', 'data' => null], 404);
}

$gast  = trim($res['vorname'] . ' ' . $res['nachname']);
$platz = $res['sitzplatznummer']
    ? 'Tisch ' . $res['tischnummer'] . ' · Platz ' . $res['sitzplatznummer']
    : 'Freies Ticket';

$qrHtml = qrCodeImg(ticketPayload($res['buchungsnummer']), 260, 'QR-Code ' . $res['buchungsnummer']);

jsonResponse([
    'success' => true,
    'message' => '',
    'data'    => [
        'gast'           => $gast,
        'buchungsnummer' => $res['buchungsnummer'],
        'event_name'     => $res['event_name'],
        'event_datum'    => formatDatum($res['event_datum']),
        'platz'          => $platz,
        'res_status'     => $res['res_status'],
        'zahl_status'    => $res['zahl_status'] ?? 'offen',
        'betrag'         => (float)($res['betrag'] ?? $res['preis'] ?? 0),
        'zahlungsart'    => $res['zahlungsart'] ?? '',
        'qr_html'        => $qrHtml,
    ],
]);

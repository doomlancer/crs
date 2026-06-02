<?php
/**
 * Sitzplatz-Reservierung
 * POST: seat_ids[] (array), event_id, csrf_token
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('error', 'Ungültige Anfrage.');
    redirect('/pages/events.php');
}

requireLogin();

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Sicherheitstoken ungültig. Bitte Seite neu laden.');
    redirect('/pages/tischplan.php');
}

$eventId = (int)($_POST['event_id'] ?? 0);
$backUrl = $eventId ? "/pages/tischplan.php?event_id={$eventId}" : '/pages/tischplan.php';

// seat_ids[] aus POST (Array-Format)
$rawIds  = $_POST['seat_ids'] ?? [];
if (!is_array($rawIds)) {
    $rawIds = [$rawIds];
}
$seatIds = array_values(array_filter(array_map('intval', $rawIds)));

if (empty($seatIds)) {
    setFlash('error', 'Bitte wählen Sie mindestens einen Sitzplatz aus.');
    redirect($backUrl);
}

if (count($seatIds) > 10) {
    setFlash('error', 'Sie können maximal 10 Plätze gleichzeitig buchen.');
    redirect($backUrl);
}

if (!$eventId) {
    setFlash('error', 'Ungültige Veranstaltung.');
    redirect('/pages/events.php');
}

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Event prüfen
$stmt = $pdo->prepare(
    "SELECT id, name, datum, preis, status FROM events WHERE id = ? AND status = 'aktiv' AND datum >= CURDATE()"
);
$stmt->execute([$eventId]);
$event = $stmt->fetch();

if (!$event) {
    setFlash('error', 'Die Veranstaltung ist nicht buchbar.');
    redirect('/pages/events.php');
}

$ticketPreis = (float)$event['preis'];
$zahlungsart = $_SESSION['zahlungsart'] ?? 'bar';

try {
    $pdo->beginTransaction();

    $buchungsnummern = [];
    $reservationIds  = [];

    foreach ($seatIds as $seatId) {
        // Sitz mit Row-Lock laden
        $stmt = $pdo->prepare(
            'SELECT s.id, s.status, t.event_id
             FROM seats s
             JOIN tables t ON s.table_id = t.id
             WHERE s.id = ? AND t.event_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$seatId, $eventId]);
        $seat = $stmt->fetch();

        if (!$seat) {
            $pdo->rollBack();
            setFlash('error', 'Ein gewählter Sitzplatz existiert nicht.');
            redirect($backUrl);
        }

        if ($seat['status'] !== 'verfuegbar') {
            $pdo->rollBack();
            setFlash('error', 'Ein gewählter Sitzplatz ist bereits belegt. Bitte eine neue Auswahl treffen.');
            redirect($backUrl);
        }

        // Sitz reservieren
        $pdo->prepare('UPDATE seats SET status = "reserviert" WHERE id = ?')->execute([$seatId]);

        // Buchungsnummer erzeugen
        $buchungsnummer = generateBuchungsnummer();

        // Reservierung anlegen
        $stmt = $pdo->prepare(
            'INSERT INTO reservations (user_id, event_id, seat_id, buchungsnummer, status, preis)
             VALUES (?, ?, ?, ?, "geplant", ?)'
        );
        $stmt->execute([$userId, $eventId, $seatId, $buchungsnummer, $ticketPreis]);
        $resId = (int)$pdo->lastInsertId();

        // Zahlung anlegen
        $pdo->prepare(
            'INSERT INTO payments (reservation_id, zahlungsart, status, betrag) VALUES (?, ?, "offen", ?)'
        )->execute([$resId, $zahlungsart, $ticketPreis]);

        $buchungsnummern[] = $buchungsnummer;
        $reservationIds[]  = $resId;
    }

    $pdo->commit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Reservierung Fehler: ' . $e->getMessage());
    setFlash('error', 'Reservierung fehlgeschlagen. Bitte erneut versuchen.');
    redirect($backUrl);
}

// Audit-Log (außerhalb der Transaktion)
logAudit(
    'BUCHUNG',
    'reservations',
    $reservationIds[0] ?? null,
    json_encode([
        'event_id'        => $eventId,
        'anzahl'          => count($seatIds),
        'buchungsnummern' => $buchungsnummern,
    ])
);

// Bestätigungs-E-Mail
try {
    sendMail(
        $_SESSION['email'],
        'Buchungsbestätigung – ' . $event['name'],
        'buchungsbestaetigung',
        [
            'vorname'         => $_SESSION['vorname'],
            'buchungsnummern' => $buchungsnummern,
            'event_name'      => $event['name'],
            'event_datum'     => formatDatum($event['datum']),
            'anzahl'          => count($seatIds),
            'betrag_gesamt'   => formatBetrag($ticketPreis * count($seatIds)),
            'zahlungsart'     => zahlungsartLabel($zahlungsart),
            'ticket_url'      => APP_URL . '/pages/meine_reservierungen.php',
        ]
    );
} catch (Exception $e) {
    error_log('Buchungs-Mail Fehler: ' . $e->getMessage());
}

$anzahl = count($buchungsnummern);
$plural = $anzahl !== 1 ? 'ätze' : '';
setFlash(
    'success',
    "{$anzahl} Platz{$plural} erfolgreich reserviert! Buchungsnummer(n): " . implode(', ', $buchungsnummern)
);
redirect('/pages/meine_reservierungen.php');

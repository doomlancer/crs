<?php
/**
 * Sitzplatz-Stornierung
 * POST: reservation_id, event_id, csrf_token
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('error', 'Ungültige Anfrage.');
    redirect('/pages/meine_reservierungen.php');
}

requireLogin();

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Sicherheitstoken ungültig. Bitte Seite neu laden.');
    redirect('/pages/meine_reservierungen.php');
}

$pdo           = getDB();
$userId        = (int)$_SESSION['user_id'];
$reservationId = (int)($_POST['reservation_id'] ?? 0);
$eventId       = (int)($_POST['event_id'] ?? 0);

$redirectUrl = $eventId
    ? '/pages/tischplan.php?event_id=' . $eventId
    : '/pages/meine_reservierungen.php';

if (!$reservationId) {
    setFlash('error', 'Ungültige Reservierung.');
    redirect($redirectUrl);
}

// Reservierung mit allen benötigten Daten laden
$stmt = $pdo->prepare(
    'SELECT r.id, r.user_id, r.seat_id, r.buchungsnummer, r.status, r.event_id, r.preis,
            e.name AS event_name, e.datum AS event_datum,
            u.vorname, u.email AS user_email,
            p.id AS payment_id
     FROM reservations r
     JOIN events e  ON r.event_id = e.id
     JOIN users  u  ON r.user_id  = u.id
     LEFT JOIN payments p ON p.reservation_id = r.id
     WHERE r.id = ?'
);
$stmt->execute([$reservationId]);
$res = $stmt->fetch();

if (!$res) {
    setFlash('error', 'Reservierung nicht gefunden.');
    redirect($redirectUrl);
}

// Nur eigene Reservierungen stornieren (außer Admin/Kassierer)
if ((int)$res['user_id'] !== $userId && !hasRole('admin', 'kassierer')) {
    setFlash('error', 'Sie dürfen diese Reservierung nicht stornieren.');
    redirect($redirectUrl);
}

// Nur Reservierungen mit Status 'geplant' stornieren
if ($res['status'] !== 'geplant') {
    setFlash('error', 'Diese Reservierung kann nicht storniert werden (Status: ' . htmlspecialchars($res['status']) . ').');
    redirect($redirectUrl);
}

try {
    $pdo->beginTransaction();

    // Sitz freigeben
    $pdo->prepare('UPDATE seats SET status = "verfuegbar" WHERE id = ?')->execute([$res['seat_id']]);

    // Zahlung löschen
    if ($res['payment_id']) {
        $pdo->prepare('DELETE FROM payments WHERE id = ?')->execute([$res['payment_id']]);
    }

    // Reservierung löschen
    $pdo->prepare('DELETE FROM reservations WHERE id = ?')->execute([$reservationId]);

    $pdo->commit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Stornierung Fehler: ' . $e->getMessage());
    setFlash('error', 'Stornierung fehlgeschlagen. Bitte erneut versuchen.');
    redirect($redirectUrl);
}

// Audit-Log
logAudit(
    'STORNIERUNG',
    'reservations',
    $reservationId,
    json_encode([
        'buchungsnummer' => $res['buchungsnummer'],
        'event_id'       => $res['event_id'],
        'storniert_von'  => $userId,
    ])
);

// Storno-Mail an Gast
try {
    sendMail(
        $res['user_email'],
        'Stornierungsbestätigung – ' . $res['event_name'],
        'storno_bestaetigung',
        [
            'vorname'        => $res['vorname'],
            'buchungsnummer' => $res['buchungsnummer'],
            'event_name'     => $res['event_name'],
            'event_datum'    => formatDatum($res['event_datum']),
            'betrag'         => formatBetrag((float)$res['preis']),
        ]
    );
} catch (Exception $e) {
    error_log('Storno-Mail Fehler: ' . $e->getMessage());
}

// Admin/Kassierer informieren
notifyAdminStorno(
    [
        'buchungsnummer' => $res['buchungsnummer'],
        'gast_name'      => $res['vorname'],
        'gast_email'     => $res['user_email'],
        'event_name'     => $res['event_name'],
        'event_datum'    => formatDatum($res['event_datum']),
        'betrag'         => $res['preis'],
    ],
    ($_SESSION['vorname'] ?? '') . ' (' . ($_SESSION['email'] ?? '') . ')'
);

// Warteliste benachrichtigen
notifyNextWaitingUser((int)$res['event_id']);

setFlash('success', 'Reservierung ' . htmlspecialchars($res['buchungsnummer']) . ' wurde erfolgreich storniert.');
redirect($redirectUrl);

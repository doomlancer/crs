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

// Fremde Reservierungen darf nur ein Admin stornieren. Kassierer sind für
// Check-in, Zahlungsstatus und Gästelisten zuständig – mit dem früheren
// Zugriff hier hätte ein einzelnes Kassierer-Konto die komplette Buchungs-
// und Zahlungshistorie einer Veranstaltung ausräumen können.
if ((int)$res['user_id'] !== $userId && !hasRole('admin')) {
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

    // Zahlung und Reservierung stornieren statt löschen: der Datensatz bleibt
    // für Abrechnung und Nachvollziehbarkeit erhalten. Der Sitzplatz wird
    // trotzdem wieder buchbar – dafür sorgt der Teilindex uq_seat_aktiv aus
    // Migration 009, der stornierte Zeilen nicht mehr mitzählt.
    if ($res['payment_id']) {
        $pdo->prepare('UPDATE payments SET status = "storniert" WHERE id = ?')
            ->execute([$res['payment_id']]);
    }
    $pdo->prepare('UPDATE reservations SET status = "abgerechnet" WHERE id = ?')
        ->execute([$reservationId]);

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

// Ab hier ist die Stornierung bereits festgeschrieben. Alles Folgende darf den
// Ablauf nicht mehr abbrechen – deshalb Throwable statt Exception: ein Tippfehler
// im Mailversand ist ein Error, kein Exception, und hätte den Redirect samt
// Rückmeldung an den Gast verschluckt.
try {
    sendStornierungsbestaetigung(
        $res['user_email'],
        $res['vorname'],
        $res['buchungsnummer'],
        $res['event_name']
    );
} catch (Throwable $e) {
    error_log('Storno-Mail Fehler: ' . $e->getMessage());
}

// Warteliste: ältesten Eintrag für dieses Event benachrichtigen
try {
    $stmtWl = $pdo->prepare(
        'SELECT u.email, u.vorname FROM waitlist w
         JOIN users u ON w.user_id = u.id
         WHERE w.event_id = ? ORDER BY w.erstellt_am ASC LIMIT 1'
    );
    $stmtWl->execute([(int)$res['event_id']]);
    $nextUser = $stmtWl->fetch();
    if ($nextUser) {
        sendWaitlistNotification(
            $nextUser['email'],
            $nextUser['vorname'],
            $res['event_name'],
            APP_URL . '/pages/tischplan.php?event_id=' . (int)$res['event_id']
        );
    }
} catch (Throwable $e) {
    error_log('Wartelisten-Benachrichtigung fehlgeschlagen: ' . $e->getMessage());
}

setFlash('success', 'Reservierung ' . htmlspecialchars($res['buchungsnummer']) . ' wurde erfolgreich storniert.');
redirect($redirectUrl);

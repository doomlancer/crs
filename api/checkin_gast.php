<?php
/**
 * API: Gast einchecken (Kassierer/Admin)
 * POST: reservation_id, csrf_token
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isAjax()) jsonResponse(['error' => 'Method Not Allowed'], 405);
    redirect('/pages/kassierer_dashboard.php');
}

requireRole('kassierer', 'admin');

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    if (isAjax()) jsonResponse(['error' => 'CSRF-Fehler'], 403);
    setFlash('error', 'Sicherheitsfehler.');
    redirect('/pages/kassierer_dashboard.php');
}

$reservationId   = (int)($_POST['reservation_id'] ?? 0);
$buchungsnummer  = strtoupper(trim($_POST['buchungsnummer'] ?? ''));

if (!$reservationId && empty($buchungsnummer)) {
    if (isAjax()) jsonResponse(['error' => 'Ungültige ID oder Buchungsnummer'], 400);
    setFlash('error', 'Ungültige Reservierungs-ID oder Buchungsnummer.');
    redirect('/pages/kassierer_dashboard.php');
}

try {
    $pdo = getDB();

    // Reservierung laden und prüfen (über ID oder Buchungsnummer)
    if ($reservationId) {
        $stmt = $pdo->prepare(
            'SELECT r.id, r.status, r.buchungsnummer, r.seat_id, r.event_id,
                    u.vorname, u.nachname, u.email,
                    e.name AS event_name, t.tischnummer, s.sitzplatznummer,
                    p.zahlungsart, p.status AS payment_status, p.betrag
             FROM reservations r
             JOIN users  u ON r.user_id  = u.id
             JOIN events e ON r.event_id = e.id
             JOIN seats  s ON r.seat_id  = s.id
             JOIN tables t ON s.table_id = t.id
             LEFT JOIN payments p ON p.reservation_id = r.id
             WHERE r.id = ?'
        );
        $stmt->execute([$reservationId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT r.id, r.status, r.buchungsnummer, r.seat_id, r.event_id,
                    u.vorname, u.nachname, u.email,
                    e.name AS event_name, t.tischnummer, s.sitzplatznummer,
                    p.zahlungsart, p.status AS payment_status, p.betrag
             FROM reservations r
             JOIN users  u ON r.user_id  = u.id
             JOIN events e ON r.event_id = e.id
             JOIN seats  s ON r.seat_id  = s.id
             JOIN tables t ON s.table_id = t.id
             LEFT JOIN payments p ON p.reservation_id = r.id
             WHERE r.buchungsnummer = ?'
        );
        $stmt->execute([$buchungsnummer]);
    }
    $reservation = $stmt->fetch();

    if (!$reservation) {
        if (isAjax()) jsonResponse(['error' => 'Reservierung nicht gefunden'], 404);
        setFlash('error', 'Reservierung nicht gefunden.');
        redirect('/pages/kassierer_dashboard.php');
    }

    if ($reservation['status'] !== 'geplant') {
        if (isAjax()) jsonResponse(['error' => 'Gast bereits eingecheckt oder abgerechnet'], 409);
        setFlash('warning', 'Gast ist bereits eingecheckt oder abgerechnet.');
        redirect('/pages/kassierer_guestlist.php');
    }

    $pdo->beginTransaction();

    // Check-in durchführen
    $pdo->prepare('UPDATE reservations SET status = "eingecheckt" WHERE id = ?')
        ->execute([$reservationId]);
    $pdo->prepare("UPDATE seats SET status = 'besetzt' WHERE id = ?")
        ->execute([$reservation['seat_id']]);

    logAudit('CHECKIN', 'reservations', $reservationId,
        "Check-in: {$reservation['buchungsnummer']} ({$reservation['vorname']} {$reservation['nachname']})");

    $pdo->commit();

    if (isAjax()) {
        jsonResponse([
            'success'        => true,
            'message'        => "Gast {$reservation['vorname']} {$reservation['nachname']} erfolgreich eingecheckt.",
            'gast'           => $reservation['vorname'] . ' ' . $reservation['nachname'],
            'email'          => $reservation['email'] ?? '',
            'tisch'          => $reservation['tischnummer'] ?? '',
            'platz'          => $reservation['sitzplatznummer'] ?? '',
            'event'          => $reservation['event_name'] ?? '',
            'zahlungsart'    => zahlungsartLabel($reservation['zahlungsart'] ?? 'bar'),
            'payment_status' => $reservation['payment_status'] ?? 'offen',
            'betrag'         => formatBetrag((float)($reservation['betrag'] ?? 0)),
            'buchungsnummer' => $reservation['buchungsnummer'],
        ]);
    }

    setFlash('success', "Gast {$reservation['vorname']} {$reservation['nachname']} erfolgreich eingecheckt.");
    $redirect = $_POST['redirect'] ?? '/pages/kassierer_guestlist.php';
    redirect($redirect);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Check-in Fehler: ' . $e->getMessage());
    if (isAjax()) jsonResponse(['error' => 'Datenbankfehler'], 500);
    setFlash('error', 'Fehler beim Check-in.');
    redirect('/pages/kassierer_dashboard.php');
}

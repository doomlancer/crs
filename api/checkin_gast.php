<?php
/**
 * API: Gast einchecken (Kassierer/Admin)
 * POST: reservation_id, csrf_token
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isAjax()) jsonResponse(['success' => false, 'message' => 'Method Not Allowed', 'data' => null], 405);
    redirect('/pages/kassierer_dashboard.php');
}

requireRole('kassierer', 'admin');

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    if (isAjax()) jsonResponse(['success' => false, 'message' => 'CSRF-Fehler', 'data' => null], 403);
    setFlash('error', 'Sicherheitsfehler.');
    redirect('/pages/kassierer_dashboard.php');
}

$reservationId = (int)($_POST['reservation_id'] ?? 0);
if (!$reservationId) {
    if (isAjax()) jsonResponse(['success' => false, 'message' => 'Ungültige ID', 'data' => null], 400);
    setFlash('error', 'Ungültige Reservierungs-ID.');
    redirect('/pages/kassierer_dashboard.php');
}

try {
    $pdo = getDB();

    // Reservierung laden und prüfen
    $stmt = $pdo->prepare(
        'SELECT r.id, r.status, r.buchungsnummer, r.seat_id,
                u.vorname, u.nachname
         FROM reservations r
         JOIN users u ON r.user_id = u.id
         WHERE r.id = ?'
    );
    $stmt->execute([$reservationId]);
    $reservation = $stmt->fetch();

    if (!$reservation) {
        if (isAjax()) jsonResponse(['success' => false, 'message' => 'Reservierung nicht gefunden', 'data' => null], 404);
        setFlash('error', 'Reservierung nicht gefunden.');
        redirect('/pages/kassierer_dashboard.php');
    }

    if ($reservation['status'] !== 'geplant') {
        if (isAjax()) jsonResponse(['success' => false, 'message' => 'Gast bereits eingecheckt oder abgerechnet', 'data' => null], 409);
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
            'success' => true,
            'message' => "Gast {$reservation['vorname']} {$reservation['nachname']} erfolgreich eingecheckt.",
            'data'    => ['buchungsnummer' => $reservation['buchungsnummer']],
        ]);
    }

    setFlash('success', "Gast {$reservation['vorname']} {$reservation['nachname']} erfolgreich eingecheckt.");
    $redirect = $_POST['redirect'] ?? '/pages/kassierer_guestlist.php';
    redirect($redirect);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Check-in Fehler: ' . $e->getMessage());
    if (isAjax()) jsonResponse(['success' => false, 'message' => 'Datenbankfehler', 'data' => null], 500);
    setFlash('error', 'Fehler beim Check-in.');
    redirect('/pages/kassierer_dashboard.php');
}

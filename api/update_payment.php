<?php
/**
 * API: Zahlungsstatus aktualisieren (Kassierer/Admin)
 * POST: reservation_id, payment_status, csrf_token
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isAjax()) jsonResponse(['error' => 'Method Not Allowed'], 405);
    redirect('/pages/kassierer_guestlist.php');
}

requireRole('kassierer', 'admin');

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    if (isAjax()) jsonResponse(['error' => 'CSRF-Fehler'], 403);
    setFlash('error', 'Sicherheitsfehler.');
    redirect('/pages/kassierer_guestlist.php');
}

$reservationId = (int)($_POST['reservation_id'] ?? 0);
$newStatus     = $_POST['payment_status'] ?? '';

if (!$reservationId || !in_array($newStatus, ['offen', 'bezahlt', 'storniert'], true)) {
    if (isAjax()) jsonResponse(['error' => 'Ungültige Parameter'], 400);
    setFlash('error', 'Ungültige Eingabe.');
    redirect('/pages/kassierer_guestlist.php');
}

try {
    $pdo = getDB();

    // Zahlung zur Reservierung suchen
    $stmt = $pdo->prepare(
        'SELECT p.id, p.status, p.zahlungsart, p.betrag, r.buchungsnummer,
                u.vorname, u.nachname
         FROM payments p
         JOIN reservations r ON p.reservation_id = r.id
         JOIN users u ON r.user_id = u.id
         WHERE p.reservation_id = ?'
    );
    $stmt->execute([$reservationId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        if (isAjax()) jsonResponse(['error' => 'Zahlung nicht gefunden'], 404);
        setFlash('error', 'Zahlung nicht gefunden.');
        redirect('/pages/kassierer_guestlist.php');
    }

    $altStatus = $payment['status'];
    $pdo->prepare('UPDATE payments SET status = ? WHERE reservation_id = ?')
        ->execute([$newStatus, $reservationId]);

    // Bei Markierung als bezahlt: Reservierung ggf. auf abgerechnet setzen
    if ($newStatus === 'bezahlt') {
        $pdo->prepare('UPDATE reservations SET status = "abgerechnet" WHERE id = ? AND status = "eingecheckt"')
            ->execute([$reservationId]);
    }

    logAudit('ZAHLUNG_UPDATE', 'payments', $payment['id'],
        "Zahlung {$payment['buchungsnummer']}: {$altStatus} → {$newStatus}");

    if (isAjax()) {
        jsonResponse([
            'success'    => true,
            'message'    => "Zahlungsstatus aktualisiert: {$altStatus} → {$newStatus}",
            'new_status' => $newStatus,
        ]);
    }

    setFlash('success', "Zahlungsstatus für {$payment['vorname']} {$payment['nachname']} aktualisiert.");
    $redirect = $_POST['redirect'] ?? '/pages/kassierer_guestlist.php';
    redirect($redirect);

} catch (PDOException $e) {
    error_log('Payment Update Fehler: ' . $e->getMessage());
    if (isAjax()) jsonResponse(['error' => 'Datenbankfehler'], 500);
    setFlash('error', 'Fehler beim Aktualisieren des Zahlungsstatus.');
    redirect('/pages/kassierer_guestlist.php');
}

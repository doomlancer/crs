<?php
/**
 * API: Zahlungsstatus setzen – vor allem "am Einlass bar kassiert".
 *
 * POST: reservation_id, payment_status (bezahlt|offen), csrf_token, [bar=1]
 *
 * Kassierer/Admin dürfen bezahlt und offen setzen, Einlass-Helfer nur
 * "bezahlt" und nur für Tickets ihres Events. Mit bar=1 wird die Zahlungsart
 * auf Bar umgestellt (der Gast hat am Einlass bar bezahlt statt überwiesen).
 *
 * Früher setzte "bezahlt" eingecheckte Buchungen zusätzlich auf
 * "abgerechnet" – sie verschwanden damit aus Gästeliste und Live-Übersicht.
 * Abgerechnet wird ausschließlich beim Abschluss eines Events (admin_events).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

function zahlungAntwort(bool $ok, string $msg, ?array $data = null, int $status = 200): void {
    if (wantsJson()) {
        jsonResponse(['success' => $ok, 'message' => $msg, 'data' => $data], $status);
    }
    setFlash($ok ? 'success' : 'error', $msg);
    redirect(safeRedirectTarget($_POST['redirect'] ?? null, '/pages/kassierer_guestlist.php'));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    zahlungAntwort(false, 'Nur POST erlaubt.', null, 405);
}

requireRole('kassierer', 'admin', 'einlass');

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    zahlungAntwort(false, 'Sicherheitsfehler. Bitte Seite neu laden.', null, 403);
}

$reservationId = (int)($_POST['reservation_id'] ?? 0);
$newStatus     = (string)($_POST['payment_status'] ?? '');
$istEinlass    = hasRole('einlass');
$erlaubt       = $istEinlass ? ['bezahlt'] : ['bezahlt', 'offen'];

if ($reservationId < 1 || !in_array($newStatus, $erlaubt, true)) {
    zahlungAntwort(false, 'Ungültige Eingabe.', null, 400);
}

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'SELECT p.id, p.status, p.zahlungsart, p.betrag, r.buchungsnummer, r.event_id, r.status AS res_status,
                u.vorname, u.nachname
         FROM payments p
         JOIN reservations r ON p.reservation_id = r.id
         JOIN users u ON r.user_id = u.id
         WHERE p.reservation_id = ?'
    );
    $stmt->execute([$reservationId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        zahlungAntwort(false, 'Zahlung nicht gefunden.', null, 404);
    }
    if ($istEinlass && (int)$payment['event_id'] !== (int)($_SESSION['einlass_event_id'] ?? 0)) {
        zahlungAntwort(false, 'Ticket gehört zu einer anderen Veranstaltung.', null, 409);
    }
    if (in_array($payment['res_status'], ['storniert', 'abgerechnet'], true) || $payment['status'] === 'storniert') {
        zahlungAntwort(false, 'Diese Buchung ist storniert oder abgerechnet.', null, 409);
    }

    $alt   = $payment['status'];
    $art   = !empty($_POST['bar']) && $newStatus === 'bezahlt' ? 'bar' : $payment['zahlungsart'];
    $pdo->prepare('UPDATE payments SET status = ?, zahlungsart = ? WHERE id = ?')
        ->execute([$newStatus, $art, $payment['id']]);

    logAudit('ZAHLUNG_UPDATE', 'payments', (int)$payment['id'],
        "Zahlung {$payment['buchungsnummer']}: {$alt} → {$newStatus}" . ($art !== $payment['zahlungsart'] ? " (bar am Einlass)" : '') . auditActorLabel());

    $name = trim($payment['vorname'] . ' ' . $payment['nachname']);
    zahlungAntwort(true,
        $newStatus === 'bezahlt'
            ? formatBetrag((float)$payment['betrag']) . ' von ' . $name . ' als bezahlt verbucht.'
            : 'Zahlung von ' . $name . ' wieder auf offen gesetzt.',
        ['new_status' => $newStatus, 'betrag' => (float)$payment['betrag']]);

} catch (PDOException $e) {
    error_log('Payment Update Fehler: ' . $e->getMessage());
    zahlungAntwort(false, 'Fehler beim Speichern der Zahlung.', null, 500);
}

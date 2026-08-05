<?php
/**
 * API: Gast einchecken (Kassierer/Admin)
 *
 * POST-Parameter (eines davon):
 *   - payload          signierter QR-Inhalt "KARN-JJJJ-XXXXXX.<signatur>"
 *   - reservation_id   direkte ID (aus Listen/Buttons im Backend)
 *   - buchungsnummer   manuelle Eingabe (nur mit Berechtigung, ohne Signatur)
 * Weitere: csrf_token (Pflicht), event_id (optional, bindet an Veranstaltung)
 *
 * Die eigentliche Prüf- und Schreiblogik liegt zentral in
 * checkinReservation()/checkinByPayload() (functions.php) – Dashboard,
 * Gästeliste und Scanner nutzen denselben Code-Pfad.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

/** Antwortet je nach Aufrufart als JSON oder per Redirect mit Flash-Nachricht. */
function checkinRespond(bool $ok, string $message, array $data = [], int $status = 200): void {
    $wantsJson = isAjax()
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || ($_POST['format'] ?? '') === 'json';

    if ($wantsJson) {
        jsonResponse(['success' => $ok, 'message' => $message, 'data' => $data ?: null], $status);
    }

    setFlash($ok ? 'success' : 'error', $message);

    // Offene Weiterleitungen verhindern: nur seiteninterne Pfade zulassen.
    $target = $_POST['redirect'] ?? '/pages/kassierer_dashboard.php';
    if (!is_string($target) || !preg_match('#^/[A-Za-z0-9_\-/\.]*$#', $target) || str_contains($target, '..')) {
        $target = '/pages/kassierer_dashboard.php';
    }
    redirect($target);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    checkinRespond(false, 'Nur POST erlaubt.', [], 405);
}

requireRole('kassierer', 'admin');

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    checkinRespond(false, 'Sicherheitsfehler. Bitte Seite neu laden.', [], 403);
}

$eventId = isset($_POST['event_id']) && (int)$_POST['event_id'] > 0
    ? (int)$_POST['event_id']
    : null;

$payload        = trim((string)($_POST['payload'] ?? ''));
$reservationId  = (int)($_POST['reservation_id'] ?? 0);
$buchungsnummer = trim((string)($_POST['buchungsnummer'] ?? ''));

if ($payload !== '') {
    // Gescannter QR-Code: Signatur wird zwingend geprüft
    $result = checkinByPayload($payload, $eventId, true);
} elseif ($reservationId > 0) {
    // Direkter Klick im Backend – Berechtigung wurde oben bereits geprüft
    $result = checkinReservation($reservationId, $eventId);
} elseif ($buchungsnummer !== '') {
    // Manuelle Eingabe durch Kassierer/Admin (z.B. wenn der Code beschädigt ist).
    // Ohne Signatur zulässig, aber im Audit-Log als manuell erkennbar.
    $result = checkinByPayload($buchungsnummer, $eventId, false);
    if ($result['ok']) {
        logAudit('CHECK_IN_MANUELL', 'reservations',
            $result['data']['reservation_id'] ?? null,
            'Manueller Check-in ohne QR-Signatur: ' . $buchungsnummer);
    }
} else {
    checkinRespond(false, 'Kein Ticket übermittelt.', [], 400);
}

$httpStatus = $result['ok'] ? 200 : match ($result['code']) {
    'not_found'                             => 404,
    'already'                               => 409,
    'invalid_signature', 'invalid_format'   => 422,
    'wrong_event', 'invalid_status'         => 409,
    default                                 => 500,
};

checkinRespond(
    $result['ok'],
    $result['message'],
    ($result['data'] ?? []) + ['code' => $result['code']],
    $httpStatus
);

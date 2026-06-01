<?php
/**
 * API: QR-Code-Check-in
 * GET: ?token=HMAC&nr=BUCHUNGSNUMMER
 * POST: JSON { token, nr } – für AJAX-Scanner
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$input  = $isPost ? (json_decode(file_get_contents('php://input'), true) ?? $_POST) : [];

$token          = trim(($input['token'] ?? $_GET['token']) ?? '');
$buchungsnummer = trim(($input['nr']    ?? $_GET['nr'])    ?? '');

if (empty($token) || empty($buchungsnummer)) {
    if ($isPost || isAjax()) {
        jsonResponse(['success' => false, 'error' => 'Fehlende Parameter.'], 400);
    }
    setFlash('error', 'Ungültiger QR-Code.');
    redirect('/pages/kassierer_dashboard.php');
}

// HMAC verifizieren
if (!verifyHmacToken($buchungsnummer, $token)) {
    if ($isPost || isAjax()) {
        jsonResponse(['success' => false, 'error' => 'Ungültiger Token – QR-Code gefälscht?'], 403);
    }
    setFlash('error', 'Ungültiger QR-Code. Möglicherweise gefälscht.');
    redirect('/pages/kassierer_dashboard.php');
}

// Kassierer/Admin erforderlich für direkten Check-in
// (Bei normalem Zugriff über Browser: Login prüfen)
if (!isLoggedIn()) {
    if ($isPost || isAjax()) {
        jsonResponse(['success' => false, 'error' => 'Nicht eingeloggt.'], 401);
    }
    setFlash('error', 'Bitte melden Sie sich an.');
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    redirect('/pages/login.php');
}

if (!hasRole('kassierer', 'admin')) {
    if ($isPost || isAjax()) {
        jsonResponse(['success' => false, 'error' => 'Keine Berechtigung.'], 403);
    }
    setFlash('error', 'Kein Zugriff.');
    redirect('/pages/events.php');
}

$pdo = getDB();

// Buchung laden
$stmt = $pdo->prepare(
    'SELECT r.id, r.status, r.event_id,
            u.vorname, u.nachname,
            e.name AS event_name,
            t.tischnummer, s.sitzplatznummer
     FROM reservations r
     JOIN users  u ON r.user_id  = u.id
     JOIN events e ON r.event_id = e.id
     JOIN seats  s ON r.seat_id  = s.id
     JOIN tables t ON s.table_id = t.id
     WHERE r.buchungsnummer = ?'
);
$stmt->execute([$buchungsnummer]);
$reservation = $stmt->fetch();

if (!$reservation) {
    if ($isPost || isAjax()) {
        jsonResponse(['success' => false, 'error' => 'Buchung nicht gefunden.'], 404);
    }
    setFlash('error', 'Buchung nicht gefunden.');
    redirect('/pages/kassierer_dashboard.php');
}

if ($reservation['status'] === 'eingecheckt') {
    $msg = sprintf(
        '%s %s ist bereits eingecheckt (Tisch %s, Platz %s).',
        htmlspecialchars($reservation['vorname']),
        htmlspecialchars($reservation['nachname']),
        $reservation['tischnummer'],
        $reservation['sitzplatznummer']
    );
    if ($isPost || isAjax()) {
        jsonResponse(['success' => false, 'alreadyCheckedIn' => true, 'message' => strip_tags($msg)]);
    }
    setFlash('info', $msg);
    redirect('/pages/kassierer_guestlist.php?event_id=' . $reservation['event_id']);
}

if ($reservation['status'] !== 'geplant') {
    if ($isPost || isAjax()) {
        jsonResponse(['success' => false, 'error' => 'Buchung hat ungültigen Status: ' . $reservation['status']]);
    }
    setFlash('error', 'Buchung kann nicht eingecheckt werden (Status: ' . $reservation['status'] . ').');
    redirect('/pages/kassierer_dashboard.php');
}

// Check-in durchführen
$pdo->prepare("UPDATE reservations SET status = 'eingecheckt' WHERE id = ?")
    ->execute([$reservation['id']]);
$pdo->prepare("UPDATE seats SET status = 'besetzt' WHERE sitzplatznummer = (SELECT sitzplatznummer FROM seats s2 JOIN reservations r2 ON r2.seat_id = s2.id WHERE r2.id = ?)")
    ->execute([$reservation['id']]);

logAudit('CHECKIN', 'reservations', $reservation['id'], "Check-in: {$buchungsnummer}");

$successMsg = sprintf(
    'Check-in erfolgreich! %s %s – Tisch %s, Platz %s – %s',
    htmlspecialchars($reservation['vorname']),
    htmlspecialchars($reservation['nachname']),
    $reservation['tischnummer'],
    $reservation['sitzplatznummer'],
    htmlspecialchars($reservation['event_name'])
);

if ($isPost || isAjax()) {
    jsonResponse([
        'success'  => true,
        'message'  => strip_tags($successMsg),
        'gast'     => $reservation['vorname'] . ' ' . $reservation['nachname'],
        'tisch'    => $reservation['tischnummer'],
        'platz'    => $reservation['sitzplatznummer'],
        'event'    => $reservation['event_name'],
    ]);
}

setFlash('success', $successMsg);
redirect('/pages/kassierer_guestlist.php?event_id=' . $reservation['event_id']);

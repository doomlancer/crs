<?php
/**
 * API: Warteliste beitreten oder verlassen
 * POST: event_id, csrf_token, [action=leave]
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('error', 'Ungültige Anfrage.');
    redirect('/pages/events.php');
}

requireLogin();

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Sicherheitsfehler. Bitte Seite neu laden.');
    redirect('/pages/events.php');
}

$pdo     = getDB();
$userId  = (int)$_SESSION['user_id'];
$eventId = (int)($_POST['event_id'] ?? 0);
$action  = $_POST['action'] ?? 'join';

if (!$eventId) {
    setFlash('error', 'Kein Event ausgewählt.');
    redirect('/pages/events.php');
}

// Event prüfen
$stmtEvent = $pdo->prepare("SELECT id, name, status FROM events WHERE id = ? AND status = 'aktiv'");
$stmtEvent->execute([$eventId]);
$event = $stmtEvent->fetch();

if (!$event) {
    setFlash('error', 'Event nicht gefunden oder nicht aktiv.');
    redirect('/pages/events.php');
}

if ($action === 'leave') {
    $pdo->prepare('DELETE FROM waitlist WHERE user_id = ? AND event_id = ?')
        ->execute([$userId, $eventId]);
    logAudit('WARTELISTE_VERLASSEN', 'waitlist', null, "Event: {$eventId}");
    setFlash('success', 'Sie wurden von der Warteliste entfernt.');
    redirect('/pages/meine_reservierungen.php');
}

// Prüfen ob bereits auf Warteliste
$stmtCheck = $pdo->prepare('SELECT id FROM waitlist WHERE user_id = ? AND event_id = ?');
$stmtCheck->execute([$userId, $eventId]);
if ($stmtCheck->fetch()) {
    setFlash('info', 'Sie stehen bereits auf der Warteliste für dieses Event.');
    redirect('/pages/events.php');
}

// Prüfen ob bereits eine aktive Reservierung für dieses Event besteht
$stmtRes = $pdo->prepare(
    "SELECT id FROM reservations WHERE user_id = ? AND event_id = ? AND status != 'abgerechnet'"
);
$stmtRes->execute([$userId, $eventId]);
if ($stmtRes->fetch()) {
    setFlash('info', 'Sie haben bereits eine aktive Reservierung für dieses Event.');
    redirect('/pages/events.php');
}

// Prüfen ob noch freie Plätze vorhanden (dann kein Wartelisten-Eintrag nötig)
$stmtFrei = $pdo->prepare(
    "SELECT COUNT(*) FROM seats s
     JOIN tables t ON s.table_id = t.id
     WHERE t.event_id = ? AND s.status = 'verfuegbar'"
);
$stmtFrei->execute([$eventId]);
$freiePlaetze = (int)$stmtFrei->fetchColumn();

if ($freiePlaetze > 0) {
    setFlash('info', 'Es sind noch freie Plätze verfügbar. Bitte reservieren Sie direkt.');
    redirect('/pages/tischplan.php?event_id=' . $eventId);
}

// Auf Warteliste setzen
$pdo->prepare('INSERT INTO waitlist (user_id, event_id) VALUES (?, ?)')
    ->execute([$userId, $eventId]);

logAudit('WARTELISTE_BEIGETRETEN', 'waitlist', null, "Event: {$eventId}");
setFlash('success', 'Sie wurden erfolgreich auf die Warteliste gesetzt. Wir benachrichtigen Sie, sobald ein Platz frei wird.');
redirect('/pages/meine_reservierungen.php');

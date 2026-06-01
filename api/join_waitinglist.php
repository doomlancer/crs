<?php
/**
 * API: Warteliste beitreten
 * POST: event_id, csrf_token
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/pages/events.php');
}

requireLogin();

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Sicherheitsfehler. Bitte Seite neu laden.');
    redirect('/pages/tischplan.php');
}

$pdo     = getDB();
$userId  = (int)$_SESSION['user_id'];
$eventId = (int)($_POST['event_id'] ?? 0);

if (!$eventId) {
    setFlash('error', 'Kein Event angegeben.');
    redirect('/pages/events.php');
}

// Event prüfen
$stmt = $pdo->prepare("SELECT id, name FROM events WHERE id = ? AND status = 'aktiv'");
$stmt->execute([$eventId]);
$event = $stmt->fetch();

if (!$event) {
    setFlash('error', 'Event nicht gefunden oder nicht aktiv.');
    redirect('/pages/events.php');
}

// Bereits auf Warteliste?
$stmt = $pdo->prepare("SELECT id FROM waitinglist WHERE user_id = ? AND event_id = ? AND status IN ('wartend','benachrichtigt')");
$stmt->execute([$userId, $eventId]);
if ($stmt->fetch()) {
    setFlash('info', 'Sie stehen bereits auf der Warteliste für dieses Event.');
    redirect('/pages/tischplan.php?event_id=' . $eventId);
}

// Bereits eine aktive Reservierung?
$stmt = $pdo->prepare("SELECT id FROM reservations WHERE user_id = ? AND event_id = ? AND status IN ('geplant','eingecheckt')");
$stmt->execute([$userId, $eventId]);
if ($stmt->fetch()) {
    setFlash('info', 'Sie haben bereits eine Reservierung für dieses Event.');
    redirect('/pages/tischplan.php?event_id=' . $eventId);
}

try {
    $pdo->prepare(
        "INSERT INTO waitinglist (user_id, event_id) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE status = 'wartend', benachrichtigt_token = NULL, token_expires = NULL"
    )->execute([$userId, $eventId]);

    logAudit('WARTELISTE_BEIGETRETEN', 'waitinglist', null, "Event: {$eventId}");
    setFlash('success', 'Sie wurden erfolgreich auf die Warteliste eingetragen. Wir benachrichtigen Sie, wenn ein Platz frei wird.');
} catch (PDOException $e) {
    error_log('Warteliste-Fehler: ' . $e->getMessage());
    setFlash('error', 'Fehler beim Eintragen in die Warteliste.');
}

redirect('/pages/meine_reservierungen.php');

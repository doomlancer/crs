<?php
/**
 * API: Warteliste verlassen
 * POST: waitinglist_id, csrf_token
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/pages/meine_reservierungen.php');
}

requireLogin();

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Sicherheitsfehler. Bitte Seite neu laden.');
    redirect('/pages/meine_reservierungen.php');
}

$pdo   = getDB();
$userId = (int)$_SESSION['user_id'];
$wlId  = (int)($_POST['waitinglist_id'] ?? 0);

if (!$wlId) {
    setFlash('error', 'Ungültige Anfrage.');
    redirect('/pages/meine_reservierungen.php');
}

// Nur eigene Einträge löschen
$stmt = $pdo->prepare('SELECT id FROM waitinglist WHERE id = ? AND user_id = ?');
$stmt->execute([$wlId, $userId]);

if (!$stmt->fetch()) {
    setFlash('error', 'Wartelisteneintrag nicht gefunden.');
    redirect('/pages/meine_reservierungen.php');
}

$pdo->prepare('DELETE FROM waitinglist WHERE id = ? AND user_id = ?')
    ->execute([$wlId, $userId]);

logAudit('WARTELISTE_VERLASSEN', 'waitinglist', $wlId, 'Warteliste verlassen');
setFlash('success', 'Sie wurden von der Warteliste entfernt.');
redirect('/pages/meine_reservierungen.php');

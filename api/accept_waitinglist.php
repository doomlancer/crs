<?php
/**
 * API: Wartelisten-Platz annehmen (GET mit 24h-Token)
 * Leitet Benutzer zum Tischplan weiter, damit er reservieren kann
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    setFlash('error', 'Ungültiger Link.');
    redirect('/pages/events.php');
}

$pdo = getDB();

// Token prüfen
$stmt = $pdo->prepare(
    "SELECT w.id, w.user_id, w.event_id, w.token_expires,
            e.name AS event_name, e.status AS event_status
     FROM waitinglist w
     JOIN events e ON w.event_id = e.id
     WHERE w.benachrichtigt_token = ? AND w.status = 'benachrichtigt'"
);
$stmt->execute([$token]);
$entry = $stmt->fetch();

if (!$entry) {
    setFlash('error', 'Dieser Link ist ungültig oder wurde bereits verwendet.');
    redirect('/pages/events.php');
}

// Abgelaufen?
if (strtotime($entry['token_expires']) < time()) {
    $pdo->prepare("UPDATE waitinglist SET status = 'abgelaufen' WHERE id = ?")
        ->execute([$entry['id']]);
    setFlash('error', 'Dieser Link ist abgelaufen (24h-Frist überschritten). Bitte melden Sie sich erneut auf der Warteliste an.');
    redirect('/pages/events.php');
}

// Event noch aktiv und Plätze frei?
if ($entry['event_status'] !== 'aktiv') {
    setFlash('error', 'Dieses Event ist nicht mehr buchbar.');
    redirect('/pages/events.php');
}

// Wartelistenstatus auf 'gebucht' setzen (Token wird eingelöst)
$pdo->prepare("UPDATE waitinglist SET status = 'gebucht' WHERE id = ?")
    ->execute([$entry['id']]);

logAudit('WARTELISTE_AKZEPTIERT', 'waitinglist', $entry['id'], "Event: {$entry['event_id']}");

// Falls nicht eingeloggt: Login + dann Tischplan
if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = '/pages/tischplan.php?event_id=' . $entry['event_id'];
    setFlash('info', 'Bitte melden Sie sich an, um Ihren Platz für "' . htmlspecialchars($entry['event_name']) . '" zu reservieren.');
    redirect('/pages/login.php');
}

// Eigener User muss übereinstimmen
if ((int)$_SESSION['user_id'] !== (int)$entry['user_id']) {
    setFlash('error', 'Dieser Link gehört zu einem anderen Konto. Bitte melden Sie sich mit dem richtigen Konto an.');
    redirect('/pages/login.php');
}

setFlash('success', 'Willkommen zurück! Bitte wählen Sie schnell einen Sitzplatz für "' . htmlspecialchars($entry['event_name']) . '" aus.');
redirect('/pages/tischplan.php?event_id=' . $entry['event_id']);

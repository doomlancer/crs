<?php
/**
 * API: DSGVO-Kontolöschung (Anonymisierung)
 * POST: passwort, csrf_token, action=delete_account
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/pages/profil.php');
}

requireLogin();

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Sicherheitsfehler. Bitte Seite neu laden.');
    redirect('/pages/profil.php');
}

$pdo      = getDB();
$userId   = (int)$_SESSION['user_id'];
$passwort = $_POST['passwort'] ?? '';

if (empty($passwort)) {
    setFlash('error', 'Bitte geben Sie Ihr Passwort zur Bestätigung ein.');
    redirect('/pages/profil.php');
}

// Passwort prüfen
$stmt = $pdo->prepare('SELECT passwort FROM users WHERE id = ? AND aktiv = 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || !verifyPassword($passwort, $user['passwort'])) {
    setFlash('error', 'Falsches Passwort. Kontolöschung abgebrochen.');
    redirect('/pages/profil.php');
}

// Offene unbezahlte Reservierungen prüfen
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM reservations r
     JOIN payments p ON p.reservation_id = r.id
     WHERE r.user_id = ? AND r.status = 'geplant' AND p.status = 'offen'"
);
$stmt->execute([$userId]);
$offeneZahlungen = (int)$stmt->fetchColumn();

if ($offeneZahlungen > 0) {
    setFlash('error', "Sie haben noch {$offeneZahlungen} offene Zahlung(en). Bitte begleichen Sie diese zuerst oder kontaktieren Sie den Kassierer.");
    redirect('/pages/profil.php');
}

// Konto anonymisieren (DSGVO-konform: keine Löschung wegen Reservierungshistorie)
try {
    $pdo->beginTransaction();

    $anonymEmail = "deleted_{$userId}@deleted.invalid";
    $pdo->prepare(
        'UPDATE users SET
            email        = ?,
            vorname      = ?,
            nachname     = ?,
            adresse      = NULL,
            telefon      = NULL,
            geburtsdatum = NULL,
            passwort     = ?,
            aktiv        = 0
         WHERE id = ?'
    )->execute([
        $anonymEmail,
        'Gelöscht',
        '',
        '$2y$12$invalidhashXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
        $userId,
    ]);

    // Wartelisten-Einträge entfernen
    $pdo->prepare('DELETE FROM waitinglist WHERE user_id = ?')->execute([$userId]);

    logAudit('KONTO_GELOESCHT', 'users', $userId, 'DSGVO-Kontolöschung (Anonymisierung)');
    $pdo->commit();

    // Ausloggen
    session_unset();
    session_destroy();
    session_start();
    setFlash('success', 'Ihr Konto wurde erfolgreich gelöscht. Alle persönlichen Daten wurden anonymisiert.');
    redirect('/pages/events.php');

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('Kontolöschung Fehler: ' . $e->getMessage());
    setFlash('error', 'Fehler beim Löschen des Kontos. Bitte versuchen Sie es erneut.');
    redirect('/pages/profil.php');
}

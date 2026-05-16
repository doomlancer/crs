<?php
/**
 * Authentifizierungs-Logik: Login, Logout, Session-Verwaltung
 *
 * Dieser Endpunkt wird auch direkt aufgerufen:
 *   /includes/auth.php?action=logout
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

/**
 * Benutzer einloggen
 * Gibt true bei Erfolg zurück, sonst Fehlermeldung
 */
function loginUser(string $email, string $passwort): true|string {
    $pdo = getDB();

    // Benutzer anhand der E-Mail suchen
    $stmt = $pdo->prepare(
        'SELECT id, vorname, nachname, email, passwort, rolle, aktiv, login_versuche, gesperrt_bis
         FROM users WHERE email = ?'
    );
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user) {
        return 'Ungültige E-Mail oder Passwort.';
    }

    // Konto gesperrt?
    if (!empty($user['gesperrt_bis']) && strtotime($user['gesperrt_bis']) > time()) {
        $minuten = ceil((strtotime($user['gesperrt_bis']) - time()) / 60);
        return "Konto gesperrt. Bitte warten Sie noch {$minuten} Minute(n).";
    }

    // Konto deaktiviert?
    if (!$user['aktiv']) {
        return 'Dieses Konto wurde deaktiviert. Bitte kontaktieren Sie den Administrator.';
    }

    // Passwort prüfen
    if (!verifyPassword($passwort, $user['passwort'])) {
        // Fehlversuche hochzählen
        $versuche = $user['login_versuche'] + 1;
        if ($versuche >= MAX_LOGIN_VERSUCHE) {
            $gesperrt_bis = date('Y-m-d H:i:s', time() + LOGIN_SPERRZEIT);
            $pdo->prepare('UPDATE users SET login_versuche = ?, gesperrt_bis = ? WHERE id = ?')
                ->execute([$versuche, $gesperrt_bis, $user['id']]);
            $minuten = LOGIN_SPERRZEIT / 60;
            return "Zu viele Fehlversuche. Konto für {$minuten} Minuten gesperrt.";
        }
        $verbleibend = MAX_LOGIN_VERSUCHE - $versuche;
        $pdo->prepare('UPDATE users SET login_versuche = ? WHERE id = ?')
            ->execute([$versuche, $user['id']]);
        return "Ungültige E-Mail oder Passwort. Noch {$verbleibend} Versuch(e) übrig.";
    }

    // Erfolgreich: Fehlversuche zurücksetzen, Session setzen
    $pdo->prepare('UPDATE users SET login_versuche = 0, gesperrt_bis = NULL WHERE id = ?')
        ->execute([$user['id']]);

    // Session-ID erneuern (Session Fixation verhindern)
    session_regenerate_id(true);

    $_SESSION['user_id']  = $user['id'];
    $_SESSION['vorname']  = $user['vorname'];
    $_SESSION['nachname'] = $user['nachname'];
    $_SESSION['email']    = $user['email'];
    $_SESSION['rolle']    = $user['rolle'];

    logAudit('LOGIN', 'users', $user['id'], 'Erfolgreicher Login');

    return true;
}

/**
 * Benutzer ausloggen
 */
function logoutUser(): void {
    if (isLoggedIn()) {
        logAudit('LOGOUT', 'users', $_SESSION['user_id'], 'Logout');
    }
    session_unset();
    session_destroy();
    session_start();
    session_regenerate_id(true);
    setcookie(session_name(), '', time() - 3600, '/');
    redirect('/pages/login.php');
}

/**
 * Neuen Benutzer registrieren
 * Gibt true bei Erfolg, sonst Fehlermeldung-Array
 */
function registerUser(array $data): true|array {
    $errors = [];

    $vorname     = trim($data['vorname'] ?? '');
    $nachname    = trim($data['nachname'] ?? '');
    $email       = strtolower(trim($data['email'] ?? ''));
    $passwort    = $data['passwort'] ?? '';
    $passwort2   = $data['passwort2'] ?? '';
    $zahlungsart = $data['zahlungsart'] ?? '';
    $adresse     = trim($data['adresse'] ?? '');

    // Validierungen
    if (strlen($vorname) < 2)  $errors[] = 'Vorname muss mindestens 2 Zeichen lang sein.';
    if (strlen($nachname) < 2) $errors[] = 'Nachname muss mindestens 2 Zeichen lang sein.';
    if (!validateEmail($email))  $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
    if (!validatePassword($passwort)) $errors[] = 'Passwort muss mindestens 8 Zeichen lang sein.';
    if ($passwort !== $passwort2) $errors[] = 'Die Passwörter stimmen nicht überein.';
    if (!in_array($zahlungsart, ['bar', 'ueberweisung', 'paypal'], true)) {
        $errors[] = 'Bitte wählen Sie eine gültige Zahlungsart.';
    }

    if (!empty($errors)) return $errors;

    $pdo = getDB();

    // E-Mail Eindeutigkeit prüfen
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['Diese E-Mail-Adresse ist bereits registriert.'];
    }

    // Benutzer anlegen
    $stmt = $pdo->prepare(
        'INSERT INTO users (vorname, nachname, email, passwort, zahlungsart, adresse)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $vorname,
        $nachname,
        $email,
        hashPassword($passwort),
        $zahlungsart,
        $adresse ?: null,
    ]);

    $userId = (int)$pdo->lastInsertId();
    logAudit('REGISTRIERUNG', 'users', $userId, "Neuer Benutzer: {$email}");

    return true;
}

// Direkt aufgerufen: Logout-Aktion (/includes/auth.php?action=logout)
if (basename($_SERVER['PHP_SELF']) === 'auth.php' && ($_GET['action'] ?? '') === 'logout') {
    logoutUser();
}

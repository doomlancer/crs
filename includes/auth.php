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
/**
 * Bcrypt-Hash eines Zufallswerts, gegen den kein Passwort verifiziert.
 * Wird für unbekannte E-Mail-Adressen geprüft, damit die Antwortzeit nicht
 * verrät, ob ein Konto existiert (siehe Kommentar in loginUser()).
 */
const DUMMY_PASSWORT_HASH = '$2y$12$YYxbEGqnIcxNMfW0yXJgv.ZVczkYCvymeRkMXlPTbpK3Rlqnun7CS';

function loginUser(string $email, string $passwort): bool|string {
    $pdo   = getDB();
    $email = strtolower(trim($email));
    $ip    = getClientIP();

    // Kontounabhängige Bremse. Der Zähler in users.login_versuche greift nur je
    // Konto – wer je Konto nur einen Versuch macht (Password-Spraying), löst ihn
    // nie aus. Diese Sperre zählt stattdessen pro Herkunftsadresse.
    if (rateLimitExceeded('login', $ip, 15, 900)) {
        return 'Zu viele Anmeldeversuche. Bitte in einigen Minuten erneut versuchen.';
    }

    $stmt = $pdo->prepare(
        'SELECT id, vorname, nachname, email, passwort, rolle, aktiv,
                login_versuche, gesperrt_bis, passwort_geaendert_am
         FROM users WHERE email = ?'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Die Passwortprüfung läuft IMMER, auch für unbekannte Adressen. Sonst
    // verrät schon die Antwortzeit, ob ein Konto existiert: bcrypt mit
    // Kostenfaktor 12 braucht rund 250 ms, ein erfolgloser SELECT keine 2 ms.
    // Eine neutrale Fehlermeldung allein nützt gegen dieses Orakel nichts.
    $passwortKorrekt = verifyPassword($passwort, $user['passwort'] ?? DUMMY_PASSWORT_HASH);

    if (!$user || !$passwortKorrekt) {
        rateLimitHit('login', $ip);

        if ($user) {
            $versuche = (int)$user['login_versuche'] + 1;
            // Abgelaufene Sperre setzt den Zähler zurück. Ohne das bliebe er
            // nach der ersten Sperre dauerhaft am Anschlag, und ein Angreifer
            // könnte das Konto mit einem Request alle 15 Minuten permanent
            // gesperrt halten.
            if (!empty($user['gesperrt_bis']) && strtotime($user['gesperrt_bis']) <= time()) {
                $versuche = 1;
            }
            if ($versuche >= MAX_LOGIN_VERSUCHE) {
                $pdo->prepare('UPDATE users SET login_versuche = ?, gesperrt_bis = ? WHERE id = ?')
                    ->execute([$versuche, date('Y-m-d H:i:s', time() + LOGIN_SPERRZEIT), $user['id']]);
            } else {
                $pdo->prepare('UPDATE users SET login_versuche = ? WHERE id = ?')
                    ->execute([$versuche, $user['id']]);
            }
        }

        // Eine einzige Meldung für alle Fehlerfälle – kein Hinweis darauf, ob
        // die Adresse registriert, gesperrt oder deaktiviert ist.
        return 'Ungültige E-Mail oder Passwort.';
    }

    // Ab hier ist das Passwort korrekt. Wer es kennt, weiß ohnehin, dass es das
    // Konto gibt – konkrete Hinweise verraten jetzt nichts mehr.
    if (!empty($user['gesperrt_bis']) && strtotime($user['gesperrt_bis']) > time()) {
        $minuten = (int)ceil((strtotime($user['gesperrt_bis']) - time()) / 60);
        return "Konto vorübergehend gesperrt. Bitte warten Sie noch {$minuten} Minute(n).";
    }
    if (!$user['aktiv']) {
        return 'Dieses Konto wurde deaktiviert. Bitte kontaktieren Sie den Administrator.';
    }

    // Erfolgreich: Fehlversuche zurücksetzen, Session setzen
    $pdo->prepare('UPDATE users SET login_versuche = 0, gesperrt_bis = NULL WHERE id = ?')
        ->execute([$user['id']]);

    // Session-ID erneuern (Session Fixation verhindern)
    session_regenerate_id(true);

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['vorname']   = $user['vorname'];
    $_SESSION['nachname']  = $user['nachname'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['rolle']     = $user['rolle'];
    // Stand des Passworts. Ändert es sich, werden alle anderen offenen
    // Sitzungen dieses Kontos beim nächsten Request verworfen.
    $_SESSION['pw_epoche'] = (string)($user['passwort_geaendert_am'] ?? '');

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
 * Einlass-Zugang per Kurzcode anmelden (kein eigenes Benutzerkonto nötig).
 * Codes sind kurz (z.B. 6-stellig) – die eigentliche Bremse gegen Erraten
 * ist die IP-basierte Rate-Begrenzung, nicht die Code-Länge selbst.
 * Gibt true bei Erfolg, sonst eine Fehlermeldung zurück.
 */
function loginEinlass(string $code): bool|string {
    $code = trim($code);
    $ip   = getClientIP();

    if ($code === '') {
        return 'Bitte einen Zugangscode eingeben.';
    }
    if (rateLimitExceeded('einlass_login', $ip, 15, 900)) {
        return 'Zu viele Anmeldeversuche. Bitte in einigen Minuten erneut versuchen.';
    }

    $pdo = getDB();
    try {
        $rows = $pdo->query(
            'SELECT id, event_id, code_hash, label FROM einlass_zugaenge WHERE aktiv = 1'
        )->fetchAll();
    } catch (PDOException $e) {
        return 'Einlass-Zugang ist auf diesem System noch nicht eingerichtet.';
    }

    $match = null;
    foreach ($rows as $row) {
        if (verifyPassword($code, $row['code_hash'])) {
            $match = $row;
            break;
        }
    }

    if ($match === null) {
        rateLimitHit('einlass_login', $ip);
        return 'Ungültiger Zugangscode.';
    }

    session_regenerate_id(true);
    // Falls dieselbe Sitzung zuvor ein normales Konto war: sauber trennen,
    // damit hasRole() eindeutig einer Identität zugeordnet werden kann.
    unset($_SESSION['user_id'], $_SESSION['vorname'], $_SESSION['nachname'],
          $_SESSION['email'], $_SESSION['pw_epoche']);

    $_SESSION['einlass_id']       = (int)$match['id'];
    $_SESSION['einlass_event_id'] = (int)$match['event_id'];
    $_SESSION['einlass_label']    = $match['label'];
    $_SESSION['rolle']            = 'einlass';

    $pdo->prepare('UPDATE einlass_zugaenge SET zuletzt_benutzt_am = NOW() WHERE id = ?')
        ->execute([$match['id']]);

    logAudit('EINLASS_LOGIN', 'einlass_zugaenge', (int)$match['id'], 'Login: ' . $match['label']);

    return true;
}

/**
 * Einlass-Zugang abmelden (Pendant zu logoutUser() für Kurzcode-Sitzungen).
 */
function logoutEinlass(): void {
    if (!empty($_SESSION['einlass_id'])) {
        logAudit('EINLASS_LOGOUT', 'einlass_zugaenge', (int)$_SESSION['einlass_id'],
            'Logout: ' . ($_SESSION['einlass_label'] ?? ''));
    }
    session_unset();
    session_destroy();
    session_start();
    session_regenerate_id(true);
    setcookie(session_name(), '', time() - 3600, '/');
    redirect('/pages/einlass_login.php');
}

/**
 * Neuen Benutzer registrieren
 * Gibt true bei Erfolg, sonst Fehlermeldung-Array
 */
function registerUser(array $data): bool|array {
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

    // Willkommens-E-Mail
    if (file_exists(__DIR__ . '/../includes/mailer.php')) {
        require_once __DIR__ . '/../includes/mailer.php';
        mailRegistrierungsbestaetigung($email, $vorname);
    }

    return true;
}

// Direkt aufgerufen: Logout-Aktion (/includes/auth.php?action=logout)
if (basename($_SERVER['PHP_SELF']) === 'auth.php' && ($_GET['action'] ?? '') === 'logout') {
    logoutUser();
}
// Direkt aufgerufen: Einlass-Logout (/includes/auth.php?action=einlass_logout)
if (basename($_SERVER['PHP_SELF']) === 'auth.php' && ($_GET['action'] ?? '') === 'einlass_logout') {
    logoutEinlass();
}

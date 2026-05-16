<?php
/**
 * Konfigurationsdatei - Datenbank & Systemkonstanten
 */

// Fehler nur in Entwicklung anzeigen; in Produktion auf false setzen
define('DEBUG_MODE', false);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/logs/error.log');
}

// Datenbank-Konfiguration
define('DB_HOST', 'localhost');
define('DB_NAME', 'karneval_db');
define('DB_USER', 'karneval_user');
define('DB_PASS', 'StrongPassword123!');
define('DB_CHARSET', 'utf8mb4');

// Anwendungs-Einstellungen
define('APP_NAME', 'Karneval Reservierungssystem');
define('APP_URL', 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
define('SESSION_TIMEOUT', 1800); // 30 Minuten in Sekunden
define('MAX_LOGIN_VERSUCHE', 5);
define('LOGIN_SPERRZEIT', 900); // 15 Minuten in Sekunden

// Ticket-Preis
define('TICKET_PREIS', 15.00);

// Upload-Verzeichnis
define('UPLOAD_DIR', __DIR__ . '/uploads/');

// Log-Verzeichnis erstellen falls nicht vorhanden
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0750, true);
}

/**
 * Datenbankverbindung als Singleton
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_FOUND_ROWS   => true,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
            die(json_encode(['error' => 'Datenbankfehler. Bitte später erneut versuchen.']));
        }
    }
    return $pdo;
}

// HTTPS erzwingen (Produktion)
if (!DEBUG_MODE && (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on')) {
    $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Location: ' . $redirect, true, 301);
    exit;
}

// Session-Konfiguration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', DEBUG_MODE ? 0 : 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session-Timeout prüfen
if (isset($_SESSION['letzte_aktivitaet'])) {
    if (time() - $_SESSION['letzte_aktivitaet'] > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['timeout_message'] = 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.';
    }
}
$_SESSION['letzte_aktivitaet'] = time();

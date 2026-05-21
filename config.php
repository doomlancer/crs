<?php
/**
 * Konfigurationsdatei - Datenbank & Systemkonstanten
 * Kein Composer nötig – .env wird direkt eingelesen
 */

// .env einlesen (einfacher Parser, keine externe Bibliothek)
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\"'");
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $val;
        }
    }
}

// Fallbacks
$_ENV['DB_HOST']         ??= 'localhost';
$_ENV['DB_NAME']         ??= 'crs';
$_ENV['DB_USER']         ??= 'root';
$_ENV['DB_PASS']         ??= '';
$_ENV['DEBUG_MODE']      ??= 'false';
$_ENV['APP_NAME']        ??= 'Karneval Reservierungssystem';
$_ENV['APP_URL']         ??= 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$_ENV['TICKET_PREIS']    ??= '15.00';
$_ENV['SMTP_USER']       ??= '';
$_ENV['SMTP_FROM_NAME']  ??= 'Karneval Reservierung';

define('DEBUG_MODE', filter_var($_ENV['DEBUG_MODE'], FILTER_VALIDATE_BOOLEAN));

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/logs/error.log');
}

define('DB_HOST',    $_ENV['DB_HOST']);
define('DB_NAME',    $_ENV['DB_NAME']);
define('DB_USER',    $_ENV['DB_USER']);
define('DB_PASS',    $_ENV['DB_PASS']);
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME',           $_ENV['APP_NAME']);
define('APP_URL',            $_ENV['APP_URL']);
define('SESSION_TIMEOUT',    1800);
define('MAX_LOGIN_VERSUCHE', 5);
define('LOGIN_SPERRZEIT',    900);
define('TICKET_PREIS',       (float)$_ENV['TICKET_PREIS']);
define('UPLOAD_DIR',         __DIR__ . '/uploads/');

if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0750, true);
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_FOUND_ROWS   => true,
            ]);
        } catch (PDOException $e) {
            error_log('DB-Verbindung fehlgeschlagen: ' . $e->getMessage());
            die(json_encode(['error' => 'Datenbankfehler. Bitte später erneut versuchen.']));
        }
    }
    return $pdo;
}

if (!DEBUG_MODE && (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on')) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', DEBUG_MODE ? 0 : 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['letzte_aktivitaet'])) {
    if (time() - $_SESSION['letzte_aktivitaet'] > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['timeout_message'] = 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.';
    }
}
$_SESSION['letzte_aktivitaet'] = time();

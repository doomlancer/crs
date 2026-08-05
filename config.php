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
        $val = trim($val, " \t\r\n\"'");
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $val;
        }
    }
}

// Container-/System-Umgebungsvariablen übernehmen (Docker reicht Werte per
// environment: durch). getenv() funktioniert unabhängig von variables_order.
foreach ([
    'DB_HOST','DB_NAME','DB_USER','DB_PASS','DEBUG_MODE','APP_NAME','APP_URL',
    'TICKET_PREIS','FORCE_HTTPS','PAYPAL_EMAIL','PAYPAL_SANDBOX',
    'SMTP_HOST','SMTP_PORT','SMTP_USER','SMTP_PASS','SMTP_FROM_NAME',
] as $__k) {
    $__v = getenv($__k);
    if ($__v !== false && !isset($_ENV[$__k])) {
        $_ENV[$__k] = $__v;
    }
}
unset($__k, $__v);

// Fallbacks
$_ENV['DB_HOST']         ??= 'localhost';
$_ENV['DB_NAME']         ??= 'crs';
$_ENV['DB_USER']         ??= 'root';
$_ENV['DB_PASS']         ??= '';
$_ENV['DEBUG_MODE']      ??= 'false';
$_ENV['APP_NAME']        ??= 'Kameruner-Tickets';
$_ENV['APP_URL']         ??= 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$_ENV['TICKET_PREIS']    ??= '15.00';
$_ENV['SMTP_USER']       ??= '';
$_ENV['SMTP_FROM_NAME']  ??= 'Kameruner-Tickets';

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

// Effektives Protokoll ermitteln – erkennt auch TLS-Terminierung durch einen
// Reverse-Proxy (SWAG / Nginx Proxy Manager / Cloudflare / Traefik).
$isHttps = (
       (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['HTTP_X_FORWARDED_SSL']   ?? '') === 'on')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
);

// FORCE_HTTPS=false erlaubt reinen HTTP-Betrieb (lokaler Direktzugriff ohne Proxy).
$forceHttps = filter_var($_ENV['FORCE_HTTPS'] ?? 'true', FILTER_VALIDATE_BOOLEAN);

if (!DEBUG_MODE && $forceHttps && !$isHttps) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}

ini_set('session.cookie_httponly', 1);
// Secure-Cookie nur wenn die Verbindung tatsächlich HTTPS ist – sonst käme
// über reines HTTP kein Session-Cookie an und der Login würde scheitern.
ini_set('session.cookie_secure', $isHttps ? 1 : 0);
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

// ─────────────────────────────────────────────────────────────────────────
// Content-Security-Policy mit Nonce
// Wird hier (statt in .htaccess) gesetzt, damit der Nonce pro Request passt.
// Inline-<script>-Blöcke bekommen den Nonce in footer.php/header.php injiziert.
// ─────────────────────────────────────────────────────────────────────────
define('CSP_NONCE', bin2hex(random_bytes(16)));

if (!headers_sent()) {
    $cspSelf = "'self'";
    $csp = [
        "default-src {$cspSelf}",
        "script-src {$cspSelf} 'nonce-" . CSP_NONCE . "'",
        // style-src: 'unsafe-inline' ist nötig – die Views nutzen dynamische
        // style="width:X%"-Attribute (Fortschrittsbalken u.ä.). Inline-Styles
        // sind deutlich ungefährlicher als Inline-Skripte.
        "style-src {$cspSelf} 'unsafe-inline'",
        "font-src {$cspSelf}",
        "img-src {$cspSelf} data:",
        "connect-src {$cspSelf}",
        "frame-ancestors 'none'",
        "base-uri {$cspSelf}",
        "form-action {$cspSelf} https://www.paypal.com https://www.sandbox.paypal.com",
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));
}

/**
 * Fügt allen <script>-Tags in einem HTML-Schnipsel den CSP-Nonce hinzu.
 * Damit funktionieren die $extraScripts-Blöcke aller Seiten ohne 'unsafe-inline'.
 */
function withCspNonce(string $html): string {
    return preg_replace('/<script(?![^>]*\bnonce=)/i', '<script nonce="' . CSP_NONCE . '"', $html);
}

define('PAYPAL_EMAIL',   $_ENV['PAYPAL_EMAIL']   ?? 'marc.gunit@gmail.com');
define('PAYPAL_SANDBOX', (bool)($_ENV['PAYPAL_SANDBOX'] ?? false));

// ─────────────────────────────────────────────────────────────────────────
// Geheimnis zum Signieren der Ticket-QR-Codes (HMAC).
// Ohne gültige Signatur wird ein Ticket beim Check-in abgelehnt – damit sind
// erfundene oder abgeänderte Buchungsnummern wertlos.
// Wird kein Wert vorgegeben, erzeugt die App einmalig einen und legt ihn
// unter uploads/.ticket_secret ab (außerhalb des Web-Zugriffs gesperrt).
// ─────────────────────────────────────────────────────────────────────────
if (!empty($_ENV['TICKET_SECRET'])) {
    define('TICKET_SECRET', $_ENV['TICKET_SECRET']);
} else {
    $__secretFile = __DIR__ . '/uploads/.ticket_secret';
    if (is_readable($__secretFile)) {
        define('TICKET_SECRET', trim((string)file_get_contents($__secretFile)));
    } else {
        $__generated = bin2hex(random_bytes(32));
        if (!is_dir(__DIR__ . '/uploads')) {
            @mkdir(__DIR__ . '/uploads', 0755, true);
        }
        if (@file_put_contents($__secretFile, $__generated) !== false) {
            @chmod($__secretFile, 0600);
        }
        define('TICKET_SECRET', $__generated);
        unset($__generated);
    }
    unset($__secretFile);
}

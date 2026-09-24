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
    'TICKET_SECRET','TICKET_SECRET_DIR','TRUSTED_PROXIES',
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
    // E_ALL statt 0: error_reporting(0) unterdrückt nicht nur die Anzeige,
    // sondern auch das Schreiben ins Log. Ein Absturz am Veranstaltungsabend
    // hinterließe dann weder auf dem Bildschirm noch in logs/error.log eine
    // Spur. Angezeigt wird durch display_errors=0 weiterhin nichts.
    error_reporting(E_ALL);
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
// Obergrenze für Plätze pro Tisch. War früher hart bei 20 (nur im HTML-Attribut,
// kein Server-Check) – Säle mit größeren Tischen (bis 28 Plätze) ließen sich
// damit gar nicht abbilden.
define('MAX_PLAETZE_PRO_TISCH', 40);

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

/**
 * Prüft, ob eine IP-Adresse in einer Liste von Einzel-IPs/CIDR-Bereichen liegt.
 * Eigenständig statt aus functions.php importiert, weil config.php vor
 * functions.php geladen wird.
 */
function crsIpIsTrusted(string $ip, array $trusted): bool {
    $ipBin = @inet_pton($ip);
    if ($ipBin === false) return false;
    foreach ($trusted as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        if (!str_contains($entry, '/')) {
            if (@inet_pton($entry) === $ipBin) return true;
            continue;
        }
        [$subnet, $bits] = explode('/', $entry, 2);
        $subBin = @inet_pton($subnet);
        $bits   = (int)$bits;
        if ($subBin === false || strlen($subBin) !== strlen($ipBin)) continue; // kein Mix v4/v6
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) continue;
        $remBits = $bits % 8;
        if ($remBits === 0) return true;
        $mask = chr((0xFF << (8 - $remBits)) & 0xFF);
        if ((substr($ipBin, $bytes, 1) & $mask) === (substr($subBin, $bytes, 1) & $mask)) return true;
    }
    return false;
}

// Effektives Protokoll ermitteln – erkennt auch TLS-Terminierung durch einen
// Reverse-Proxy (SWAG / Nginx Proxy Manager / Cloudflare / Traefik).
//
// X-Forwarded-Proto/-Ssl werden NUR akzeptiert, wenn die Anfrage nachweislich
// von einem als vertrauenswürdig konfigurierten Proxy kommt (TRUSTED_PROXIES
// in .env, kommagetrennte IPs/CIDR-Bereiche). Ohne diese Prüfung könnte jeder
// Client, der den App-Container direkt erreicht – z. B. jeder im selben LAN,
// sobald FORCE_HTTPS=true gesetzt wird – diesen Header selbst mitschicken und
// damit session.cookie_secure fälschlich als "gesichert" erscheinen lassen.
// Ohne konfigurierten Proxy (Standard bei direktem Port-Mapping) wird der
// Header schlicht ignoriert.
$trustedProxies   = array_filter(array_map('trim', explode(',', $_ENV['TRUSTED_PROXIES'] ?? '')));
$fromTrustedProxy = $trustedProxies !== [] && crsIpIsTrusted($_SERVER['REMOTE_ADDR'] ?? '', $trustedProxies);

$isHttps = (
       (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    || ($fromTrustedProxy && (
           ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || ($_SERVER['HTTP_X_FORWARDED_SSL']   ?? '') === 'on'
       ))
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

// Kein Vorgabewert: Ein fest einkompiliertes Empfängerkonto würde Zahlungen
// stillschweigend auf ein fremdes Konto leiten, wenn die Konfiguration fehlt.
// Ist der Wert leer, prüft api/paypal_ipn.php gar nicht erst weiter.
define('PAYPAL_EMAIL',   $_ENV['PAYPAL_EMAIL']   ?? '');
define('PAYPAL_SANDBOX', (bool)($_ENV['PAYPAL_SANDBOX'] ?? false));

// ─────────────────────────────────────────────────────────────────────────
// Geheimnis zum Signieren der Ticket-QR-Codes (HMAC).
// Ohne gültige Signatur wird ein Ticket beim Check-in abgelehnt – damit sind
// erfundene oder abgeänderte Buchungsnummern wertlos.
//
// Vorrang hat TICKET_SECRET aus der Umgebung (empfohlen, siehe .env.example).
// Fehlt der Wert, legt die App den Schlüssel in einem Verzeichnis OBERHALB der
// DocumentRoot ab. Er darf NICHT unter uploads/ liegen: dieses Verzeichnis
// wird vom Webserver ausgeliefert, ein dort abgelegter Schlüssel wäre per HTTP
// abrufbar – und wer ihn kennt, kann beliebige Tickets fälschen.
//
// Ein an der alten Stelle vorhandener Schlüssel wird übernommen und dort
// gelöscht. Er darf nicht einfach neu erzeugt werden, sonst würden alle
// bereits versendeten QR-Codes ungültig.
// ─────────────────────────────────────────────────────────────────────────
if (!empty($_ENV['TICKET_SECRET'])) {
    define('TICKET_SECRET', $_ENV['TICKET_SECRET']);
} else {
    $__secretDir  = $_ENV['TICKET_SECRET_DIR'] ?? dirname(__DIR__) . '/crs-secrets';
    $__secretFile = $__secretDir . '/ticket_secret';
    $__legacyFile = __DIR__ . '/uploads/.ticket_secret';
    $__secret     = '';

    if (is_readable($__secretFile)) {
        $__secret = trim((string)file_get_contents($__secretFile));
    }
    if ($__secret === '' && is_readable($__legacyFile)) {
        $__secret = trim((string)file_get_contents($__legacyFile));   // Altbestand
    }
    if ($__secret === '') {
        $__secret = bin2hex(random_bytes(32));
    }

    // An den geschützten Ort schreiben und die exponierte Kopie entfernen.
    if (!is_readable($__secretFile)) {
        if (!is_dir($__secretDir)) {
            @mkdir($__secretDir, 0700, true);
        }
        if (@file_put_contents($__secretFile, $__secret) !== false) {
            @chmod($__secretFile, 0600);
            @unlink($__legacyFile);
        } elseif (!is_readable($__legacyFile)) {
            // Letzter Ausweg: Der Schlüssel muss dauerhaft ablegbar sein, sonst
            // entstünde bei jedem Request ein neuer und kein Ticket wäre mehr
            // einlösbar. uploads/ ist per .htaccess gegen Punktdateien gesperrt.
            @file_put_contents($__legacyFile, $__secret);
            @chmod($__legacyFile, 0600);
            error_log('CRS: Ticket-Schlüssel konnte nicht in ' . $__secretDir
                . ' abgelegt werden. Bitte TICKET_SECRET als Umgebungsvariable setzen.');
        }
    }

    define('TICKET_SECRET', $__secret);
    unset($__secretDir, $__secretFile, $__legacyFile, $__secret);
}

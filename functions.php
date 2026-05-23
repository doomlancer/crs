<?php
/**
 * Hilfsfunktionen für Kameruner-Tickets
 */

require_once __DIR__ . '/config.php';

// =====================
// Internationalisierung (i18n)
// =====================

/**
 * Aktuelle Sprache ermitteln (Session > Browser > Fallback 'de')
 */
function getCurrentLang(): string {
    $allowed = ['de', 'en'];
    if (!empty($_SESSION['lang']) && in_array($_SESSION['lang'], $allowed, true)) {
        return $_SESSION['lang'];
    }
    // Browser-Sprache als Hint
    $acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'de';
    return str_starts_with(strtolower($acceptLang), 'en') ? 'en' : 'de';
}

/**
 * Sprache in Session setzen
 */
function setLang(string $lang): void {
    $allowed = ['de', 'en'];
    if (in_array($lang, $allowed, true)) {
        $_SESSION['lang'] = $lang;
    }
}

/**
 * Übersetzung abrufen
 * Unterstützt printf-Platzhalter: __('key', 'Wert')
 */
function __(string $key, string ...$args): string {
    static $translations = null;
    if ($translations === null) {
        $lang = getCurrentLang();
        $file = __DIR__ . "/lang/{$lang}.php";
        if (!file_exists($file)) {
            $file = __DIR__ . '/lang/de.php';
        }
        $translations = file_exists($file) ? require $file : [];
    }
    $text = $translations[$key] ?? $key;
    return empty($args) ? $text : vsprintf($text, $args);
}

// =====================
// Sicherheits-Funktionen
// =====================

/**
 * CSRF-Token generieren und in Session speichern
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * CSRF-Token validieren
 */
function validateCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * CSRF-Token als verstecktes Formularfeld ausgeben
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken()) . '">';
}

/**
 * Eingabe bereinigen und validieren
 */
function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * E-Mail validieren
 */
function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Passwort-Anforderungen prüfen
 */
function validatePassword(string $password): bool {
    return strlen($password) >= 8;
}

/**
 * Passwort hashen mit bcrypt
 */
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Passwort verifizieren
 */
function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

/**
 * IP-Adresse des Benutzers ermitteln
 */
function getClientIP(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return '0.0.0.0';
}

// =====================
// Authentifizierungs-Funktionen
// =====================

/**
 * Prüft ob der Benutzer eingeloggt ist
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Prüft ob der Benutzer eine bestimmte Rolle hat
 */
function hasRole(string ...$roles): bool {
    if (!isLoggedIn()) return false;
    return in_array($_SESSION['rolle'] ?? '', $roles, true);
}

/**
 * Weiterleitung wenn nicht eingeloggt
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        redirect('/pages/login.php');
    }
}

/**
 * Weiterleitung wenn nicht die richtige Rolle
 */
function requireRole(string ...$roles): void {
    requireLogin();
    if (!hasRole(...$roles)) {
        http_response_code(403);
        include __DIR__ . '/pages/error_403.php';
        exit;
    }
}

/**
 * Aktuellen Benutzer aus der DB laden
 */
function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $user = null;
    if ($user === null) {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT id, vorname, nachname, email, zahlungsart, adresse, rolle, aktiv FROM users WHERE id = ? AND aktiv = 1');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

// =====================
// Buchungsnummer
// =====================

/**
 * Eindeutige Buchungsnummer generieren (Format: KARN-YYYY-XXXXXX)
 */
function generateBuchungsnummer(): string {
    $pdo = getDB();
    do {
        $year = date('Y');
        $random = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $nummer = "KARN-{$year}-{$random}";
        $stmt = $pdo->prepare('SELECT id FROM reservations WHERE buchungsnummer = ?');
        $stmt->execute([$nummer]);
    } while ($stmt->fetch());
    return $nummer;
}

// =====================
// Audit-Log
// =====================

/**
 * Aktion im Audit-Log speichern
 */
function logAudit(string $aktion, string $tabelle, ?int $datensatzId = null, ?string $aenderung = null): void {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (user_id, aktion, tabelle, datensatz_id, aenderung, ip_adresse) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $aktion,
            $tabelle,
            $datensatzId,
            $aenderung,
            getClientIP()
        ]);
    } catch (PDOException $e) {
        error_log('Audit-Log Fehler: ' . $e->getMessage());
    }
}

// =====================
// Redirect & Nachrichten
// =====================

/**
 * Weiterleitung zu einer URL
 */
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/**
 * Flash-Nachricht setzen
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Flash-Nachricht ausgeben und löschen
 */
function getFlash(): string {
    if (empty($_SESSION['flash'])) return '';
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $type = $flash['type'] === 'error' ? 'danger' : htmlspecialchars($flash['type']);
    $msg  = htmlspecialchars($flash['message']);
    return "<div class=\"alert alert-{$type} alert-dismissible\" role=\"alert\">
                {$msg}
                <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\"></button>
            </div>";
}

// =====================
// Statistik-Hilfsfunktionen
// =====================

/**
 * Auslastung eines Events in Prozent berechnen
 */
function getEventAuslastung(int $eventId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT
            COUNT(s.id) AS gesamt,
            SUM(CASE WHEN s.status != "verfuegbar" THEN 1 ELSE 0 END) AS belegt
         FROM seats s
         INNER JOIN tables t ON s.table_id = t.id
         WHERE t.event_id = ?'
    );
    $stmt->execute([$eventId]);
    $result = $stmt->fetch();
    $gesamt = (int)($result['gesamt'] ?? 0);
    $belegt = (int)($result['belegt'] ?? 0);
    $prozent = $gesamt > 0 ? round(($belegt / $gesamt) * 100) : 0;
    return [
        'gesamt'  => $gesamt,
        'belegt'  => $belegt,
        'frei'    => $gesamt - $belegt,
        'prozent' => $prozent,
    ];
}

/**
 * Zahlungsart-Label ausgeben
 */
function zahlungsartLabel(string $art): string {
    return match($art) {
        'bar'          => 'Bar',
        'ueberweisung' => 'Überweisung',
        'paypal'       => 'PayPal',
        default        => ucfirst($art),
    };
}

/**
 * Status-Badge HTML ausgeben
 */
function statusBadge(string $status): string {
    $map = [
        'geplant'      => ['secondary', 'Geplant'],
        'eingecheckt'  => ['success',   'Eingecheckt'],
        'abgerechnet'  => ['primary',   'Abgerechnet'],
        'verfuegbar'   => ['success',   'Verfügbar'],
        'reserviert'   => ['warning',   'Reserviert'],
        'besetzt'      => ['danger',    'Besetzt'],
        'offen'        => ['warning',   'Offen'],
        'bezahlt'      => ['success',   'Bezahlt'],
        'storniert'    => ['danger',    'Storniert'],
        'planung'      => ['info',      'In Planung'],
        'aktiv'        => ['success',   'Aktiv'],
    ];
    [$color, $label] = $map[$status] ?? ['secondary', ucfirst($status)];
    return "<span class=\"badge bg-{$color}\">" . htmlspecialchars($label) . "</span>";
}

/**
 * Datum deutsch formatieren
 */
function formatDatum(string $datum): string {
    $ts = strtotime($datum);
    return $ts ? date('d.m.Y', $ts) : $datum;
}

/**
 * Betrag als Euro formatieren
 */
function formatBetrag(float $betrag): string {
    return number_format($betrag, 2, ',', '.') . ' €';
}

/**
 * JSON-Response senden (für API-Endpunkte)
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Prüft ob ein AJAX-Request vorliegt
 */
function isAjax(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// =====================
// E-Mail-Hilfsfunktionen (Wrapper – Implementierung in includes/mailer.php)
// =====================

/**
 * Passwort-Reset-E-Mail senden
 */
function sendPasswordResetEmail(string $email, string $vorname, string $resetUrl): bool {
    if (file_exists(__DIR__ . '/includes/mailer.php')) {
        require_once __DIR__ . '/includes/mailer.php';
        return mailPasswordReset($email, $vorname, $resetUrl);
    }
    // Fallback: PHP mail() wenn kein PHPMailer vorhanden
    $subject = htmlspecialchars(APP_NAME) . ' – Passwort zurücksetzen';
    $body    = "Hallo {$vorname},\n\nbitte klicken Sie auf folgenden Link, um Ihr Passwort zurückzusetzen:\n{$resetUrl}\n\nDer Link ist 1 Stunde gültig.\n\nWenn Sie keinen Reset angefordert haben, ignorieren Sie diese E-Mail.";
    return mail($email, $subject, $body, 'From: ' . ($_ENV['SMTP_USER'] ?? 'noreply@localhost'));
}

/**
 * Reservierungsbestätigung senden
 */
function sendReservierungsbestaetigung(string $email, string $vorname, array $buchungen, string $eventName, string $eventDatum): bool {
    if (file_exists(__DIR__ . '/includes/mailer.php')) {
        require_once __DIR__ . '/includes/mailer.php';
        return mailReservierungsbestaetigung($email, $vorname, $buchungen, $eventName, $eventDatum);
    }
    return false;
}

/**
 * Stornierungsbestätigung senden
 */
function sendStornierungsbestaetigung(string $email, string $vorname, string $buchungsnummer, string $eventName): bool {
    if (file_exists(__DIR__ . '/includes/mailer.php')) {
        require_once __DIR__ . '/includes/mailer.php';
        return mailStornierungsbestaetigung($email, $vorname, $buchungsnummer, $eventName);
    }
    return false;
}

/**
 * Wartelisten-Benachrichtigung senden (Platz frei geworden)
 */
function sendWaitlistNotification(string $email, string $vorname, string $eventName, string $tischUrl): bool {
    if (file_exists(__DIR__ . '/includes/mailer.php')) {
        require_once __DIR__ . '/includes/mailer.php';
        return mailWaitlistNotification($email, $vorname, $eventName, $tischUrl);
    }
    $subject = htmlspecialchars(APP_NAME) . ' – Ein Platz ist frei geworden!';
    $body    = "Hallo {$vorname},\n\nein Platz für das Event \"{$eventName}\" ist frei geworden!\nBitte reservieren Sie jetzt: {$tischUrl}\n\nViele Grüße\nIhr " . APP_NAME;
    return mail($email, $subject, $body, 'From: ' . ($_ENV['SMTP_USER'] ?? 'noreply@localhost'));
}

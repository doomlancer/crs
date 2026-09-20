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
 * Alle Admin-Überschreibungen aus translation_overrides laden (Migration 013).
 * Eine Abfrage pro Request, nur falls __() tatsächlich aufgerufen wird.
 * Rückgabe: ['bereich.zweck' => ['de' => ..., 'en' => ...], ...]
 */
function loadTranslationOverrides(): array {
    try {
        $rows = getDB()->query('SELECT lang_key, de, en FROM translation_overrides')->fetchAll();
    } catch (PDOException $e) {
        // Tabelle fehlt (Migration noch nicht gelaufen) oder DB-Störung:
        // Sprachdateien bleiben die alleinige Quelle, nichts bricht.
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[$r['lang_key']] = ['de' => $r['de'], 'en' => $r['en']];
    }
    return $out;
}

/**
 * Übersetzung abrufen.
 * Auflösungskette: Admin-Überschreibung (translation_overrides) → Sprachdatei
 * (lang/de.php bzw. lang/en.php) → der Schlüssel selbst als letzter Fallback.
 * Unterstützt printf-Platzhalter: __('key', 'Wert') via vsprintf — Reihenfolge
 * und Wortstellung bleiben dadurch pro Sprache frei änderbar.
 */
function __(string $key, string ...$args): string {
    static $translations = null;
    static $overrides = null;
    static $lang = null;

    if ($translations === null) {
        $lang = getCurrentLang();
        $file = __DIR__ . "/lang/{$lang}.php";
        if (!file_exists($file)) {
            $file = __DIR__ . '/lang/de.php';
        }
        $translations = file_exists($file) ? require $file : [];
        $overrides = loadTranslationOverrides();
    }

    $override = $overrides[$key][$lang] ?? null;
    $text = ($override !== null && $override !== '') ? $override : ($translations[$key] ?? $key);
    return empty($args) ? $text : vsprintf($text, $args);
}

/**
 * Alle bekannten Schlüssel aus beiden Sprachdateien, mit Vorgabetext,
 * aktuellem Override (falls vorhanden) und ob der Schlüssel im Code
 * überhaupt noch verwendet wird. Für die Admin-Übersetzungsverwaltung.
 *
 * @return array<string, array{de_default:string, en_default:string,
 *   de_override:?string, en_override:?string, used:bool}>
 */
function getTranslationCatalog(): array {
    $deFile = require __DIR__ . '/lang/de.php';
    $enFile = require __DIR__ . '/lang/en.php';
    $overrides = loadTranslationOverrides();

    $keys = array_unique(array_merge(array_keys($deFile), array_keys($enFile)));
    sort($keys);

    $usedKeys = scanUsedTranslationKeys();

    $catalog = [];
    foreach ($keys as $key) {
        $catalog[$key] = [
            'de_default'  => $deFile[$key] ?? '',
            'en_default'  => $enFile[$key] ?? '',
            'de_override' => $overrides[$key]['de'] ?? null,
            'en_override' => $overrides[$key]['en'] ?? null,
            'used'        => isset($usedKeys[$key]),
        ];
    }
    return $catalog;
}

/**
 * Durchsucht den Anwendungscode nach __('schluessel', ...)-Aufrufen.
 * Rein diagnostisch für die Admin-Oberfläche (Punkt A2: "welche Schlüssel
 * werden im Code gar nicht verwendet"), deshalb ein einfacher Regex-Scan
 * statt eines echten Parsers — reicht für den bestehenden Aufrufstil
 * __('key') / __("key"), läuft nur auf einer selten besuchten Admin-Seite.
 */
function scanUsedTranslationKeys(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $root  = __DIR__; // functions.php liegt im Projektwurzelverzeichnis
    $dirs  = [$root . '/pages', $root . '/includes', $root . '/api'];
    $files = [$root . '/functions.php', $root . '/index.php'];
    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            $files = array_merge($files, glob($dir . '/*.php') ?: []);
        }
    }

    $used = [];
    foreach ($files as $file) {
        $src = @file_get_contents($file);
        if ($src === false) continue;
        if (preg_match_all('/\b__\(\s*[\'"]([a-zA-Z0-9_.]+)[\'"]/', $src, $m)) {
            foreach ($m[1] as $key) {
                $used[$key] = true;
            }
        }
    }
    return $cache = $used;
}

/**
 * Setzt oder löscht die Admin-Überschreibung eines Schlüssels für eine Sprache.
 * Leerer String entfernt die Überschreibung (Zurücksetzen auf Vorgabe).
 */
function setTranslationOverride(string $key, string $de, string $en, int $userId): void {
    $de = trim($de);
    $en = trim($en);
    if ($de === '' && $en === '') {
        getDB()->prepare('DELETE FROM translation_overrides WHERE lang_key = ?')->execute([$key]);
        return;
    }
    getDB()->prepare(
        'INSERT INTO translation_overrides (lang_key, de, en, geaendert_von)
         VALUES (?, NULLIF(?, ""), NULLIF(?, ""), ?)
         ON DUPLICATE KEY UPDATE de = NULLIF(VALUES(de), ""), en = NULLIF(VALUES(en), ""),
                                  geaendert_von = VALUES(geaendert_von)'
    )->execute([$key, $de, $en, $userId]);
}

/** Setzt einen Schlüssel auf die Vorgabe aus der Sprachdatei zurück. */
function resetTranslationOverride(string $key): void {
    getDB()->prepare('DELETE FROM translation_overrides WHERE lang_key = ?')->execute([$key]);
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

/**
 * Baut ein LIKE-Suchmuster und entschärft die Platzhalterzeichen der Eingabe.
 *
 * Ohne dieses Escaping wirkt eine Eingabe von "%" als "alles anzeigen": Aus dem
 * Suchfeld der Ticket-Suche würde damit ein Knopf, der ohne Suchbegriff die
 * Buchungen des Events ausgibt. Verschachtelte Muster wie "%a%a%a%a%" erzeugen
 * außerdem teure Volltabellen-Scans auf ungeindizierten Textspalten.
 */
function likePattern(string $eingabe): string {
    // Der Backslash ist in MySQL das Standard-Escape-Zeichen für LIKE.
    return '%' . addcslashes($eingabe, '%_\\') . '%';
}

/**
 * Prüft ein Weiterleitungsziel und lässt ausschließlich seiteninterne Pfade zu.
 *
 * Ein Ziel muss mit genau einem "/" beginnen. Sowohl "//fremde-domain" als auch
 * "/\fremde-domain" sind protokollrelative Adressen und führen auf eine fremde
 * Domain – Browser normalisieren den Backslash dabei zu einem Slash. Genau
 * diese beiden Fälle hatten die bisherigen, jeweils eigenen Prüfungen in
 * set_lang.php und checkin_gast.php übersehen; update_payment.php prüfte gar
 * nicht. Deshalb steht die Logik jetzt an einer Stelle.
 */
function safeRedirectTarget(mixed $ziel, string $standard): string {
    if (!is_string($ziel) || $ziel === '') {
        return $standard;
    }
    if (str_contains($ziel, '..')) {
        return $standard;
    }
    // Erlaubt sind Pfad, Query und übliche Sonderzeichen – keine Steuerzeichen
    // (schützt zugleich vor Header-Injection) und kein zweites Trennzeichen
    // direkt nach dem führenden Slash.
    if (!preg_match('#^/(?![/\\\\])[A-Za-z0-9_\-/.?=&%]*$#', $ziel)) {
        return $standard;
    }
    return $ziel;
}

// =====================
// Rate-Limiting
// =====================

/**
 * Vermerkt einen Versuch (fehlgeschlagener Login, Reset-Anforderung, …).
 */
function rateLimitHit(string $aktion, string $schluessel): void {
    try {
        $pdo = getDB();
        $pdo->prepare('INSERT INTO rate_limits (aktion, schluessel) VALUES (?, ?)')
            ->execute([$aktion, mb_substr($schluessel, 0, 190)]);
        // Gelegentlich aufräumen, damit die Tabelle nicht unbegrenzt wächst.
        if (random_int(1, 50) === 1) {
            $pdo->exec('DELETE FROM rate_limits WHERE zeitpunkt < (NOW() - INTERVAL 1 DAY)');
        }
    } catch (Throwable $e) {
        error_log('Rate-Limit konnte nicht geschrieben werden: ' . $e->getMessage());
    }
}

/**
 * Prüft, ob für Aktion und Schlüssel im Zeitfenster zu viele Versuche liegen.
 *
 * Bei einem Datenbankfehler wird bewusst "nicht überschritten" gemeldet: eine
 * Störung darf niemanden aussperren, und die übrigen Prüfungen greifen weiter.
 */
function rateLimitExceeded(string $aktion, string $schluessel, int $max, int $fensterSekunden): bool {
    try {
        $stmt = getDB()->prepare(
            'SELECT COUNT(*) FROM rate_limits
             WHERE aktion = ? AND schluessel = ?
               AND zeitpunkt > (NOW() - INTERVAL ' . (int)$fensterSekunden . ' SECOND)'
        );
        $stmt->execute([$aktion, mb_substr($schluessel, 0, 190)]);
        return (int)$stmt->fetchColumn() >= $max;
    } catch (Throwable $e) {
        error_log('Rate-Limit-Prüfung fehlgeschlagen: ' . $e->getMessage());
        return false;
    }
}

// =====================
// Authentifizierungs-Funktionen
// =====================

/**
 * Prüft ob der Benutzer eingeloggt ist
 */
function isLoggedIn(): bool {
    return currentIdentity() !== null;
}

/**
 * Prüft ob der Benutzer eine bestimmte Rolle hat
 */
function hasRole(string ...$roles): bool {
    $user = currentIdentity();
    return $user !== null && in_array($user['rolle'], $roles, true);
}

/**
 * Lädt den angemeldeten Benutzer einmal pro Request aus der Datenbank.
 *
 * Rolle und Aktiv-Status stammten bisher ausschließlich aus der Session und
 * damit aus dem Zustand zum Zeitpunkt des Logins. Wurde jemandem die
 * Kassierer-Rolle entzogen, das Konto deaktiviert oder gelöscht, behielt eine
 * offene Sitzung sämtliche Rechte, solange sie am Leben gehalten wurde – und
 * das genügte, um weiterhin Gästelisten zu exportieren oder Check-ins
 * vorzunehmen. Der Abgleich hier macht solche Änderungen sofort wirksam.
 *
 * Das Ergebnis wird für die Dauer des Requests zwischengespeichert, es
 * entsteht also höchstens eine zusätzliche Abfrage pro Seitenaufruf.
 */
function currentIdentity(): ?array {
    static $geladen = false;
    static $user    = null;

    if ($geladen) return $user;
    $geladen = true;

    if (empty($_SESSION['user_id'])) return null;

    try {
        $stmt = getDB()->prepare(
            'SELECT id, vorname, nachname, email, zahlungsart, adresse, rolle, aktiv,
                    passwort_geaendert_am
             FROM users WHERE id = ?'
        );
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        // Datenbank nicht erreichbar: im Zweifel keine Rechte vergeben, die
        // Sitzung aber auch nicht zerstören (sonst würde eine kurze Störung
        // alle Nutzer abmelden).
        error_log('Identitätsprüfung fehlgeschlagen: ' . $e->getMessage());
        return null;
    }

    // Passwortwechsel entwertet alle anderen Sitzungen des Kontos. Genau darauf
    // verlässt sich jemand, der nach einem Verdacht sein Passwort ändert.
    // Sitzungen ohne gespeicherten Stand (aus der Zeit vor dieser Prüfung)
    // werden nicht abgewiesen, sondern nachgezogen.
    $pwEpocheAktuell = (string)($row['passwort_geaendert_am'] ?? '');
    $pwEpocheSession = $_SESSION['pw_epoche'] ?? null;
    $pwGeaendert     = $pwEpocheSession !== null && $pwEpocheSession !== $pwEpocheAktuell;

    if (!$row || (int)$row['aktiv'] !== 1 || $pwGeaendert) {
        // Konto gelöscht, deaktiviert oder Passwort geändert – Sitzung beenden.
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        return null;
    }

    $_SESSION['pw_epoche'] = $pwEpocheAktuell;

    // Rollenwechsel unmittelbar übernehmen.
    $_SESSION['rolle'] = $row['rolle'];

    return $user = $row;
}

/**
 * Weiterleitung wenn nicht eingeloggt
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        // REQUEST_URI stammt aus der Anfrage und wird nach dem Login als
        // Weiterleitungsziel verwendet – also hier schon einschränken.
        $_SESSION['redirect_after_login'] = safeRedirectTarget(
            $_SERVER['REQUEST_URI'] ?? null, '/pages/events.php'
        );
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
 * Aktuellen Benutzer aus der DB laden (Alias auf currentIdentity()).
 */
function getCurrentUser(): ?array {
    return currentIdentity();
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

    // Freiticket-Events haben keine Sitzplätze. Ohne diesen Zweig lieferte die
    // Funktion dort immer 0/0/0 %, und ein ausverkauftes Event erschien auf der
    // Startseite, der Eventübersicht, beiden Dashboards und beiden
    // Statistikseiten als vollständig leer.
    $stmtTyp = $pdo->prepare('SELECT event_typ, max_gaeste FROM events WHERE id = ?');
    $stmtTyp->execute([$eventId]);
    $ev = $stmtTyp->fetch();

    if (($ev['event_typ'] ?? 'tischplan') === 'freie_tickets') {
        $stmtVerk = $pdo->prepare(
            "SELECT COUNT(*) FROM reservations
             WHERE event_id = ? AND status NOT IN ('abgerechnet','storniert')"
        );
        $stmtVerk->execute([$eventId]);
        $belegt = (int)$stmtVerk->fetchColumn();
        // Ohne Kontingent gibt es keine sinnvolle Obergrenze – dann steht die
        // Zahl der verkauften Tickets für sich.
        $gesamt = $ev['max_gaeste'] !== null ? (int)$ev['max_gaeste'] : 0;
        return [
            'gesamt'  => $gesamt,
            'belegt'  => $belegt,
            'frei'    => $gesamt > 0 ? max(0, $gesamt - $belegt) : 0,
            'prozent' => $gesamt > 0 ? (int)round(($belegt / $gesamt) * 100) : 0,
        ];
    }

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
    // lang/*.php enthalten die Schlüssel payment.bar/ueberweisung/paypal
    // bereits (angelegt für genau diesen Zweck, bislang ungenutzt).
    $key = 'payment.' . $art;
    $text = __($key);
    return $text !== $key ? $text : ucfirst($art);
}

/**
 * Status-Badge HTML ausgeben
 */
function statusBadge(string $status): string {
    $colors = [
        'geplant'      => 'secondary',
        'eingecheckt'  => 'success',
        'abgerechnet'  => 'primary',
        'verfuegbar'   => 'success',
        'reserviert'   => 'warning',
        'besetzt'      => 'danger',
        'offen'        => 'warning',
        'bezahlt'      => 'success',
        'storniert'    => 'danger',
        'planung'      => 'info',
        'aktiv'        => 'success',
    ];
    $color = $colors[$status] ?? 'secondary';
    // lang/*.php enthalten die Schlüssel status.* bereits.
    $key   = 'status.' . $status;
    $text  = __($key);
    $label = $text !== $key ? $text : ucfirst($status);
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

// =====================
// Design-Einstellungen
// =====================

function getSetting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        try {
            $rows  = getDB()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
            $cache = array_column($rows, 'setting_value', 'setting_key');
        } catch (PDOException $e) {
            $cache = [];
        }
    }
    return (string)($cache[$key] ?? $default);
}

function setSetting(string $key, string $value): void {
    getDB()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
         ON DUPLICATE KEY UPDATE setting_value = ?'
    )->execute([$key, $value, $value]);
    // Cache is per-request; after save we always redirect, so stale cache is irrelevant.
}

function getAllSettings(): array {
    try {
        $rows = getDB()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        return array_column($rows, 'setting_value', 'setting_key');
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Stellt sicher, dass die Check-in-Spalten aus Migration 007 vorhanden sind.
 * Wird beim ersten Check-in-Zugriff aufgerufen, damit auf bestehenden
 * Installationen kein manueller Migrationsschritt nötig ist.
 */
function ensureCheckinColumns(): bool {
    static $done = null;
    if ($done !== null) return $done;

    $pdo = getDB();
    try {
        $pdo->query('SELECT eingecheckt_am FROM reservations LIMIT 1');
        return $done = true;
    } catch (PDOException $e) {
        try {
            $pdo->exec('ALTER TABLE reservations
                        ADD COLUMN eingecheckt_am DATETIME DEFAULT NULL,
                        ADD COLUMN eingecheckt_von INT DEFAULT NULL');
            try { $pdo->exec('ALTER TABLE reservations ADD INDEX idx_eingecheckt_am (eingecheckt_am)'); } catch (PDOException $i) {}
            try { $pdo->exec('ALTER TABLE reservations ADD INDEX idx_event_status (event_id, status)'); } catch (PDOException $i) {}
            return $done = true;
        } catch (PDOException $e2) {
            error_log('Check-in-Migration fehlgeschlagen: ' . $e2->getMessage());
            return $done = false;
        }
    }
}

function settingsTableExists(): bool {
    static $exists = null;
    if ($exists === null) {
        try {
            getDB()->query('SELECT 1 FROM settings LIMIT 1');
            $exists = true;
        } catch (PDOException $e) {
            $exists = false;
        }
    }
    return $exists;
}

// =====================
// Sicherer Bild-Upload
// =====================

/**
 * Nimmt eine hochgeladene Bilddatei entgegen und speichert sie sicher.
 *
 * Sicherheitsprinzip: Weder der vom Browser gemeldete MIME-Typ ($_FILES['type'])
 * noch die vom Client stammende Dateiendung werden ausgewertet – beide sind
 * frei fälschbar. Stattdessen entscheidet ausschließlich getimagesize(), das
 * den tatsächlichen Bildinhalt prüft. Die Endung wird aus dem erkannten Typ
 * abgeleitet, der Dateiname serverseitig neu vergeben.
 *
 * SVG ist bewusst NICHT erlaubt: SVG-Dateien können <script> enthalten und
 * würden beim direkten Aufruf als Stored XSS im eigenen Origin ausgeführt.
 *
 * @param array  $file      Eintrag aus $_FILES
 * @param string $prefix    Namenspräfix, z.B. 'logo'
 * @param int    $maxBytes  Maximale Dateigröße
 * @param array  $allowed   Erlaubte IMAGETYPE_*-Konstanten
 * @return array{ok:bool, name?:string, error?:string}
 */
function saveUploadedImage(
    array $file,
    string $prefix,
    int $maxBytes = 2097152,
    array $allowed = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP]
): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $msg = match ($file['error'] ?? -1) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu groß.',
            UPLOAD_ERR_NO_FILE                        => 'Bitte wählen Sie eine Datei aus.',
            UPLOAD_ERR_PARTIAL                        => 'Der Upload wurde abgebrochen.',
            default                                   => 'Upload fehlgeschlagen.',
        };
        return ['ok' => false, 'error' => $msg];
    }

    // Muss wirklich über HTTP hochgeladen worden sein (verhindert Pfad-Tricks)
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Ungültiger Upload.'];
    }

    if (($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'Datei zu groß (max. ' . round($maxBytes / 1048576, 1) . ' MB).'];
    }

    // Entscheidend: echter Bildinhalt statt Client-Angaben
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !isset($info[2])) {
        return ['ok' => false, 'error' => 'Die Datei ist kein gültiges Bild.'];
    }

    $type = $info[2];
    if (!in_array($type, $allowed, true)) {
        return ['ok' => false, 'error' => 'Dieses Bildformat wird nicht unterstützt.'];
    }

    // Endung aus dem ERKANNTEN Typ ableiten, nie aus dem Dateinamen
    $ext = match ($type) {
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_ICO  => 'ico',
        default        => null,
    };
    if ($ext === null) {
        return ['ok' => false, 'error' => 'Dieses Bildformat wird nicht unterstützt.'];
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        return ['ok' => false, 'error' => 'Upload-Verzeichnis nicht beschreibbar.'];
    }

    // Serverseitig vergebener Name – keine Client-Eingabe im Dateinamen
    $name = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $name)) {
        return ['ok' => false, 'error' => 'Datei konnte nicht gespeichert werden (Berechtigungen prüfen).'];
    }
    @chmod(UPLOAD_DIR . $name, 0644);

    return ['ok' => true, 'name' => $name];
}

/**
 * Löscht eine zuvor hochgeladene Datei sicher aus dem Upload-Verzeichnis.
 * Schützt gegen Path-Traversal, indem nur der Basename verwendet wird.
 */
function deleteUploadedFile(string $name): void {
    $name = basename($name);
    if ($name === '' || $name === '.' || $name === '..') return;
    $path = UPLOAD_DIR . $name;
    if (is_file($path)) {
        @unlink($path);
    }
}

// =====================
// Ticket-Signatur (Manipulationsschutz)
// =====================

/**
 * Erzeugt den signierten Inhalt für den Ticket-QR-Code.
 *
 * Format: "<buchungsnummer>.<signatur>"
 * Die Signatur ist ein gekürzter HMAC-SHA256 über die Buchungsnummer mit
 * TICKET_SECRET. Ohne Kenntnis des Geheimnisses lässt sich zu einer
 * beliebigen Buchungsnummer keine gültige Signatur erzeugen.
 */
function ticketPayload(string $buchungsnummer): string {
    return $buchungsnummer . '.' . ticketSignature($buchungsnummer);
}

/**
 * Signatur zu einer Buchungsnummer berechnen (16 Hex-Zeichen = 64 Bit).
 */
function ticketSignature(string $buchungsnummer): string {
    return substr(hash_hmac('sha256', $buchungsnummer, TICKET_SECRET), 0, 16);
}

/**
 * Prüft einen gescannten QR-Inhalt und gibt die Buchungsnummer zurück.
 * Liefert null, wenn Format oder Signatur ungültig sind.
 *
 * Der Vergleich nutzt hash_equals (laufzeitkonstant), um Timing-Angriffe
 * auf die Signatur auszuschließen.
 */
function verifyTicketPayload(string $payload): ?string {
    $payload = trim($payload);
    if (!str_contains($payload, '.')) {
        return null;
    }
    [$bn, $sig] = explode('.', $payload, 2);
    $bn  = trim($bn);
    $sig = trim($sig);
    if ($bn === '' || $sig === '') {
        return null;
    }
    if (!hash_equals(ticketSignature($bn), $sig)) {
        return null;
    }
    return $bn;
}

// =====================
// Check-in (zentral)
// =====================

/**
 * Checkt eine Reservierung ein. Einzige Stelle, an der ein Check-in passiert –
 * Dashboard, Gästeliste und Scanner rufen alle diese Funktion auf.
 *
 * Prüfungen:
 *  - Reservierung existiert
 *  - gehört zum erwarteten Event (verhindert Tickets aus anderen/alten Events)
 *  - ist noch nicht eingecheckt (verhindert Mehrfachnutzung)
 *  - ist nicht storniert/abgerechnet
 *
 * Läuft vollständig in einer Transaktion mit Zeilensperre (FOR UPDATE), damit
 * zwei gleichzeitige Scans desselben Tickets nicht beide durchgehen.
 *
 * @return array{ok:bool, code:string, message:string, data?:array}
 */
function checkinReservation(int $reservationId, ?int $expectedEventId = null): array {
    $pdo = getDB();
    ensureCheckinColumns();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT r.id, r.seat_id, r.status, r.buchungsnummer, r.event_id, r.eingecheckt_am,
                    u.vorname, u.nachname,
                    e.name AS event_name,
                    t.tischnummer, s.sitzplatznummer,
                    p.status AS zahl_status, p.betrag, p.zahlungsart
             FROM reservations r
             INNER JOIN users  u ON u.id = r.user_id
             INNER JOIN events e ON e.id = r.event_id
             LEFT  JOIN seats  s ON s.id = r.seat_id
             LEFT  JOIN tables t ON t.id = s.table_id
             LEFT  JOIN payments p ON p.reservation_id = r.id
             WHERE r.id = ?
             FOR UPDATE"
        );
        $stmt->execute([$reservationId]);
        $res = $stmt->fetch();

        if (!$res) {
            $pdo->rollBack();
            return ['ok' => false, 'code' => 'not_found', 'message' => 'Ticket nicht gefunden.'];
        }

        $gast = trim(($res['vorname'] ?? '') . ' ' . ($res['nachname'] ?? ''));

        // Ticket muss zum laufenden Event gehören
        if ($expectedEventId !== null && (int)$res['event_id'] !== $expectedEventId) {
            $pdo->rollBack();
            return [
                'ok'      => false,
                'code'    => 'wrong_event',
                'message' => 'Ticket gehört zu einer anderen Veranstaltung (' . $res['event_name'] . ').',
                'data'    => ['gast' => $gast],
            ];
        }

        if ($res['status'] === 'eingecheckt') {
            $pdo->rollBack();
            $zeit = $res['eingecheckt_am'] ? date('H:i', strtotime($res['eingecheckt_am'])) : null;
            return [
                'ok'      => false,
                'code'    => 'already',
                'message' => 'Bereits eingecheckt' . ($zeit ? " (um {$zeit} Uhr)" : '') . '.',
                'data'    => ['gast' => $gast],
            ];
        }

        if ($res['status'] !== 'geplant') {
            $pdo->rollBack();
            return [
                'ok'      => false,
                'code'    => 'invalid_status',
                'message' => 'Ticket ist nicht gültig (Status: ' . $res['status'] . ').',
                'data'    => ['gast' => $gast],
            ];
        }

        $now    = date('Y-m-d H:i:s');
        $userId = $_SESSION['user_id'] ?? null;

        $pdo->prepare(
            "UPDATE reservations
             SET status = 'eingecheckt', eingecheckt_am = ?, eingecheckt_von = ?
             WHERE id = ?"
        )->execute([$now, $userId, $reservationId]);

        // Sitzplatz nur bei Tischplan-Events (Freitickets haben seat_id = NULL)
        if (!empty($res['seat_id'])) {
            $pdo->prepare("UPDATE seats SET status = 'besetzt' WHERE id = ?")
                ->execute([$res['seat_id']]);
        }

        $pdo->commit();

        logAudit('CHECK_IN', 'reservations', $reservationId, json_encode([
            'buchungsnummer' => $res['buchungsnummer'],
            'gast'           => $gast,
        ], JSON_UNESCAPED_UNICODE));

        return [
            'ok'      => true,
            'code'    => 'ok',
            'message' => 'Check-in erfolgreich.',
            'data'    => [
                'reservation_id' => $reservationId,
                'buchungsnummer' => $res['buchungsnummer'],
                'gast'           => $gast,
                'vorname'        => $res['vorname'],
                'nachname'       => $res['nachname'],
                'event_name'     => $res['event_name'],
                'tischnummer'    => $res['tischnummer'],
                'sitzplatznummer'=> $res['sitzplatznummer'],
                'freies_ticket'  => empty($res['seat_id']),
                'zahl_status'    => $res['zahl_status'] ?? 'offen',
                'betrag'         => $res['betrag'] ?? 0,
                'zahlungsart'    => $res['zahlungsart'] ?? 'bar',
                'zeit'           => $now,
            ],
        ];

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Check-in Fehler: ' . $e->getMessage());
        return ['ok' => false, 'code' => 'error', 'message' => 'Technischer Fehler beim Check-in.'];
    }
}

/**
 * Check-in anhand eines gescannten QR-Inhalts ODER einer Buchungsnummer.
 * $requireSignature = true erzwingt einen gültig signierten QR-Code.
 */
function checkinByPayload(string $payload, ?int $expectedEventId = null, bool $requireSignature = true): array {
    $payload = trim($payload);

    if (str_contains($payload, '.')) {
        $bn = verifyTicketPayload($payload);
        if ($bn === null) {
            return ['ok' => false, 'code' => 'invalid_signature',
                    'message' => 'Ungültiger oder manipulierter QR-Code.'];
        }
    } else {
        // Kein Signaturteil: nur erlaubt, wenn manuelle Eingabe zugelassen ist
        if ($requireSignature) {
            return ['ok' => false, 'code' => 'invalid_signature',
                    'message' => 'Ungültiger QR-Code (keine Signatur).'];
        }
        $bn = $payload;
    }

    if (!preg_match('/^KARN-\d{4}-[0-9A-F]{6}$/i', $bn)) {
        return ['ok' => false, 'code' => 'invalid_format', 'message' => 'Ungültige Buchungsnummer.'];
    }

    $stmt = getDB()->prepare('SELECT id FROM reservations WHERE buchungsnummer = ? LIMIT 1');
    $stmt->execute([$bn]);
    $id = $stmt->fetchColumn();

    if (!$id) {
        return ['ok' => false, 'code' => 'not_found', 'message' => 'Ticket nicht gefunden.'];
    }

    return checkinReservation((int)$id, $expectedEventId);
}

// =====================
// Live-Event-Dashboard
// =====================

/**
 * Liefert den Live-Ampel-Zustand eines Events (Rot=nicht verkauft,
 * Gelb=verkauft/reserviert, Grün=eingecheckt). Wird sowohl beim Seitenaufruf
 * (Erstladung, kein leeres Grid) als auch von der Polling-API
 * (api/event_live_grid.php) genutzt – eine einzige Quelle für die Logik.
 *
 * Die Farbe wird ausschließlich aus dem aktiven Reservierungsdatensatz
 * abgeleitet (nicht aus seats.status), um keine zweite, potenziell
 * abweichende Wahrheitsquelle zu haben. Der Statusfilter steht bewusst in
 * der JOIN-Bedingung, nicht in einem nachgelagerten WHERE – sonst würde ein
 * stornierter+neu gebuchter Sitz zwei Zeilen für dieselbe Kachel erzeugen.
 *
 * @return array{event_typ:string,tables:array,tickets:array,capacity:?array,counts:array}|null
 */
function getEventLiveGrid(int $eventId): ?array {
    $pdo = getDB();

    $stmtEv = $pdo->prepare('SELECT id, event_typ, max_gaeste FROM events WHERE id = ?');
    $stmtEv->execute([$eventId]);
    $event = $stmtEv->fetch();
    if (!$event) return null;

    $eventTyp = $event['event_typ'] ?? 'tischplan';
    $counts   = ['rot' => 0, 'gelb' => 0, 'gruen' => 0];

    if ($eventTyp === 'freie_tickets') {
        $stmt = $pdo->prepare(
            "SELECT r.id AS reservation_id, r.status AS res_status, r.buchungsnummer,
                    u.vorname, u.nachname
             FROM reservations r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.event_id = ? AND r.status NOT IN ('abgerechnet','storniert')
             ORDER BY r.erstellt_am ASC"
        );
        $stmt->execute([$eventId]);

        $tickets = [];
        foreach ($stmt->fetchAll() as $row) {
            $farbe = $row['res_status'] === 'eingecheckt' ? 'gruen' : 'gelb';
            $counts[$farbe]++;
            $tickets[] = [
                'reservation_id' => (int)$row['reservation_id'],
                'buchungsnummer' => $row['buchungsnummer'],
                'gast'           => trim($row['vorname'] . ' ' . $row['nachname']),
                'farbe'          => $farbe,
            ];
        }

        $maxGaeste = $event['max_gaeste'] !== null ? (int)$event['max_gaeste'] : null;
        $capacity  = null;
        if ($maxGaeste !== null) {
            $rest = max(0, $maxGaeste - count($tickets));
            $counts['rot'] = $rest;
            $capacity = [
                'max_gaeste'  => $maxGaeste,
                'verkauft'    => count($tickets),
                'rest'        => $rest,
                // Rendering großer Restkapazitäten deckeln, sonst riesiges DOM
                'ghost_tiles' => min($rest, 200),
                'ghost_extra' => max(0, $rest - 200),
            ];
        }

        return [
            'event_typ' => 'freie_tickets',
            'tables'    => [],
            'tickets'   => $tickets,
            'capacity'  => $capacity,
            'counts'    => $counts,
        ];
    }

    // Tischplan-Events
    $stmt = $pdo->prepare(
        "SELECT t.id AS table_id, t.tischnummer,
                s.id AS seat_id, s.sitzplatznummer,
                r.id AS reservation_id, r.status AS res_status, r.buchungsnummer,
                u.vorname, u.nachname
         FROM tables t
         LEFT JOIN seats s ON s.table_id = t.id
         LEFT JOIN reservations r ON r.seat_id = s.id AND r.status NOT IN ('abgerechnet','storniert')
         LEFT JOIN users u ON u.id = r.user_id
         WHERE t.event_id = ?
         ORDER BY t.tischnummer ASC, s.sitzplatznummer ASC"
    );
    $stmt->execute([$eventId]);

    $tables = [];
    foreach ($stmt->fetchAll() as $row) {
        $tid = (int)$row['table_id'];
        if (!isset($tables[$tid])) {
            $tables[$tid] = [
                'table_id'    => $tid,
                'tischnummer' => (int)$row['tischnummer'],
                'seats'       => [],
            ];
        }
        if (!$row['seat_id']) continue;

        if ($row['reservation_id'] === null) {
            $farbe = 'rot';
        } elseif ($row['res_status'] === 'eingecheckt') {
            $farbe = 'gruen';
        } else {
            $farbe = 'gelb';
        }
        $counts[$farbe]++;

        $tables[$tid]['seats'][] = [
            'seat_id'         => (int)$row['seat_id'],
            'sitzplatznummer' => (int)$row['sitzplatznummer'],
            'reservation_id'  => $row['reservation_id'] !== null ? (int)$row['reservation_id'] : null,
            'buchungsnummer'  => $row['buchungsnummer'],
            'gast'            => $row['reservation_id'] !== null
                                    ? trim($row['vorname'] . ' ' . $row['nachname']) : null,
            'farbe'           => $farbe,
        ];
    }

    return [
        'event_typ' => 'tischplan',
        'tables'    => array_values($tables),
        'tickets'   => [],
        'capacity'  => null,
        'counts'    => $counts,
    ];
}

/**
 * Sucht aktive Reservierungen nach Name/E-Mail/Buchungsnummer – für die
 * Kassierer-Ticket-Suche (verlorenes Ticket erneut anzeigen).
 */
function findReservationsForLookup(string $query, ?int $eventId = null, int $limit = 20): array {
    $query = trim($query);
    if (mb_strlen($query) < 2) return [];

    $pdo = getDB();
    $sql = "SELECT r.id AS reservation_id, r.buchungsnummer, r.status AS res_status,
                   u.vorname, u.nachname,
                   e.name AS event_name,
                   t.tischnummer, s.sitzplatznummer
            FROM reservations r
            INNER JOIN users  u ON u.id = r.user_id
            INNER JOIN events e ON e.id = r.event_id
            LEFT  JOIN seats  s ON s.id = r.seat_id
            LEFT  JOIN tables t ON t.id = s.table_id
            WHERE r.status NOT IN ('abgerechnet','storniert')
              AND (u.vorname LIKE :q1 OR u.nachname LIKE :q2
                   OR CONCAT(u.vorname, ' ', u.nachname) LIKE :q3
                   OR r.buchungsnummer LIKE :q4 OR u.email LIKE :q5)";
    // Mit PDO::ATTR_EMULATE_PREPARES=false (siehe config.php) darf derselbe
    // benannte Platzhalter nicht mehrfach im Query auftauchen – jede Stelle
    // braucht einen eigenen Namen, gebunden auf denselben Suchwert.
    $needle = likePattern($query);
    $params = ['q1' => $needle, 'q2' => $needle, 'q3' => $needle, 'q4' => $needle, 'q5' => $needle];

    if ($eventId !== null) {
        $sql .= ' AND r.event_id = :event_id';
        $params['event_id'] = $eventId;
    }
    $sql .= ' ORDER BY r.erstellt_am DESC LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map(static function ($row) {
        return [
            'reservation_id' => (int)$row['reservation_id'],
            'buchungsnummer' => $row['buchungsnummer'],
            'gast'           => trim($row['vorname'] . ' ' . $row['nachname']),
            'event_name'     => $row['event_name'],
            'res_status'     => $row['res_status'],
            'platz'          => $row['sitzplatznummer']
                ? 'Tisch ' . $row['tischnummer'] . ' · Platz ' . $row['sitzplatznummer']
                : 'Freies Ticket',
        ];
    }, $stmt->fetchAll());
}

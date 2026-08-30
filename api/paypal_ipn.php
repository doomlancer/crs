<?php
/**
 * PayPal IPN (Instant Payment Notification) Handler
 * Wird von PayPal-Servern nach einer Zahlung aufgerufen.
 * Keine Benutzer-Session – muss immer HTTP 200 zurückgeben.
 *
 * Wichtig zum Verständnis der Prüfungen unten: Sowohl der Betrag als auch das
 * Feld "custom" (die Liste der zu quittierenden Buchungsnummern) stammen aus
 * einem Formular im Browser des Käufers und sind dort frei veränderbar. Die
 * Rückfrage bei PayPal beweist nur, dass IRGENDEINE Zahlung stattgefunden hat –
 * nicht, über welchen Betrag und wofür. Ohne die Betragsprüfung ließen sich
 * beliebig viele Tickets mit einer Ein-Cent-Zahlung als bezahlt markieren.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

/** Beendet die Verarbeitung. PayPal erwartet in jedem Fall HTTP 200. */
function ipnAbort(string $grund): void {
    error_log('PayPal IPN abgewiesen: ' . $grund);
    http_response_code(200);
    exit;
}

// Ohne konfiguriertes Empfängerkonto darf nichts verbucht werden – sonst würde
// ein leeres receiver_email gegen ein leeres PAYPAL_EMAIL "passen".
if (PAYPAL_EMAIL === '') {
    ipnAbort('PAYPAL_EMAIL ist nicht konfiguriert.');
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    ipnAbort('leerer Request-Body');
}

// ── 1) Echtheit der Benachrichtigung bei PayPal zurückfragen ───────────────
$verifyUrl = PAYPAL_SANDBOX
    ? 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr'
    : 'https://ipnpb.paypal.com/cgi-bin/webscr';

$ch = curl_init($verifyUrl);
curl_setopt_array($ch, [
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => 'cmd=_notify-validate&' . $rawBody,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: PHP-IPN-Verification',
        'Connection: Close',
    ],
]);
$response  = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError)              ipnAbort('cURL-Fehler: ' . $curlError);
if ($response !== 'VERIFIED') ipnAbort('nicht verifiziert: ' . substr((string)$response, 0, 100));

// ── 2) Felder einlesen und die Rahmendaten prüfen ──────────────────────────
parse_str($rawBody, $ipnData);

$paymentStatus = (string)($ipnData['payment_status'] ?? '');
$receiverEmail = (string)($ipnData['receiver_email'] ?? '');
$currency      = (string)($ipnData['mc_currency']    ?? '');
$custom        = trim((string)($ipnData['custom']    ?? ''));
$txnId         = trim((string)($ipnData['txn_id']    ?? ''));
$payerEmail    = trim((string)($ipnData['payer_email'] ?? ''));
$mcGross       = (float)($ipnData['mc_gross'] ?? 0);

if (strtolower($receiverEmail) !== strtolower(PAYPAL_EMAIL)) {
    ipnAbort('fremder Empfänger: ' . $receiverEmail);
}
if ($currency !== 'EUR') ipnAbort('falsche Währung: ' . $currency);
if ($txnId === '')       ipnAbort('keine txn_id');
if ($custom === '')      ipnAbort('kein custom-Feld');

// ── 3) Buchungsnummern strikt validieren ───────────────────────────────────
// Nur das erzeugte Format (siehe generateBuchungsnummer()) wird akzeptiert,
// und die Anzahl ist gedeckelt: eine einzelne Benachrichtigung soll nicht
// hunderte Buchungen auf einmal anfassen können.
$rohListe = str_starts_with($custom, 'BATCH:')
    ? explode(',', substr($custom, 6))
    : [$custom];

$buchungsnummern = [];
foreach ($rohListe as $bn) {
    $bn = strtoupper(trim($bn));
    if (preg_match('/^KARN-\d{4}-[0-9A-F]{6}$/', $bn)) {
        $buchungsnummern[] = $bn;
    }
}
$buchungsnummern = array_values(array_unique($buchungsnummern));

if (!$buchungsnummern)             ipnAbort('keine gültige Buchungsnummer in: ' . $custom);
if (count($buchungsnummern) > 20)  ipnAbort('zu viele Buchungsnummern: ' . count($buchungsnummern));

$platzhalter = implode(',', array_fill(0, count($buchungsnummern), '?'));

try {
    $pdo = getDB();

    // ── 4) Rückbuchungen: Zahlung gilt nicht mehr ──────────────────────────
    if (in_array($paymentStatus, ['Refunded', 'Reversed'], true)) {
        $stmt = $pdo->prepare(
            "UPDATE payments p JOIN reservations r ON r.id = p.reservation_id
             SET p.status = 'offen'
             WHERE r.buchungsnummer IN ({$platzhalter}) AND p.status = 'bezahlt'"
        );
        $stmt->execute($buchungsnummern);
        logAudit('PAYPAL_RUECKBUCHUNG', 'payments', 0, json_encode([
            'txn_id' => $txnId, 'status' => $paymentStatus,
            'buchungen' => $buchungsnummern, 'betroffen' => $stmt->rowCount(),
        ]));
        http_response_code(200);
        exit;
    }

    if ($paymentStatus !== 'Completed') {
        ipnAbort('Status nicht verwertbar: ' . $paymentStatus);
    }

    // ── 5) Betrag gegen die tatsächliche Forderung prüfen ──────────────────
    // Das ist die entscheidende Prüfung: ohne sie quittiert jede beliebige
    // Zahlung (auch 0,01 €) jede beliebige Menge an Buchungen.
    $stmtSum = $pdo->prepare(
        "SELECT COALESCE(SUM(p.betrag), 0)
         FROM payments p
         JOIN reservations r ON r.id = p.reservation_id
         WHERE r.buchungsnummer IN ({$platzhalter})"
    );
    $stmtSum->execute($buchungsnummern);
    $erwartet = (float)$stmtSum->fetchColumn();

    if ($erwartet <= 0) {
        ipnAbort('keine offene Forderung zu: ' . implode(',', $buchungsnummern));
    }
    // Rundungsdifferenzen zulassen, Unterzahlung nicht. Überzahlung ist
    // unkritisch und wird bewusst akzeptiert.
    if ($mcGross + 0.01 < $erwartet) {
        ipnAbort(sprintf('Betrag zu niedrig: gezahlt %.2f, gefordert %.2f (txn %s)',
            $mcGross, $erwartet, $txnId));
    }

    // ── 6) Jede Transaktion nur einmal verbuchen ───────────────────────────
    // Der UNIQUE-Key auf txn_id macht das auch bei zwei gleichzeitig
    // eintreffenden Benachrichtigungen zuverlässig.
    try {
        $pdo->prepare(
            'INSERT INTO paypal_transactions (txn_id, mc_gross, payer_email, buchungen)
             VALUES (?, ?, ?, ?)'
        )->execute([$txnId, $mcGross, $payerEmail, implode(',', $buchungsnummern)]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            ipnAbort('Transaktion bereits verarbeitet: ' . $txnId);
        }
        throw $e;
    }

    // ── 7) Zahlungen quittieren ────────────────────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT p.id, p.status
         FROM payments p
         JOIN reservations r ON r.id = p.reservation_id
         WHERE r.buchungsnummer = ?
         LIMIT 1'
    );
    $stmtUpdate = $pdo->prepare('UPDATE payments SET status = ? WHERE id = ?');

    foreach ($buchungsnummern as $bn) {
        $stmt->execute([$bn]);
        $payment = $stmt->fetch();

        if (!$payment) {
            error_log('PayPal IPN: Buchungsnummer nicht gefunden: ' . $bn);
            continue;
        }
        if ($payment['status'] !== 'bezahlt') {
            $stmtUpdate->execute(['bezahlt', $payment['id']]);
            logAudit('PAYPAL_IPN', 'payments', (int)$payment['id'], json_encode([
                'buchungsnummer' => $bn,
                'txn_id'         => $txnId,
                'mc_gross'       => $mcGross,
                'gefordert'      => $erwartet,
            ]));
        }
    }
} catch (Exception $e) {
    error_log('PayPal IPN DB-Fehler: ' . $e->getMessage());
}

http_response_code(200);
exit;

<?php
/**
 * PayPal IPN (Instant Payment Notification) Handler
 * Called by PayPal servers after a completed payment.
 * No user auth – must always return HTTP 200.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

// Read raw POST body
$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    error_log('PayPal IPN: leerer Request-Body');
    http_response_code(200);
    exit;
}

// Verify with PayPal
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
$response = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    error_log('PayPal IPN cURL-Fehler: ' . $curlError);
    http_response_code(200);
    exit;
}

if ($response !== 'VERIFIED') {
    error_log('PayPal IPN: nicht verifiziert. Antwort: ' . substr((string)$response, 0, 100));
    http_response_code(200);
    exit;
}

// Parse POST fields
parse_str($rawBody, $ipnData);

$paymentStatus  = $ipnData['payment_status']  ?? '';
$receiverEmail  = $ipnData['receiver_email']  ?? '';
$currency       = $ipnData['mc_currency']     ?? '';
$buchungsnummer = trim($ipnData['custom']     ?? '');

if (
    $paymentStatus !== 'Completed'
    || strtolower($receiverEmail) !== strtolower(PAYPAL_EMAIL)
    || $currency !== 'EUR'
    || $buchungsnummer === ''
) {
    error_log('PayPal IPN: Bedingung nicht erfüllt. Status=' . $paymentStatus
        . ' receiver=' . $receiverEmail . ' currency=' . $currency);
    http_response_code(200);
    exit;
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare(
        'SELECT p.id, p.status
         FROM payments p
         JOIN reservations r ON r.id = p.reservation_id
         WHERE r.buchungsnummer = ?
         LIMIT 1'
    );
    $stmt->execute([$buchungsnummer]);
    $payment = $stmt->fetch();

    if (!$payment) {
        error_log('PayPal IPN: Buchungsnummer nicht gefunden: ' . $buchungsnummer);
        http_response_code(200);
        exit;
    }

    if ($payment['status'] !== 'bezahlt') {
        $pdo->prepare('UPDATE payments SET status = ? WHERE id = ?')
            ->execute(['bezahlt', $payment['id']]);

        logAudit('PAYPAL_IPN', 'payments', (int)$payment['id'], json_encode([
            'buchungsnummer' => $buchungsnummer,
            'txn_id'        => $ipnData['txn_id'] ?? '',
            'mc_gross'      => $ipnData['mc_gross'] ?? '',
        ]));
    }
} catch (Exception $e) {
    error_log('PayPal IPN DB-Fehler: ' . $e->getMessage());
}

http_response_code(200);
exit;

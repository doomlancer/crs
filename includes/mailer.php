<?php
/**
 * E-Mail-Versand via PHP mail() – kein Composer, kein PHPMailer nötig
 * Funktioniert auf jedem Shared-Hosting / Plesk-Server
 */

/**
 * Kern-Funktion: sendet eine HTML-E-Mail mit Plaintext-Fallback
 */
function sendMail(string $to, string $toName, string $subject, string $htmlBody, string $textBody): bool {
    $from     = $_ENV['SMTP_USER'] ?? 'noreply@localhost';
    $fromName = $_ENV['SMTP_FROM_NAME'] ?? APP_NAME;
    $boundary = '----=_Part_' . md5(uniqid((string)time(), true));

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= 'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";
    $headers .= "X-Priority: 3\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($textBody)) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    $body .= "--{$boundary}--\r\n";

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedTo      = '=?UTF-8?B?' . base64_encode($toName) . "?= <{$to}>";

    $result = mail($encodedTo, $encodedSubject, $body, $headers);
    if (!$result) {
        error_log("mail() fehlgeschlagen an: {$to}, Betreff: {$subject}");
    }
    return $result;
}

function mailPasswordReset(string $email, string $vorname, string $resetUrl): bool {
    $html = emailTemplate('Passwort zurücksetzen',
        "<p>Hallo " . htmlspecialchars($vorname) . ",</p>
        <p>Sie haben eine Anfrage zum Zurücksetzen Ihres Passworts gestellt.</p>
        <p style='text-align:center;margin:24px 0'>
            <a href='" . htmlspecialchars($resetUrl) . "' style='background:#FFC107;color:#000;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:bold;'>Passwort zurücksetzen</a>
        </p>
        <p>Dieser Link ist <strong>1 Stunde</strong> gültig.</p>
        <p>Falls Sie keinen Reset angefordert haben, ignorieren Sie diese E-Mail.</p>"
    );
    $text = "Hallo {$vorname},\n\nPasswort zurücksetzen: {$resetUrl}\n\nDer Link ist 1 Stunde gültig.";
    return sendMail($email, $vorname, APP_NAME . ' – Passwort zurücksetzen', $html, $text);
}

function mailReservierungsbestaetigung(string $email, string $vorname, array $buchungen, string $eventName, string $eventDatum): bool {
    $zeilen = '';
    $textZeilen = '';
    foreach ($buchungen as $b) {
        $zeilen .= "<tr>
            <td style='padding:8px;border-bottom:1px solid #eee'><code style='background:#f8f9fa;padding:2px 6px;border-radius:3px'>{$b['buchungsnummer']}</code></td>
            <td style='padding:8px;border-bottom:1px solid #eee'>Tisch {$b['tischnummer']}, Platz {$b['sitzplatznummer']}</td>
            <td style='padding:8px;border-bottom:1px solid #eee;text-align:right'>" . formatBetrag((float)$b['preis']) . "</td>
        </tr>";
        $textZeilen .= "  {$b['buchungsnummer']} – Tisch {$b['tischnummer']}, Platz {$b['sitzplatznummer']}\n";
    }

    $html = emailTemplate('Reservierungsbestätigung',
        "<p>Hallo " . htmlspecialchars($vorname) . ",</p>
        <p>Ihre Reservierung für <strong>" . htmlspecialchars($eventName) . "</strong> am <strong>" . formatDatum($eventDatum) . "</strong> wurde erfolgreich erfasst.</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0'>
            <thead><tr style='background:#FFC107'>
                <th style='padding:10px;text-align:left'>Buchungsnummer</th>
                <th style='padding:10px;text-align:left'>Sitzplatz</th>
                <th style='padding:10px;text-align:right'>Preis</th>
            </tr></thead>
            <tbody>{$zeilen}</tbody>
        </table>
        <p>Bitte zeigen Sie Ihre Buchungsnummer(n) beim Einlass vor.</p>"
    );
    $text = "Hallo {$vorname},\n\nIhre Reservierung für {$eventName} am " . formatDatum($eventDatum) . ":\n{$textZeilen}";
    return sendMail($email, $vorname, APP_NAME . ' – Reservierungsbestätigung', $html, $text);
}

function mailStornierungsbestaetigung(string $email, string $vorname, string $buchungsnummer, string $eventName): bool {
    $html = emailTemplate('Stornierungsbestätigung',
        "<p>Hallo " . htmlspecialchars($vorname) . ",</p>
        <p>Ihre Reservierung <code style='background:#f8f9fa;padding:2px 6px;border-radius:3px'>{$buchungsnummer}</code> für <strong>" . htmlspecialchars($eventName) . "</strong> wurde erfolgreich storniert.</p>
        <p>Falls eine Zahlung bereits eingegangen ist, wird diese erstattet.</p>"
    );
    $text = "Hallo {$vorname},\n\nIhre Reservierung {$buchungsnummer} für {$eventName} wurde storniert.";
    return sendMail($email, $vorname, APP_NAME . ' – Stornierungsbestätigung', $html, $text);
}

function mailWaitlistNotification(string $email, string $vorname, string $eventName, string $tischUrl): bool {
    $html = emailTemplate('Platz verfügbar!',
        "<p>Hallo " . htmlspecialchars($vorname) . ",</p>
        <p>Ein Platz für <strong>" . htmlspecialchars($eventName) . "</strong> ist wieder frei geworden!</p>
        <p style='text-align:center;margin:24px 0'>
            <a href='" . htmlspecialchars($tischUrl) . "' style='background:#FFC107;color:#000;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:bold;'>Jetzt reservieren</a>
        </p>
        <p><small>Bitte beeilen Sie sich – der Platz kann auch von anderen reserviert werden.</small></p>"
    );
    $text = "Hallo {$vorname},\n\nEin Platz für {$eventName} ist frei: {$tischUrl}";
    return sendMail($email, $vorname, APP_NAME . ' – Ein Platz ist frei geworden!', $html, $text);
}

function mailRegistrierungsbestaetigung(string $email, string $vorname): bool {
    $html = emailTemplate('Willkommen!',
        "<p>Hallo " . htmlspecialchars($vorname) . ",</p>
        <p>Ihr Konto wurde erfolgreich erstellt. Sie können sich jetzt anmelden und Plätze für unsere Veranstaltungen reservieren.</p>
        <p style='text-align:center;margin:24px 0'>
            <a href='" . APP_URL . "/pages/events.php' style='background:#FFC107;color:#000;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:bold;'>Zu den Events</a>
        </p>"
    );
    $text = "Hallo {$vorname},\n\nIhr Konto bei " . APP_NAME . " wurde erstellt.\n" . APP_URL . "/pages/events.php";
    return sendMail($email, $vorname, 'Willkommen bei ' . APP_NAME, $html, $text);
}

function emailTemplate(string $titel, string $inhalt): string {
    $appName = htmlspecialchars(APP_NAME);
    $year    = date('Y');
    return "<!DOCTYPE html><html lang='de'><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif'>
<table style='max-width:600px;margin:32px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)' width='100%' cellpadding='0' cellspacing='0'>
  <tr><td style='background:#1a1a2e;padding:24px 32px;text-align:center'>
    <h1 style='color:#FFC107;margin:0;font-size:22px'>&#127917; {$appName}</h1>
  </td></tr>
  <tr><td style='padding:32px;color:#333;font-size:15px;line-height:1.6'>
    <h2 style='color:#1a1a2e;margin-top:0'>{$titel}</h2>
    {$inhalt}
  </td></tr>
  <tr><td style='background:#f8f9fa;padding:16px 32px;text-align:center;color:#999;font-size:12px'>
    &copy; {$year} {$appName} &middot; Diese E-Mail wurde automatisch generiert.
  </td></tr>
</table></body></html>";
}

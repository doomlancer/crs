<?php
/**
 * E-Mail-Versand via PHPMailer
 * Benötigt: phpmailer/phpmailer (via Composer)
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Vorkonfiguriertes PHPMailer-Objekt zurückgeben
 */
function createMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $_ENV['SMTP_HOST'] ?? '';
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['SMTP_USER'] ?? '';
    $mail->Password   = $_ENV['SMTP_PASS'] ?? '';
    $mail->SMTPSecure = ($_ENV['SMTP_ENCRYPTION'] ?? 'tls') === 'ssl'
                        ? PHPMailer::ENCRYPTION_SMTPS
                        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(
        $_ENV['SMTP_USER'] ?? 'noreply@localhost',
        $_ENV['SMTP_FROM_NAME'] ?? APP_NAME
    );
    return $mail;
}

/**
 * Passwort-Reset-E-Mail
 */
function mailPasswordReset(string $email, string $vorname, string $resetUrl): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($email, $vorname);
        $mail->Subject = APP_NAME . ' – Passwort zurücksetzen';
        $mail->isHTML(true);
        $mail->Body = emailTemplate(
            'Passwort zurücksetzen',
            "<p>Hallo {$vorname},</p>
            <p>Sie haben eine Anfrage zum Zurücksetzen Ihres Passworts gestellt. Klicken Sie auf den Button, um ein neues Passwort zu vergeben:</p>
            <p style=\"text-align:center;margin:24px 0\">
                <a href=\"" . htmlspecialchars($resetUrl) . "\" style=\"background:#FFC107;color:#000;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:bold;\">Passwort zurücksetzen</a>
            </p>
            <p>Dieser Link ist <strong>1 Stunde</strong> gültig.</p>
            <p>Falls Sie keinen Reset angefordert haben, ignorieren Sie diese E-Mail.</p>"
        );
        $mail->AltBody = "Hallo {$vorname},\n\nPasswort zurücksetzen: {$resetUrl}\n\nDer Link ist 1 Stunde gültig.";
        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('Mailer Fehler (PasswordReset): ' . $e->getMessage());
        return false;
    }
}

/**
 * Reservierungsbestätigung
 */
function mailReservierungsbestaetigung(string $email, string $vorname, array $buchungen, string $eventName, string $eventDatum): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($email, $vorname);
        $mail->Subject = APP_NAME . ' – Reservierungsbestätigung';

        $zeilen = '';
        foreach ($buchungen as $b) {
            $zeilen .= "<tr>
                <td style=\"padding:8px;border-bottom:1px solid #eee\"><code style=\"background:#f8f9fa;padding:2px 6px;border-radius:3px\">{$b['buchungsnummer']}</code></td>
                <td style=\"padding:8px;border-bottom:1px solid #eee\">Tisch {$b['tischnummer']}, Platz {$b['sitzplatznummer']}</td>
                <td style=\"padding:8px;border-bottom:1px solid #eee;text-align:right\">" . formatBetrag((float)$b['preis']) . "</td>
            </tr>";
        }

        $mail->isHTML(true);
        $mail->Body = emailTemplate(
            'Reservierungsbestätigung',
            "<p>Hallo {$vorname},</p>
            <p>Ihre Reservierung für <strong>" . htmlspecialchars($eventName) . "</strong> am <strong>" . formatDatum($eventDatum) . "</strong> wurde erfolgreich erfasst.</p>
            <table style=\"width:100%;border-collapse:collapse;margin:16px 0\">
                <thead><tr style=\"background:#FFC107\">
                    <th style=\"padding:10px;text-align:left\">Buchungsnummer</th>
                    <th style=\"padding:10px;text-align:left\">Sitzplatz</th>
                    <th style=\"padding:10px;text-align:right\">Preis</th>
                </tr></thead>
                <tbody>{$zeilen}</tbody>
            </table>
            <p>Bitte zeigen Sie Ihre Buchungsnummer(n) beim Einlass vor. Sie finden den QR-Code auch in Ihrem Konto unter <em>Meine Reservierungen</em>.</p>"
        );
        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('Mailer Fehler (Reservierung): ' . $e->getMessage());
        return false;
    }
}

/**
 * Stornierungsbestätigung
 */
function mailStornierungsbestaetigung(string $email, string $vorname, string $buchungsnummer, string $eventName): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($email, $vorname);
        $mail->Subject = APP_NAME . ' – Stornierungsbestätigung';
        $mail->isHTML(true);
        $mail->Body = emailTemplate(
            'Stornierungsbestätigung',
            "<p>Hallo {$vorname},</p>
            <p>Ihre Reservierung <code style=\"background:#f8f9fa;padding:2px 6px;border-radius:3px\">{$buchungsnummer}</code> für <strong>" . htmlspecialchars($eventName) . "</strong> wurde erfolgreich storniert.</p>
            <p>Falls eine Zahlung bereits eingegangen ist, wird diese erstattet.</p>"
        );
        $mail->AltBody = "Hallo {$vorname},\n\nIhre Reservierung {$buchungsnummer} für {$eventName} wurde storniert.";
        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('Mailer Fehler (Stornierung): ' . $e->getMessage());
        return false;
    }
}

/**
 * Wartelisten-Benachrichtigung
 */
function mailWaitlistNotification(string $email, string $vorname, string $eventName, string $tischUrl): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($email, $vorname);
        $mail->Subject = APP_NAME . ' – Ein Platz ist frei geworden!';
        $mail->isHTML(true);
        $mail->Body = emailTemplate(
            'Platz verfügbar!',
            "<p>Hallo {$vorname},</p>
            <p>Gute Nachrichten! Ein Platz für <strong>" . htmlspecialchars($eventName) . "</strong> ist wieder frei geworden.</p>
            <p style=\"text-align:center;margin:24px 0\">
                <a href=\"" . htmlspecialchars($tischUrl) . "\" style=\"background:#FFC107;color:#000;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:bold;\">Jetzt reservieren</a>
            </p>
            <p><small>Bitte beeilen Sie sich – der Platz kann auch von anderen reserviert werden.</small></p>"
        );
        $mail->AltBody = "Hallo {$vorname},\n\nEin Platz für {$eventName} ist frei: {$tischUrl}";
        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('Mailer Fehler (Warteliste): ' . $e->getMessage());
        return false;
    }
}

/**
 * Registrierungsbestätigung
 */
function mailRegistrierungsbestaetigung(string $email, string $vorname): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($email, $vorname);
        $mail->Subject = 'Willkommen bei ' . APP_NAME;
        $mail->isHTML(true);
        $mail->Body = emailTemplate(
            'Willkommen!',
            "<p>Hallo {$vorname},</p>
            <p>Ihr Konto wurde erfolgreich erstellt. Sie können sich jetzt anmelden und Plätze für unsere Veranstaltungen reservieren.</p>
            <p style=\"text-align:center;margin:24px 0\">
                <a href=\"" . APP_URL . "/pages/events.php\" style=\"background:#FFC107;color:#000;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:bold;\">Zu den Events</a>
            </p>"
        );
        $mail->AltBody = "Hallo {$vorname},\n\nIhr Konto bei " . APP_NAME . " wurde erstellt.";
        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('Mailer Fehler (Registrierung): ' . $e->getMessage());
        return false;
    }
}

/**
 * HTML-E-Mail-Template
 */
function emailTemplate(string $titel, string $inhalt): string {
    $appName = htmlspecialchars(APP_NAME);
    return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif">
<table style="max-width:600px;margin:32px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)" width="100%" cellpadding="0" cellspacing="0">
  <tr>
    <td style="background:#1a1a2e;padding:24px 32px;text-align:center">
      <h1 style="color:#FFC107;margin:0;font-size:22px">🎭 {$appName}</h1>
    </td>
  </tr>
  <tr>
    <td style="padding:32px;color:#333;font-size:15px;line-height:1.6">
      <h2 style="color:#1a1a2e;margin-top:0">{$titel}</h2>
      {$inhalt}
    </td>
  </tr>
  <tr>
    <td style="background:#f8f9fa;padding:16px 32px;text-align:center;color:#999;font-size:12px">
      © <?= date('Y') ?> {$appName} · Diese E-Mail wurde automatisch generiert.
    </td>
  </tr>
</table>
</body>
</html>
HTML;
}

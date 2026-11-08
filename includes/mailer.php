<?php
/**
 * E-Mail-Versand – kein Composer, kein PHPMailer nötig.
 *
 * Drei Wege, in dieser Reihenfolge:
 *  1. SMTP_HOST in der .env (Docker): der Entrypoint richtet msmtp ein, PHP
 *     mail() liefert darüber aus.
 *  2. SMTP-Zugang im Admin-Bereich (Design & Einstellungen): wird in
 *     GEHEIM_DIR abgelegt, die App ruft msmtp direkt mit dieser Konfiguration
 *     auf. Braucht keine Konsole auf dem Server.
 *  3. Sonst PHP mail() mit der Mail-Konfiguration des Servers (Plesk/Shared
 *     Hosting).
 */

const MSMTP_BINARY = '/usr/bin/msmtp';

/** Fehlertext des letzten fehlgeschlagenen Versands (für die Testmail). */
function mailLetzterFehler(?string $setzen = null): string {
    static $fehler = '';
    if ($setzen !== null) $fehler = $setzen;
    return $fehler;
}

/** Ist msmtp vorhanden (Docker-Image)? Nur dann gibt es Weg 2. */
function smtpAdminMoeglich(): bool {
    return is_executable(MSMTP_BINARY) && function_exists('proc_open');
}

/**
 * Im Admin-Bereich hinterlegter SMTP-Zugang (ohne Passwort, wenn
 * $mitPasswort = false). Leeres Array = nichts hinterlegt.
 */
function smtpEinstellungen(bool $mitPasswort = false): array {
    $datei = GEHEIM_DIR . '/smtp.json';
    if (!is_readable($datei)) return [];
    $e = json_decode((string)@file_get_contents($datei), true);
    if (!is_array($e) || empty($e['host'])) return [];
    if (!$mitPasswort) {
        $e['passwort_gesetzt'] = ($e['passwort'] ?? '') !== '';
        unset($e['passwort']);
    }
    return $e;
}

/**
 * Welcher Weg ist aktiv? 'env' | 'admin' | 'server'
 */
function mailWeg(): string {
    if (!empty($_ENV['SMTP_HOST'])) return 'env';
    if (smtpAdminMoeglich() && smtpEinstellungen()) return 'admin';
    return 'server';
}

/** Absenderadresse und -name für den aktiven Weg. */
function mailAbsender(): array {
    $name = $_ENV['SMTP_FROM_NAME'] ?? (getSetting('app_name', '') ?: APP_NAME);
    if (mailWeg() === 'admin') {
        $e = smtpEinstellungen();
        return [$e['absender'], ($e['absender_name'] ?? '') !== '' ? $e['absender_name'] : $name];
    }
    return [$_ENV['SMTP_USER'] ?? 'noreply@localhost', $name];
}

/**
 * Speichert den SMTP-Zugang aus dem Admin-Bereich und erzeugt daraus die
 * msmtp-Konfiguration. $e['passwort'] === null behält das bisherige Passwort.
 * Leerer Host entfernt den Zugang.
 */
function smtpEinstellungenSpeichern(array $e): bool {
    if (($e['host'] ?? '') === '') {
        @unlink(GEHEIM_DIR . '/smtp.json');
        @unlink(GEHEIM_DIR . '/msmtprc');
        return true;
    }
    if (($e['passwort'] ?? null) === null) {
        $e['passwort'] = smtpEinstellungen(true)['passwort'] ?? '';
    }

    // msmtp entfernt nur die äußeren Anführungszeichen und liest alles dazwischen
    // wörtlich – es gibt keine Maskierung mit \ (siehe trim_string() in msmtp
    // conf.c). So bleiben auch Leerzeichen am Rand und " im Passwort erhalten.
    // Zeilenumbrüche sind vorher ausgeschlossen – sie würden eigene Direktiven
    // erlauben.
    $q = static fn(string $v): string => '"' . str_replace(["\r", "\n", "\0"], '', $v) . '"';
    $tls = $e['verschluesselung'] !== 'keine';
    $rc  = "# Automatisch erzeugt aus Verwaltung → Design & Einstellungen. Nicht von Hand ändern.\n"
         . "defaults\n"
         . "timeout 20\n"
         . "syslog off\n"
         . 'logfile ' . $q(dirname(__DIR__) . '/logs/msmtp.log') . "\n"
         . "tls_trust_file /etc/ssl/certs/ca-certificates.crt\n"
         . "\naccount default\n"
         . 'host ' . $q($e['host']) . "\n"
         . 'port ' . (int)$e['port'] . "\n"
         . 'tls ' . ($tls ? 'on' : 'off') . "\n"
         . ($tls ? 'tls_starttls ' . ($e['verschluesselung'] === 'starttls' ? 'on' : 'off') . "\n" : '')
         . 'from ' . $q($e['absender']) . "\n";
    if ($e['benutzer'] !== '') {
        $rc .= "auth on\n"
             . 'user ' . $q($e['benutzer']) . "\n"
             . 'password ' . $q($e['passwort']) . "\n";
    } else {
        $rc .= "auth off\n";
    }

    return geheimDateiSchreiben('msmtprc', $rc)
        && geheimDateiSchreiben('smtp.json', json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Liefert eine fertige Nachricht über msmtp mit der Admin-Konfiguration aus.
 * Anders als mail() bekommen wir hier die Fehlermeldung des Mailservers.
 */
function mailUeberMsmtp(string $to, string $from, string $nachricht): bool {
    $rc = GEHEIM_DIR . '/msmtprc';
    if (!is_readable($rc)) {
        // Volume neu angelegt o. Ä.: aus smtp.json neu erzeugen.
        $e = smtpEinstellungen(true);
        if (!$e || !smtpEinstellungenSpeichern($e)) {
            mailLetzterFehler('SMTP-Konfiguration fehlt.');
            return false;
        }
    }

    $proc = @proc_open(
        [MSMTP_BINARY, '-C', $rc, '-f', $from, '--', $to],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        mailLetzterFehler('msmtp konnte nicht gestartet werden.');
        return false;
    }
    fwrite($pipes[0], $nachricht);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $stderr = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    if ($code !== 0) {
        // msmtp schreibt "msmtp: <Grund>" – Präfix weg, Zeilen zusammenfassen.
        $grund = preg_replace('/^msmtp:\s*/m', '', $stderr);
        mailLetzterFehler(trim(preg_replace('/\s*\n\s*/', ' – ', $grund)) ?: "msmtp-Fehlercode {$code}");
        error_log('msmtp-Versand fehlgeschlagen (' . $code . '): ' . $stderr);
        return false;
    }
    return true;
}

/**
 * Kern-Funktion: sendet eine HTML-E-Mail mit Plaintext-Fallback
 */
function sendMail(string $to, string $toName, string $subject, string $htmlBody, string $textBody, array $inlineBilder = []): bool {
    mailLetzterFehler('');
    // Betreff und Anzeigename sind durch die Base64-Kodierung unten bereits
    // gegen Header-Injection geschützt, die Empfängeradresse dagegen wird roh
    // interpoliert. Alle heutigen Aufrufer liefern geprüfte Adressen – diese
    // Absicherung stellt sicher, dass das auch bei künftigen so bleibt.
    $to = str_replace(["\r", "\n", "\0"], '', trim($to));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('sendMail: ungültige Empfängeradresse abgewiesen');
        return false;
    }

    [$from, $fromName] = mailAbsender();
    $altGrenze = '----=_Alt_' . md5(uniqid((string)time(), true));

    $alternative  = "--{$altGrenze}\r\n";
    $alternative .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $alternative .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $alternative .= chunk_split(base64_encode($textBody)) . "\r\n";
    $alternative .= "--{$altGrenze}\r\n";
    $alternative .= "Content-Type: text/html; charset=UTF-8\r\n";
    $alternative .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $alternative .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    $alternative .= "--{$altGrenze}--\r\n";

    $headers  = "MIME-Version: 1.0\r\n";
    if ($inlineBilder) {
        // multipart/related: Die QR-Codes hängen als Bilder an und werden im
        // HTML per cid: eingebettet. data:-URIs und SVG zeigen Gmail und
        // Outlook nicht an – eingebettete PNGs dagegen überall.
        $relGrenze = '----=_Rel_' . md5(uniqid('rel', true));
        $headers .= "Content-Type: multipart/related; boundary=\"{$relGrenze}\"; type=\"multipart/alternative\"\r\n";
        $body  = "--{$relGrenze}\r\n";
        $body .= "Content-Type: multipart/alternative; boundary=\"{$altGrenze}\"\r\n\r\n";
        $body .= $alternative . "\r\n";
        foreach ($inlineBilder as $bild) {
            $name  = preg_replace('/[^A-Za-z0-9._-]/', '', $bild['name']);
            $cid   = preg_replace('/[^A-Za-z0-9._@-]/', '', $bild['cid']);
            $body .= "--{$relGrenze}\r\n";
            $body .= "Content-Type: image/png; name=\"{$name}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-ID: <{$cid}>\r\n";
            $body .= "Content-Disposition: inline; filename=\"{$name}\"\r\n\r\n";
            $body .= chunk_split(base64_encode($bild['daten'])) . "\r\n";
        }
        $body .= "--{$relGrenze}--\r\n";
    } else {
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$altGrenze}\"\r\n";
        $body = $alternative;
    }
    $headers .= 'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "X-Priority: 3\r\n";

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedTo      = '=?UTF-8?B?' . base64_encode($toName) . "?= <{$to}>";

    if (mailWeg() === 'admin') {
        $domain    = substr(strrchr($from, '@') ?: '@localhost', 1);
        $nachricht = "To: {$encodedTo}\r\n"
                   . "Subject: {$encodedSubject}\r\n"
                   . 'Date: ' . date('r') . "\r\n"
                   . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . ">\r\n"
                   . $headers . "\r\n" . $body;
        return mailUeberMsmtp($to, $from, $nachricht);
    }

    $result = mail($encodedTo, $encodedSubject, $body, $headers);
    if (!$result) {
        error_log("mail() fehlgeschlagen an: {$to}, Betreff: {$subject}");
        mailLetzterFehler(mailLogLetzteZeile() ?: 'Der Server hat den Versand abgelehnt.');
    }
    return $result;
}

/** Letzte Zeile aus dem msmtp-Log (Weg 1), um Fehler sichtbar zu machen. */
function mailLogLetzteZeile(): string {
    $log = dirname(__DIR__) . '/logs/msmtp.log';
    if (!is_readable($log)) return '';
    $f = @fopen($log, 'r');
    if (!$f) return '';
    fseek($f, max(0, filesize($log) - 2000));
    $zeilen = array_filter(explode("\n", (string)stream_get_contents($f)));
    fclose($f);
    $letzte = (string)end($zeilen);
    // msmtp-Logzeile: "... smtpstatus=535 smtpmsg='535 ...' errormsg='...' exitcode=..."
    if (preg_match("/errormsg='([^']*)'/", $letzte, $m)) return $m[1];
    return '';
}

/** Testmail aus dem Admin-Bereich. */
function mailTest(string $email, string $vorname): bool {
    $html = emailTemplate('Testmail',
        "<p>Hallo " . htmlspecialchars($vorname) . ",</p>
         <p>diese Testmail zeigt: Der Mailversand von <strong>" . htmlspecialchars(getSetting('app_name', '') ?: APP_NAME) . "</strong> funktioniert.
         Buchungsbestätigungen, Tickets und Warteliste-Nachrichten kommen damit bei den Gästen an.</p>
         <p style=\"color:#666;font-size:13px;\">Verschickt am " . date('d.m.Y, H:i') . " Uhr.</p>"
    );
    $text = "Hallo {$vorname},\n\ndiese Testmail zeigt: Der Mailversand funktioniert.\n";
    return sendMail($email, $vorname, 'Testmail – Mailversand funktioniert', $html, $text);
}

function mailPasswordReset(string $email, string $vorname, string $resetUrl): bool {
    $html = emailTemplate('Passwort zurücksetzen',
        "<p>Hallo " . htmlspecialchars($vorname) . ",</p>
        <p>Sie haben eine Anfrage zum Zurücksetzen Ihres Passworts gestellt.</p>
        <p style='text-align:center;margin:24px 0'>
            <a href='" . htmlspecialchars($resetUrl) . "' style='background:" . mailFarbe() . ";color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:bold;'>Passwort zurücksetzen</a>
        </p>
        <p>Dieser Link ist <strong>1 Stunde</strong> gültig.</p>
        <p>Falls Sie keinen Reset angefordert haben, ignorieren Sie diese E-Mail.</p>"
    );
    $text = "Hallo {$vorname},\n\nPasswort zurücksetzen: {$resetUrl}\n\nDer Link ist 1 Stunde gültig.";
    return sendMail($email, $vorname, APP_NAME . ' – Passwort zurücksetzen', $html, $text);
}

/**
 * Reservierungsbestätigung: je Person ein Ticket mit eigenem QR-Code (als
 * eingebettetes PNG), ein Link zu allen Tickets ohne Login, je Ticket ein
 * Link zum Weitergeben, dazu der Zahlungshinweis je Zahlungsart.
 *
 * @param array $user    email, vorname, nachname
 * @param array $event   name, datum
 * @param array $tickets buchungsnummer, preis, tischnummer, sitzplatznummer, zahlungsart, zahl_status, betrag
 */
function mailReservierungsbestaetigung(array $user, array $event, array $tickets, string $ticketLink): bool {
    $farbe   = mailFarbe();
    $e       = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $bilder  = [];
    $karten  = '';
    $text    = '';
    $offen   = 0.0;
    $nummern = [];
    $zahlungsart = 'bar';

    foreach ($tickets as $i => $t) {
        $bn    = $t['buchungsnummer'];
        $platz = $t['sitzplatznummer'] !== null
            ? 'Tisch ' . (int)$t['tischnummer'] . ', Platz ' . (int)$t['sitzplatznummer']
            : 'Freies Ticket (freie Platzwahl)';
        $preis = (float)($t['betrag'] ?? $t['preis']);
        if (($t['zahl_status'] ?? 'offen') === 'offen') {
            $offen += $preis;
        }
        $zahlungsart = $t['zahlungsart'] ?: $zahlungsart;
        $nummern[]   = $bn;

        $png = qrCodePng(ticketPayload($bn), 6);
        $qrHtml = '';
        if ($png !== null) {
            $cid = 'qr' . $i . '.' . preg_replace('/[^A-Za-z0-9]/', '', $bn) . '@crs';
            $bilder[] = ['cid' => $cid, 'name' => $bn . '.png', 'daten' => $png];
            $qrHtml = "<img src='cid:{$cid}' width='170' height='170' alt='QR-Code {$e($bn)}' style='display:block;margin:0 auto;border:0'>";
        }
        $nr = $i + 1;
        $karten .= "<tr><td style='padding:14px 0;border-top:1px solid #eee;text-align:center'>
            <div style='font-size:13px;color:#777'>Ticket {$nr} von " . count($tickets) . "</div>
            {$qrHtml}
            <div style='font-family:monospace;font-size:15px;font-weight:bold;margin-top:6px'>{$e($bn)}</div>
            <div style='font-size:15px;margin-top:2px'>{$e($platz)} &middot; " . formatBetrag($preis) . "</div>
            <div style='margin-top:6px'><a href='{$e(ticketEinzelLinkUrl($bn))}' style='color:{$farbe};font-size:13px'>Dieses Ticket weitergeben</a></div>
        </td></tr>";
        $text .= "  {$bn} – {$platz} – " . formatBetrag($preis) . "\n";
    }

    // Zahlungshinweis
    $zahlungHtml = '';
    $zahlungText = '';
    if ($offen > 0) {
        $betrag = formatBetrag($offen);
        if ($zahlungsart === 'ueberweisung') {
            $bank  = bankverbindung();
            $zweck = verwendungszweck($nummern, $user['nachname'] ?? '');
            if ($bank['iban'] !== '') {
                $zeilen = '';
                foreach (['Kontoinhaber' => $bank['inhaber'], 'IBAN' => $bank['iban'], 'BIC' => $bank['bic'], 'Bank' => $bank['bank'], 'Verwendungszweck' => $zweck, 'Betrag' => $betrag] as $k => $v) {
                    if ($v === '') continue;
                    $zeilen .= "<tr><td style='padding:3px 12px 3px 0;color:#777'>{$k}</td><td style='padding:3px 0;font-weight:bold'>{$e($v)}</td></tr>";
                    $zahlungText .= "{$k}: {$v}\n";
                }
                $zahlungHtml = "<p><strong>Bitte überweisen Sie {$betrag}:</strong></p><table style='border-collapse:collapse;font-size:14px'>{$zeilen}</table>";
                $zahlungText = "Bitte überweisen Sie {$betrag}:\n" . $zahlungText;
            } else {
                $zahlungHtml = "<p><strong>Bitte überweisen Sie {$betrag}</strong> mit dem Verwendungszweck <strong>{$e($zweck)}</strong>. Die Bankverbindung erhalten Sie vom Verein.</p>";
                $zahlungText = "Bitte überweisen Sie {$betrag} mit dem Verwendungszweck {$zweck}.\n";
            }
        } else {
            $zahlungHtml = "<p><strong>Offener Betrag: {$betrag}.</strong> Bitte bezahlen Sie bar am Einlass.</p>";
            $zahlungText = "Offener Betrag: {$betrag}. Bitte bezahlen Sie bar am Einlass.\n";
        }
    }

    $anzahl = count($tickets);
    $html = emailTemplate('Ihre Tickets',
        "<p>Hallo {$e($user['vorname'])},</p>
        <p>Ihre Reservierung für <strong>{$e($event['name'])}</strong> am <strong>" . formatDatum($event['datum']) . "</strong> ist bestätigt.
        Jede Person braucht beim Einlass ihren eigenen QR-Code – zeigen Sie ihn auf dem Handy oder ausgedruckt vor.</p>
        <p style='text-align:center;margin:20px 0'>
            <a href='{$e($ticketLink)}' style='background:{$farbe};color:#fff;padding:12px 26px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block'>Alle {$anzahl} Ticket(s) öffnen</a>
        </p>
        <p style='font-size:13px;color:#777;text-align:center'>Der Link funktioniert ohne Anmeldung. Über „Dieses Ticket weitergeben“ können Sie einzelne Tickets an Ihre Begleitung schicken.</p>
        {$zahlungHtml}
        <table style='width:100%;border-collapse:collapse;margin-top:12px'>{$karten}</table>"
    );
    $textBody = "Hallo {$user['vorname']},\n\nIhre Reservierung für {$event['name']} am " . formatDatum($event['datum']) . " ist bestätigt.\n\n"
              . "Ihre Tickets (QR-Codes) finden Sie hier, ohne Anmeldung:\n{$ticketLink}\n\n"
              . $text . "\n" . $zahlungText;

    return sendMail($user['email'], $user['vorname'], APP_NAME . ' – Ihre Tickets für ' . $event['name'], $html, $textBody, $bilder);
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
            <a href='" . htmlspecialchars($tischUrl) . "' style='background:" . mailFarbe() . ";color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:bold;'>Jetzt reservieren</a>
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
            <a href='" . APP_URL . "/pages/events.php' style='background:" . mailFarbe() . ";color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:bold;'>Zu den Events</a>
        </p>"
    );
    $text = "Hallo {$vorname},\n\nIhr Konto bei " . APP_NAME . " wurde erstellt.\n" . APP_URL . "/pages/events.php";
    return sendMail($email, $vorname, 'Willkommen bei ' . APP_NAME, $html, $text);
}

/** Vereinsfarbe aus den Design-Einstellungen (geprüft), sonst Standardrot. */
function mailFarbe(): string {
    $f = function_exists('getSetting') ? getSetting('color_primary', '#cf2e2e') : '#cf2e2e';
    return preg_match('/^#[0-9a-fA-F]{6}$/', $f) ? $f : '#cf2e2e';
}

function emailTemplate(string $titel, string $inhalt): string {
    $appName = htmlspecialchars(APP_NAME);
    $year    = date('Y');
    $farbe   = mailFarbe();
    return "<!DOCTYPE html><html lang='de'><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif'>
<table style='max-width:600px;margin:32px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)' width='100%' cellpadding='0' cellspacing='0'>
  <tr><td style='background:#1a1a1a;padding:24px 32px;text-align:center;border-bottom:4px solid {$farbe}'>
    <h1 style='color:#fff;margin:0;font-size:22px'>{$appName}</h1>
  </td></tr>
  <tr><td style='padding:32px;color:#333;font-size:15px;line-height:1.6'>
    <h2 style='color:#1a1a1a;margin-top:0'>{$titel}</h2>
    {$inhalt}
  </td></tr>
  <tr><td style='background:#f8f9fa;padding:16px 32px;text-align:center;color:#999;font-size:12px'>
    &copy; {$year} {$appName} &middot; Diese E-Mail wurde automatisch generiert.
  </td></tr>
</table></body></html>";
}

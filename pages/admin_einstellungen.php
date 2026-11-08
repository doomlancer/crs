<?php
/**
 * Admin: Design & Branding Einstellungen
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');
require_once __DIR__ . '/../includes/mailer.php';
$pdo = getDB();

$errors    = [];
$smtpForm  = null;   // bei Fehleingabe: die abgeschickten Werte (ohne Passwort)
$ich       = currentIdentity();

// ─── Auto-Migration: settings-Tabelle bei Bedarf erstellen ───────────────────
if (!settingsTableExists()) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `settings` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `setting_key`   VARCHAR(100) NOT NULL,
              `setting_value` TEXT DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_key` (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
              ('color_primary','#cf2e2e'),('color_primary_dark','#a82424'),
              ('color_primary_light','#e84444'),('color_dark','#1a1a1a'),
              ('color_dark2','#2d2d2d'),('color_bg','#f5f5f5'),
              ('app_name','Kameruner-Tickets'),('app_slogan',''),
              ('app_logo',''),('app_favicon',''),
              ('font_family','system'),('theme_version','1')
            ON DUPLICATE KEY UPDATE setting_key = setting_key
        ");
        redirect('/pages/admin_einstellungen.php');
    } catch (PDOException $e) {
        error_log('Settings-Tabelle konnte nicht erstellt werden: ' . $e->getMessage());
        setFlash('error', 'Tabelle konnte nicht erstellt werden.');
        redirect('/pages/admin_dashboard.php');
    }
}

// ─── POST-Handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiger Sicherheitstoken.');
        redirect('/pages/admin_einstellungen.php');
    }

    $postAction = $_POST['post_action'] ?? '';

    // ── Allgemeine Einstellungen (Farben, Marke, Schrift) ──────────────────────
    if ($postAction === 'save_settings') {
        $hexRe = '/^#[0-9a-fA-F]{6}$/';
        $colorFields = [
            'color_primary', 'color_primary_dark', 'color_primary_light',
            'color_dark', 'color_dark2', 'color_bg',
        ];
        foreach ($colorFields as $field) {
            $val = trim($_POST[$field] ?? '');
            if ($val !== '' && !preg_match($hexRe, $val)) {
                $errors[] = 'Ungültiger Farbwert für ' . $field . ': ' . htmlspecialchars($val);
            }
        }

        $allowedFonts = ['system', 'humanist', 'geometric', 'classic', 'condensed', 'mono'];
        $fontVal = trim($_POST['font_family'] ?? 'system');
        if (!in_array($fontVal, $allowedFonts, true)) {
            $errors[] = 'Ungültige Schriftart.';
        }

        // Bankverbindung für Überweisungen (erscheint in Bestätigungs-Mail,
        // "Meine Tickets" und auf der Ticket-Seite). Leer = keine Angabe.
        $iban = strtoupper(preg_replace('/\s+/', '', (string)($_POST['bank_iban'] ?? '')));
        $bic  = strtoupper(preg_replace('/\s+/', '', (string)($_POST['bank_bic'] ?? '')));
        if ($iban !== '' && !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            $errors[] = 'Die IBAN hat kein gültiges Format.';
        }
        if ($bic !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) {
            $errors[] = 'Die BIC hat kein gültiges Format.';
        }

        if (empty($errors)) {
            setSetting('bank_inhaber', mb_substr(trim((string)($_POST['bank_inhaber'] ?? '')), 0, 100));
            setSetting('bank_iban', $iban !== '' ? trim(chunk_split($iban, 4, ' ')) : '');
            setSetting('bank_bic', $bic);
            setSetting('bank_name', mb_substr(trim((string)($_POST['bank_name'] ?? '')), 0, 100));
            foreach ($colorFields as $field) {
                $val = trim($_POST[$field] ?? '');
                if (preg_match($hexRe, $val)) setSetting($field, $val);
            }
            setSetting('app_name',    sanitize($_POST['app_name']    ?? getSetting('app_name', APP_NAME)));
            setSetting('app_slogan',  sanitize($_POST['app_slogan']  ?? ''));
            setSetting('font_family', $fontVal);
            setSetting('theme_version', (string)((int)getSetting('theme_version', '1') + 1));

            logAudit('UPDATE', 'settings', null, 'Design-Einstellungen geändert');
            setFlash('success', 'Einstellungen gespeichert.');
            redirect('/pages/admin_einstellungen.php');
        }
    }

    // ── Logo hochladen ─────────────────────────────────────────────────────────
    if ($postAction === 'upload_logo') {
        $res = saveUploadedImage($_FILES['logo_file'] ?? [], 'logo', 2 * 1024 * 1024);
        if ($res['ok']) {
            $old = getSetting('app_logo', '');
            if ($old && $old !== $res['name']) {
                deleteUploadedFile($old);
            }
            setSetting('app_logo', $res['name']);
            setSetting('theme_version', (string)((int)getSetting('theme_version', '1') + 1));
            logAudit('UPDATE', 'settings', null, 'Logo hochgeladen: ' . $res['name']);
            setFlash('success', 'Logo gespeichert.');
        } else {
            setFlash('error', $res['error']);
        }
        redirect('/pages/admin_einstellungen.php');
    }

    // ── Logo entfernen ─────────────────────────────────────────────────────────
    if ($postAction === 'remove_logo') {
        deleteUploadedFile(getSetting('app_logo', ''));
        setSetting('app_logo', '');
        setSetting('theme_version', (string)((int)getSetting('theme_version', '1') + 1));
        logAudit('UPDATE', 'settings', null, 'Logo entfernt');
        setFlash('success', 'Logo entfernt.');
        redirect('/pages/admin_einstellungen.php');
    }

    // ── Favicon hochladen ──────────────────────────────────────────────────────
    if ($postAction === 'upload_favicon') {
        $res = saveUploadedImage(
            $_FILES['favicon_file'] ?? [],
            'favicon',
            512 * 1024,
            [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP, IMAGETYPE_ICO]
        );
        if ($res['ok']) {
            $old = getSetting('app_favicon', '');
            if ($old && $old !== $res['name']) {
                deleteUploadedFile($old);
            }
            setSetting('app_favicon', $res['name']);
            setSetting('theme_version', (string)((int)getSetting('theme_version', '1') + 1));
            logAudit('UPDATE', 'settings', null, 'Favicon hochgeladen: ' . $res['name']);
            setFlash('success', 'Favicon gespeichert.');
        } else {
            setFlash('error', $res['error']);
        }
        redirect('/pages/admin_einstellungen.php');
    }

    // ── Favicon entfernen ──────────────────────────────────────────────────────
    if ($postAction === 'remove_favicon') {
        deleteUploadedFile(getSetting('app_favicon', ''));
        setSetting('app_favicon', '');
        setSetting('theme_version', (string)((int)getSetting('theme_version', '1') + 1));
        logAudit('UPDATE', 'settings', null, 'Favicon entfernt');
        setFlash('success', 'Favicon entfernt.');
        redirect('/pages/admin_einstellungen.php');
    }

    // ── E-Mail-Versand (SMTP) ──────────────────────────────────────────────────
    if (in_array($postAction, ['save_smtp', 'save_smtp_test', 'remove_smtp'], true)) {
        if (mailWeg() === 'env' || !smtpAdminMoeglich()) {
            setFlash('error', 'Der Mailversand wird auf diesem Server nicht hier eingestellt.');
            redirect('/pages/admin_einstellungen.php#mail');
        }

        if ($postAction === 'remove_smtp') {
            smtpEinstellungenSpeichern(['host' => '']);
            logAudit('UPDATE', 'settings', null, 'SMTP-Zugang entfernt');
            setFlash('success', 'SMTP-Zugang entfernt. Es werden keine Mails mehr verschickt.');
            redirect('/pages/admin_einstellungen.php#mail');
        }

        $smtpForm = [
            'host'             => trim((string)($_POST['smtp_host'] ?? '')),
            'port'             => (int)($_POST['smtp_port'] ?? 0),
            'verschluesselung' => (string)($_POST['smtp_verschluesselung'] ?? 'starttls'),
            'benutzer'         => trim((string)($_POST['smtp_benutzer'] ?? '')),
            'absender'         => trim((string)($_POST['smtp_absender'] ?? '')),
            'absender_name'    => trim((string)($_POST['smtp_absender_name'] ?? '')),
        ];
        $passwort = (string)($_POST['smtp_passwort'] ?? '');
        $bisher   = smtpEinstellungen();

        if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', $smtpForm['host'])) {
            $errors[] = 'Bitte den Mailserver als Namen angeben, z. B. smtp.ionos.de.';
        }
        if ($smtpForm['port'] < 1 || $smtpForm['port'] > 65535) {
            $errors[] = 'Der Port muss zwischen 1 und 65535 liegen (meist 587 oder 465).';
        }
        if (!in_array($smtpForm['verschluesselung'], ['starttls', 'ssl', 'keine'], true)) {
            $errors[] = 'Ungültige Verschlüsselung.';
        }
        foreach ([$smtpForm['benutzer'], $passwort, $smtpForm['absender_name']] as $wert) {
            if (preg_match('/[\x00-\x1f\x7f]/', $wert)) {
                $errors[] = 'Die Angaben dürfen keine Zeilenumbrüche oder Steuerzeichen enthalten.';
                break;
            }
        }
        if (mb_strlen($smtpForm['benutzer']) > 200 || strlen($passwort) > 200 || mb_strlen($smtpForm['absender_name']) > 100) {
            $errors[] = 'Eine der Angaben ist zu lang.';
        }
        if ($smtpForm['absender'] === '' && filter_var($smtpForm['benutzer'], FILTER_VALIDATE_EMAIL)) {
            $smtpForm['absender'] = $smtpForm['benutzer'];
        }
        if (!filter_var($smtpForm['absender'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Bitte eine gültige Absender-Adresse angeben.';
        }
        if ($smtpForm['verschluesselung'] === 'keine' && $smtpForm['benutzer'] !== '') {
            // msmtp schickt Passwörter nie unverschlüsselt – und das ist gut so.
            $errors[] = 'Mit Benutzername und Passwort bitte STARTTLS oder SSL/TLS wählen – ohne Verschlüsselung würde das Passwort lesbar übertragen.';
        }
        if ($smtpForm['benutzer'] !== '' && $passwort === '' && empty($bisher['passwort_gesetzt'])) {
            $errors[] = 'Bitte das Passwort des Postfachs eingeben.';
        }

        if (empty($errors)) {
            $ok = smtpEinstellungenSpeichern($smtpForm + ['passwort' => $passwort === '' ? null : $passwort]);
            if (!$ok) {
                setFlash('error', 'Der SMTP-Zugang konnte nicht gespeichert werden (Verzeichnis ' . GEHEIM_DIR . ' nicht beschreibbar).');
                redirect('/pages/admin_einstellungen.php#mail');
            }
            logAudit('UPDATE', 'settings', null, 'SMTP-Zugang gespeichert (' . $smtpForm['host'] . ')');

            if ($postAction === 'save_smtp_test' && $ich) {
                if (mailTest($ich['email'], $ich['vorname'])) {
                    setFlash('success', 'Gespeichert. Testmail an ' . $ich['email'] . ' ist verschickt – bitte auch im Spam-Ordner nachsehen.');
                } else {
                    setFlash('error', 'Gespeichert, aber die Testmail ging nicht raus: ' . (mailLetzterFehler() ?: 'unbekannter Fehler'));
                }
            } else {
                setFlash('success', 'SMTP-Zugang gespeichert.');
            }
            redirect('/pages/admin_einstellungen.php#mail');
        }
    }

    // ── Testmail (für den Weg über .env oder den Webserver) ────────────────────
    if ($postAction === 'test_mail' && $ich) {
        if (mailTest($ich['email'], $ich['vorname'])) {
            setFlash('success', 'Testmail an ' . $ich['email'] . ' ist verschickt – bitte auch im Spam-Ordner nachsehen.');
        } else {
            setFlash('error', 'Die Testmail ging nicht raus: ' . (mailLetzterFehler() ?: 'unbekannter Fehler'));
        }
        redirect('/pages/admin_einstellungen.php#mail');
    }

    // ── Ticket-Schlüssel erneuern ──────────────────────────────────────────────
    if ($postAction === 'rotate_ticket_key') {
        if (empty($_POST['bestaetigt'])) {
            setFlash('error', 'Bitte bestätigen Sie, dass bisherige QR-Codes ungültig werden.');
            redirect('/pages/admin_einstellungen.php#sicherheit');
        }
        if (ticketSchluesselQuelle() !== 'datei') {
            setFlash('error', 'Der Schlüssel steht in der Server-Konfiguration (.env) und kann nur dort geändert werden.');
            redirect('/pages/admin_einstellungen.php#sicherheit');
        }
        $betroffen = (int)$pdo->query(
            "SELECT COUNT(*) FROM reservations r JOIN events e ON e.id = r.event_id
             WHERE r.status = 'geplant' AND e.datum >= CURDATE()"
        )->fetchColumn();
        if (ticketSchluesselErneuern()) {
            logAudit('UPDATE', 'settings', null, "Ticket-Schlüssel erneuert ({$betroffen} gültige Tickets betroffen)");
            setFlash('success', 'Neuer Ticket-Schlüssel ist aktiv. Alle bisherigen QR-Codes und Ticket-Links sind ab sofort ungültig.');
        } else {
            setFlash('error', 'Der neue Schlüssel konnte nicht gespeichert werden. Der bisherige bleibt gültig.');
        }
        redirect('/pages/admin_einstellungen.php#sicherheit');
    }
}

// ─── E-Mail-Versand und Ticket-Schlüssel: Anzeige ────────────────────────────
$mailWeg     = mailWeg();
$smtpMoeglich = smtpAdminMoeglich();
$smtpGesp    = smtpEinstellungen();
$smtpWerte   = $smtpForm ?? ($smtpGesp + [
    'host' => '', 'port' => 587, 'verschluesselung' => 'starttls',
    'benutzer' => '', 'absender' => '', 'absender_name' => '',
]);
$schluesselQuelle = ticketSchluesselQuelle();
$aktiveTickets = (int)$pdo->query(
    "SELECT COUNT(*) FROM reservations r JOIN events e ON e.id = r.event_id
     WHERE r.status = 'geplant' AND e.datum >= CURDATE()"
)->fetchColumn();

// ─── Aktuelle Einstellungen laden ─────────────────────────────────────────────
$s = getAllSettings();
$defaults = [
    'color_primary'       => '#cf2e2e',
    'color_primary_dark'  => '#a82424',
    'color_primary_light' => '#e84444',
    'color_dark'          => '#1a1a1a',
    'color_dark2'         => '#2d2d2d',
    'color_bg'            => '#f5f5f5',
    'app_name'            => APP_NAME,
    'app_slogan'          => '',
    'font_family'         => 'system',
];
foreach ($defaults as $k => $v) {
    if (!isset($s[$k]) || $s[$k] === '') $s[$k] = $v;
}

$pageTitle = 'Design & Einstellungen';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container py-4">

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-palette text-warning me-2"></i>Design & Einstellungen
            </h1>
            <p class="text-muted mb-0 small">Farben, Logo, Bankverbindung, E-Mail-Versand und Ticket-Schlüssel</p>
        </div>
        <a href="/pages/admin_dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Zurück
        </a>
    </div>

    <?= getFlash() ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" id="settingsForm">
        <?= csrfField() ?>
        <input type="hidden" name="post_action" value="save_settings">

        <div class="row g-4">

            <!-- ── Presets ─────────────────────────────────────────────────── -->
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-stars text-warning me-2"></i>Design-Vorlagen
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Klicken Sie auf eine Vorlage, um alle Farbfelder zu befüllen – danach können Sie die Werte weiter anpassen.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sm preset-btn"
                                    style="background:#cf2e2e;color:#fff;border:none;"
                                    data-p="#cf2e2e" data-pd="#a82424" data-pl="#e84444"
                                    data-d="#1a1a1a" data-d2="#2d2d2d" data-bg="#f5f5f5">
                                🎪 Karneval Rot
                            </button>
                            <button type="button" class="btn btn-sm preset-btn"
                                    style="background:#1d4ed8;color:#fff;border:none;"
                                    data-p="#1d4ed8" data-pd="#1e40af" data-pl="#3b82f6"
                                    data-d="#0f172a" data-d2="#1e293b" data-bg="#f8fafc">
                                👑 Königsblau
                            </button>
                            <button type="button" class="btn btn-sm preset-btn"
                                    style="background:#059669;color:#fff;border:none;"
                                    data-p="#059669" data-pd="#047857" data-pl="#10b981"
                                    data-d="#1a1a1a" data-d2="#2d2d2d" data-bg="#f0fdf4">
                                🌿 Smaragd
                            </button>
                            <button type="button" class="btn btn-sm preset-btn"
                                    style="background:#7c3aed;color:#fff;border:none;"
                                    data-p="#7c3aed" data-pd="#6d28d9" data-pl="#8b5cf6"
                                    data-d="#1a1a1a" data-d2="#2d2d2d" data-bg="#f5f3ff">
                                💜 Violett
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Farben ──────────────────────────────────────────────────── -->
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-droplet-half text-warning me-2"></i>Farbpalette
                    </div>
                    <div class="card-body">
                        <div class="row g-3">

                            <?php
                            $colorFields = [
                                ['key' => 'color_primary',       'label' => 'Akzentfarbe (Primär)',     'hint' => 'Buttons, Navbar-Rand, Highlights'],
                                ['key' => 'color_primary_dark',  'label' => 'Akzent dunkel',            'hint' => 'Hover-Zustand, Verläufe'],
                                ['key' => 'color_primary_light', 'label' => 'Akzent hell',              'hint' => 'Leichte Highlights'],
                                ['key' => 'color_dark',          'label' => 'Dunkel (Navbar/Footer)',   'hint' => 'Haupthintergrund Navbar & Footer'],
                                ['key' => 'color_dark2',         'label' => 'Dunkel-2',                 'hint' => 'Sekundäre dunkle Flächen'],
                                ['key' => 'color_bg',            'label' => 'Seitenhintergrund',        'hint' => 'Hintergrundfarbe der Seite'],
                            ];
                            foreach ($colorFields as $cf):
                                $val = htmlspecialchars($s[$cf['key']]);
                            ?>
                            <div class="col-12 col-md-6 col-lg-4">
                                <label class="form-label fw-semibold small">
                                    <?= $cf['label'] ?>
                                    <span class="text-muted fw-normal"> – <?= $cf['hint'] ?></span>
                                </label>
                                <div class="input-group">
                                    <input type="color"
                                           class="form-control form-control-color color-picker"
                                           name="<?= $cf['key'] ?>"
                                           value="<?= $val ?>"
                                           data-var-name="<?= $cf['key'] ?>"
                                           title="<?= $cf['label'] ?>">
                                    <input type="text"
                                           class="form-control font-monospace color-text"
                                           value="<?= $val ?>"
                                           maxlength="7"
                                           pattern="^#[0-9a-fA-F]{6}$"
                                           data-for="<?= $cf['key'] ?>">
                                </div>
                            </div>
                            <?php endforeach; ?>

                        </div>

                        <!-- Live-Vorschau -->
                        <div class="mt-4 p-3 rounded" id="colorPreview"
                             style="background:<?= htmlspecialchars($s['color_dark']) ?>;border-top:3px solid <?= htmlspecialchars($s['color_primary']) ?>;">
                            <span style="color:#fff;font-weight:700;font-size:1rem;">
                                <span id="previewName" style="color:<?= htmlspecialchars($s['color_primary']) ?>;"><?= htmlspecialchars($s['app_name'] ?: APP_NAME) ?></span>
                            </span>
                            &nbsp;
                            <span class="badge ms-2" id="previewBadge"
                                  style="background:<?= htmlspecialchars($s['color_primary']) ?>;color:#fff;">
                                Vorschau
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Marke & Texte ───────────────────────────────────────────── -->
            <div class="col-12 col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-type text-warning me-2"></i>Marke & Texte
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">App-Name / Markenname</label>
                            <input type="text" name="app_name" class="form-control"
                                   value="<?= htmlspecialchars($s['app_name']) ?>"
                                   maxlength="60" placeholder="z.B. Kameruner-Tickets">
                            <div class="form-text">Wird in Navbar, Browser-Tab und E-Mails angezeigt.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Slogan (Startseite)</label>
                            <input type="text" name="app_slogan" class="form-control"
                                   value="<?= htmlspecialchars($s['app_slogan']) ?>"
                                   maxlength="200" placeholder="Ihr Slogan für die Startseite (optional)">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Bankverbindung ──────────────────────────────────────────── -->
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-bank text-warning me-2"></i>Bankverbindung für Überweisungen
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">Gäste mit Zahlungsart „Überweisung“ sehen diese Angaben in der Bestätigungs-Mail, unter „Meine Tickets“ und auf ihrem Ticket-Link – zusammen mit Betrag und Verwendungszweck. Leer lassen, wenn die Bankverbindung anders mitgeteilt wird.</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small" for="bank_inhaber">Kontoinhaber</label>
                                <input type="text" name="bank_inhaber" id="bank_inhaber" class="form-control" maxlength="100"
                                       value="<?= htmlspecialchars(getSetting('bank_inhaber')) ?>" placeholder="z.B. KG Die Kameruner e.V.">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small" for="bank_iban">IBAN</label>
                                <input type="text" name="bank_iban" id="bank_iban" class="form-control font-monospace" maxlength="42"
                                       value="<?= htmlspecialchars(getSetting('bank_iban')) ?>" placeholder="DE00 0000 0000 0000 0000 00">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small" for="bank_bic">BIC</label>
                                <input type="text" name="bank_bic" id="bank_bic" class="form-control font-monospace" maxlength="11"
                                       value="<?= htmlspecialchars(getSetting('bank_bic')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small" for="bank_name">Bank</label>
                                <input type="text" name="bank_name" id="bank_name" class="form-control" maxlength="100"
                                       value="<?= htmlspecialchars(getSetting('bank_name')) ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Schriftart ──────────────────────────────────────────────── -->
            <div class="col-12 col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-fonts text-warning me-2"></i>Schriftart
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Schriftart auswählen</label>
                            <select name="font_family" id="fontSelect" class="form-select">
                                <?php
                                $fonts = [
                                    'system'    => 'Modern (Standard)',
                                    'humanist'  => 'Freundlich',
                                    'geometric' => 'Geometrisch',
                                    'classic'   => 'Klassisch (Serif)',
                                    'condensed' => 'Schmal',
                                    'mono'      => 'Technisch (Monospace)',
                                ];
                                $fontStacks = [
                                    'system'    => "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
                                    'humanist'  => "'Segoe UI', Candara, 'Trebuchet MS', Verdana, sans-serif",
                                    'geometric' => "Futura, 'Century Gothic', 'Avenir Next', Avenir, sans-serif",
                                    'classic'   => "Georgia, 'Times New Roman', serif",
                                    'condensed' => "'Arial Narrow', 'Roboto Condensed', Impact, sans-serif",
                                    'mono'      => "'SF Mono', 'Cascadia Mono', Menlo, Consolas, monospace",
                                ];
                                foreach ($fonts as $fk => $fl):
                                ?>
                                <option value="<?= $fk ?>" <?= $s['font_family'] === $fk ? 'selected' : '' ?>>
                                    <?= $fl ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="p-3 border rounded" id="fontPreview"
                             style="font-family: <?= htmlspecialchars($fontStacks[$s['font_family']] ?? $fontStacks['system']) ?>;">
                            <p class="mb-1 fw-bold">Schriftvorschau</p>
                            <p class="mb-0 text-muted small">Reservierungen, Events und mehr – in Ihrer gewählten Schrift.</p>
                        </div>
                        <div class="form-text mt-2">
                            System-Schriften – kein Download nötig, funktioniert auch offline.
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Speichern ───────────────────────────────────────────────── -->
            <div class="col-12">
                <button type="submit" class="btn btn-warning px-4 fw-bold">
                    <i class="bi bi-floppy me-2"></i>Einstellungen speichern
                </button>
            </div>

        </div>
    </form>

    <!-- ── Logo ──────────────────────────────────────────────────────────────── -->
    <div class="row g-4 mt-2">
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-semibold">
                    <i class="bi bi-image text-warning me-2"></i>Logo
                </div>
                <div class="card-body">
                    <?php $logo = getSetting('app_logo', ''); ?>
                    <?php if ($logo && file_exists(UPLOAD_DIR . $logo)): ?>
                    <div class="mb-3 p-3 bg-dark rounded text-center" style="max-width:200px;">
                        <img src="/uploads/<?= htmlspecialchars($logo) ?>"
                             alt="Logo" style="max-width:120px;max-height:80px;object-fit:contain;">
                    </div>
                    <form method="POST" class="mb-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="post_action" value="remove_logo">
                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                data-confirm="Logo entfernen?">
                            <i class="bi bi-trash me-1"></i>Logo entfernen
                        </button>
                    </form>
                    <?php else: ?>
                    <p class="text-muted small mb-3">Kein Logo hochgeladen. Ohne Logo wird kein Bild in Navbar und Startseite angezeigt.</p>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data">
                        <?= csrfField() ?>
                        <input type="hidden" name="post_action" value="upload_logo">
                        <div class="mb-2">
                            <input type="file" name="logo_file" class="form-control form-control-sm"
                                   accept="image/png,image/jpeg,image/gif,image/webp">
                        </div>
                        <div class="form-text mb-2">PNG, JPG, GIF oder WebP – max. 2 MB. Empfohlen: quadratisch, mind. 200×200 px.</div>
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-upload me-1"></i>Logo hochladen
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ── Favicon ──────────────────────────────────────────────────────── -->
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-semibold">
                    <i class="bi bi-browser-chrome text-warning me-2"></i>Favicon (Browser-Tab-Symbol)
                </div>
                <div class="card-body">
                    <?php $fav = getSetting('app_favicon', ''); ?>
                    <?php if ($fav && file_exists(UPLOAD_DIR . $fav)): ?>
                    <div class="mb-3 p-2 bg-light rounded d-inline-flex align-items-center gap-2 border">
                        <img src="/uploads/<?= htmlspecialchars($fav) ?>"
                             alt="Favicon" style="width:32px;height:32px;object-fit:contain;">
                        <small class="text-muted"><?= htmlspecialchars($fav) ?></small>
                    </div>
                    <br>
                    <form method="POST" class="mb-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="post_action" value="remove_favicon">
                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                data-confirm="Favicon entfernen?">
                            <i class="bi bi-trash me-1"></i>Favicon entfernen
                        </button>
                    </form>
                    <?php else: ?>
                    <p class="text-muted small mb-3">Kein Favicon hochgeladen (Browser verwendet Standard-Symbol).</p>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data">
                        <?= csrfField() ?>
                        <input type="hidden" name="post_action" value="upload_favicon">
                        <div class="mb-2">
                            <input type="file" name="favicon_file" class="form-control form-control-sm"
                                   accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/webp">
                        </div>
                        <div class="form-text mb-2">PNG oder ICO – max. 512 KB. Empfohlen: 32×32 px oder 64×64 px.</div>
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-upload me-1"></i>Favicon hochladen
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ── E-Mail-Versand ───────────────────────────────────────────────────── -->
    <div class="row g-4 mt-2">
        <div class="col-12" id="mail">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-semibold">
                    <i class="bi bi-envelope-at text-warning me-2"></i>E-Mail-Versand
                </div>
                <div class="card-body">
                    <?php if ($mailWeg === 'env'): ?>
                    <div class="alert alert-info small">
                        <i class="bi bi-hdd-network me-1"></i>
                        Eingerichtet über die Server-Konfiguration (.env), Mailserver
                        <strong><?= htmlspecialchars((string)$_ENV['SMTP_HOST']) ?></strong>. Diese Einstellung hat Vorrang und wird nur dort geändert.
                    </div>
                    <?php elseif ($mailWeg === 'admin'): ?>
                    <div class="alert alert-success small">
                        <i class="bi bi-check-circle me-1"></i>
                        Aktiv: Mails gehen über <strong><?= htmlspecialchars($smtpGesp['host']) ?></strong>
                        mit Absender <strong><?= htmlspecialchars($smtpGesp['absender']) ?></strong>.
                    </div>
                    <?php elseif ($smtpMoeglich): ?>
                    <div class="alert alert-warning small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>Noch nicht eingerichtet – es werden keine Mails verschickt.</strong>
                        Gäste bekommen weder Buchungsbestätigung noch Tickets, „Passwort vergessen“ und die Warteliste funktionieren nicht.
                    </div>
                    <?php else: ?>
                    <div class="alert alert-secondary small">
                        Die Mails verschickt der Webserver selbst (klassisches Hosting). Hier ist nichts einzustellen.
                    </div>
                    <?php endif; ?>

                    <?php if ($smtpMoeglich && $mailWeg !== 'env'): ?>
                    <p class="text-muted small">
                        Die Angaben finden Sie bei Ihrem E-Mail-Anbieter – es sind dieselben wie im Mailprogramm
                        für den <strong>Postausgang (SMTP)</strong>. Das Passwort wird geschützt auf dem Server abgelegt
                        und hier nie wieder angezeigt.
                    </p>
                    <form method="POST" action="/pages/admin_einstellungen.php#mail" autocomplete="off">
                        <?= csrfField() ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small" for="smtp_host">Mailserver (SMTP)</label>
                                <input type="text" name="smtp_host" id="smtp_host" class="form-control" maxlength="253" required
                                       value="<?= htmlspecialchars($smtpWerte['host']) ?>" placeholder="z. B. smtp.ionos.de">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label fw-semibold small" for="smtp_verschluesselung">Verschlüsselung</label>
                                <select name="smtp_verschluesselung" id="smtp_verschluesselung" class="form-select">
                                    <?php foreach (['starttls' => 'STARTTLS (üblich)', 'ssl' => 'SSL/TLS', 'keine' => 'keine (nur eigener Server ohne Anmeldung)'] as $k => $l): ?>
                                    <option value="<?= $k ?>" <?= $smtpWerte['verschluesselung'] === $k ? 'selected' : '' ?>><?= $l ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label fw-semibold small" for="smtp_port">Port</label>
                                <input type="number" name="smtp_port" id="smtp_port" class="form-control" min="1" max="65535" required
                                       value="<?= (int)$smtpWerte['port'] ?>">
                                <div class="form-text">STARTTLS: 587 · SSL/TLS: 465</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small" for="smtp_benutzer">Benutzername</label>
                                <input type="text" name="smtp_benutzer" id="smtp_benutzer" class="form-control" maxlength="200"
                                       value="<?= htmlspecialchars($smtpWerte['benutzer']) ?>" placeholder="meist die E-Mail-Adresse" autocomplete="off">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small" for="smtp_passwort">Passwort</label>
                                <input type="password" name="smtp_passwort" id="smtp_passwort" class="form-control" maxlength="200"
                                       autocomplete="new-password"
                                       placeholder="<?= !empty($smtpGesp['passwort_gesetzt']) ? 'gespeichert – leer lassen, um es zu behalten' : '' ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small" for="smtp_absender">Absender-Adresse</label>
                                <input type="email" name="smtp_absender" id="smtp_absender" class="form-control" maxlength="200"
                                       value="<?= htmlspecialchars($smtpWerte['absender']) ?>" placeholder="leer = Benutzername">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small" for="smtp_absender_name">Absender-Name</label>
                                <input type="text" name="smtp_absender_name" id="smtp_absender_name" class="form-control" maxlength="100"
                                       value="<?= htmlspecialchars($smtpWerte['absender_name']) ?>"
                                       placeholder="<?= htmlspecialchars(getSetting('app_name', '') ?: APP_NAME) ?>">
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="submit" name="post_action" value="save_smtp_test" class="btn btn-warning fw-bold">
                                <i class="bi bi-send me-1"></i>Speichern &amp; Testmail an <?= htmlspecialchars($ich['email'] ?? 'mich') ?>
                            </button>
                            <button type="submit" name="post_action" value="save_smtp" class="btn btn-outline-secondary">
                                Nur speichern
                            </button>
                        </div>
                    </form>
                    <?php if ($mailWeg === 'admin'): ?>
                    <form method="POST" action="/pages/admin_einstellungen.php#mail" class="mt-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="post_action" value="remove_smtp">
                        <button type="submit" class="btn btn-link btn-sm text-danger p-0"
                                data-confirm="SMTP-Zugang entfernen? Danach werden keine Mails mehr verschickt.">
                            Zugang entfernen
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php else: ?>
                    <form method="POST" action="/pages/admin_einstellungen.php#mail">
                        <?= csrfField() ?>
                        <input type="hidden" name="post_action" value="test_mail">
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-send me-1"></i>Testmail an <?= htmlspecialchars($ich['email'] ?? 'mich') ?> senden
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Ticket-Schlüssel ─────────────────────────────────────────────── -->
        <div class="col-12" id="sicherheit">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-semibold">
                    <i class="bi bi-shield-lock text-warning me-2"></i>Ticket-Schlüssel
                </div>
                <div class="card-body">
                    <p class="text-muted small">
                        Mit diesem geheimen Schlüssel sind alle QR-Codes und Ticket-Links unterschrieben. Wer ihn kennt,
                        kann gültige Tickets fälschen. Erzeugen Sie einen neuen, wenn er in fremde Hände geraten sein
                        könnte – zum Beispiel, weil er per Screenshot oder Chat geteilt wurde.
                    </p>
                    <?php if ($schluesselQuelle === 'env'): ?>
                    <div class="alert alert-info small mb-0">
                        Der Schlüssel steht in der Server-Konfiguration (.env, <code>TICKET_SECRET</code>) und kann nur dort geändert werden.
                    </div>
                    <?php else: ?>
                    <div class="alert <?= $aktiveTickets > 0 ? 'alert-warning' : 'alert-light border' ?> small">
                        <?php if ($aktiveTickets > 0): ?>
                        <strong><?= $aktiveTickets ?> gültige Tickets</strong> für kommende Events wären betroffen.
                        Bereits verschickte Mails und ausgedruckte Tickets funktionieren am Einlass dann nicht mehr –
                        die Gäste finden die neuen QR-Codes unter „Meine Tickets“.
                        <?php else: ?>
                        Für kommende Events gibt es noch keine gültigen Tickets – ein neuer Schlüssel hat keine Nachteile.
                        <?php endif; ?>
                    </div>
                    <form method="POST" action="/pages/admin_einstellungen.php#sicherheit">
                        <?= csrfField() ?>
                        <input type="hidden" name="post_action" value="rotate_ticket_key">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="bestaetigt" value="1" id="schluessel_ok" required>
                            <label class="form-check-label small" for="schluessel_ok">
                                Mir ist klar, dass alle bisher ausgegebenen QR-Codes und Ticket-Links ungültig werden.
                            </label>
                        </div>
                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                data-confirm="Wirklich einen neuen Ticket-Schlüssel erzeugen? Alle bisherigen QR-Codes werden ungültig.">
                            <i class="bi bi-arrow-repeat me-1"></i>Neuen Schlüssel erzeugen
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</main>

<?php
$googleFontMap = ['lato' => 'Lato', 'poppins' => 'Poppins', 'roboto' => 'Roboto', 'oswald' => 'Oswald'];
$extraScripts = <<<HTML
<script>
(function () {
    // ─── Presets ───────────────────────────────────────────────────────────
    document.querySelectorAll('.preset-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var map = {
                color_primary:       btn.dataset.p,
                color_primary_dark:  btn.dataset.pd,
                color_primary_light: btn.dataset.pl,
                color_dark:          btn.dataset.d,
                color_dark2:         btn.dataset.d2,
                color_bg:            btn.dataset.bg,
            };
            Object.keys(map).forEach(function (key) {
                var picker = document.querySelector('[name="' + key + '"][type="color"]');
                var text   = document.querySelector('[data-for="' + key + '"]');
                if (picker) picker.value = map[key];
                if (text)   text.value  = map[key];
            });
            updatePreview();
        });
    });

    // ─── Color picker ↔ Text field sync ───────────────────────────────────
    document.querySelectorAll('.color-picker').forEach(function (picker) {
        picker.addEventListener('input', function () {
            var text = document.querySelector('[data-for="' + picker.name + '"]');
            if (text) text.value = picker.value;
            updatePreview();
        });
    });
    document.querySelectorAll('.color-text').forEach(function (text) {
        text.addEventListener('input', function () {
            if (/^#[0-9a-fA-F]{6}$/.test(text.value)) {
                var picker = document.querySelector('[name="' + text.dataset.for + '"][type="color"]');
                if (picker) picker.value = text.value;
                updatePreview();
            }
        });
    });

    // ─── Live-Vorschau aktualisieren ────────────────────────────────────
    function getColor(key) {
        var el = document.querySelector('[name="' + key + '"][type="color"]');
        return el ? el.value : null;
    }
    function updatePreview() {
        var p  = getColor('color_primary');
        var d  = getColor('color_dark');
        var bg = getColor('color_bg');
        var preview = document.getElementById('colorPreview');
        var badge   = document.getElementById('previewBadge');
        var name    = document.getElementById('previewName');
        if (preview && p && d) {
            preview.style.background   = d;
            preview.style.borderTopColor = p;
        }
        if (badge && p)  { badge.style.background = p; }
        if (name  && p)  { name.style.color = p; }
    }

    // ─── Schriftart-Vorschau (System-Schriften, kein Nachladen nötig) ─────
    var fontSelect  = document.getElementById('fontSelect');
    var fontPreview = document.getElementById('fontPreview');
    var fontStacks = {
        'system':    "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
        'humanist':  "'Segoe UI', Candara, 'Trebuchet MS', Verdana, sans-serif",
        'geometric': "Futura, 'Century Gothic', 'Avenir Next', Avenir, sans-serif",
        'classic':   "Georgia, 'Times New Roman', serif",
        'condensed': "'Arial Narrow', 'Roboto Condensed', Impact, sans-serif",
        'mono':      "'SF Mono', 'Cascadia Mono', Menlo, Consolas, monospace"
    };
    if (fontSelect) {
        fontSelect.addEventListener('change', function () {
            var stack = fontStacks[fontSelect.value] || fontStacks['system'];
            if (fontPreview) fontPreview.style.fontFamily = stack;
        });
    }

    // ─── SMTP: Standard-Port passend zur Verschlüsselung ──────────────────
    var vs = document.getElementById('smtp_verschluesselung');
    var port = document.getElementById('smtp_port');
    if (vs && port) {
        var standard = { starttls: '587', ssl: '465', keine: '25' };
        vs.addEventListener('change', function () {
            if (['587', '465', '25', ''].indexOf(port.value) !== -1) port.value = standard[vs.value];
        });
    }
})();
</script>
HTML;
include __DIR__ . '/../includes/footer.php';
?>

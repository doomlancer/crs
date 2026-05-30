<?php
/**
 * Benutzerprofil – Stammdaten bearbeiten und Passwort ändern
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

// Login erforderlich
requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Aktuellen Benutzer laden
$stmt = $pdo->prepare(
    'SELECT id, vorname, nachname, email, zahlungsart, adresse, rolle, erstellt_am
     FROM users WHERE id = ? AND aktiv = 1'
);
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Falls Nutzer nicht mehr existiert / deaktiviert: ausloggen
if (!$user) {
    session_unset();
    session_destroy();
    redirect('/pages/login.php');
}

// Aktive Reservierungen zählen
$stmtRes = $pdo->prepare(
    "SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status IN ('geplant','eingecheckt')"
);
$stmtRes->execute([$userId]);
$aktiveReservierungen = (int)$stmtRes->fetchColumn();

// =====================
// Profil-Formular Fehler / Nachrichten
// =====================
$profilErrors  = [];
$profilSuccess = false;
$pwErrors      = [];
$pwSuccess     = false;

// Formularwerte für Wiederbefüllung (bei Fehler)
$formData = [
    'vorname'     => $user['vorname'],
    'nachname'    => $user['nachname'],
    'adresse'     => $user['adresse'] ?? '',
    'zahlungsart' => $user['zahlungsart'],
];

// =====================
// POST-Handler: Profil aktualisieren
// =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'profil') {

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $profilErrors[] = 'Ungültiger Sicherheitstoken. Bitte laden Sie die Seite neu.';
    } else {
        $vorname     = trim($_POST['vorname']     ?? '');
        $nachname    = trim($_POST['nachname']    ?? '');
        $adresse     = trim($_POST['adresse']     ?? '');
        $zahlungsart = trim($_POST['zahlungsart'] ?? '');

        $formData['vorname']     = $vorname;
        $formData['nachname']    = $nachname;
        $formData['adresse']     = $adresse;
        $formData['zahlungsart'] = $zahlungsart;

        // Validierung
        if (strlen($vorname) < 2 || strlen($vorname) > 100) {
            $profilErrors[] = 'Vorname muss zwischen 2 und 100 Zeichen lang sein.';
        }
        if (strlen($nachname) < 2 || strlen($nachname) > 100) {
            $profilErrors[] = 'Nachname muss zwischen 2 und 100 Zeichen lang sein.';
        }
        if ($adresse !== '' && strlen($adresse) > 255) {
            $profilErrors[] = 'Die Adresse darf maximal 255 Zeichen lang sein.';
        }
        if (!in_array($zahlungsart, ['bar', 'ueberweisung', 'paypal'], true)) {
            $profilErrors[] = 'Bitte wählen Sie eine gültige Zahlungsart.';
        }

        if (empty($profilErrors)) {
            $stmt = $pdo->prepare(
                'UPDATE users SET vorname = ?, nachname = ?, adresse = ?, zahlungsart = ? WHERE id = ?'
            );
            $stmt->execute([
                $vorname,
                $nachname,
                $adresse !== '' ? $adresse : null,
                $zahlungsart,
                $userId,
            ]);

            // Session-Daten aktualisieren
            $_SESSION['vorname']  = $vorname;
            $_SESSION['nachname'] = $nachname;

            // Lokale Variable aktualisieren
            $user['vorname']     = $vorname;
            $user['nachname']    = $nachname;
            $user['adresse']     = $adresse;
            $user['zahlungsart'] = $zahlungsart;

            logAudit('PROFIL_AKTUALISIERT', 'users', $userId, 'Profildaten geändert');

            setFlash('success', 'Ihre Profildaten wurden erfolgreich aktualisiert.');
            redirect('/pages/profil.php');
        }
    }
}

// =====================
// POST-Handler: Passwort ändern
// =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'passwort') {

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $pwErrors[] = 'Ungültiger Sicherheitstoken. Bitte laden Sie die Seite neu.';
    } else {
        $altesPasswort  = $_POST['altes_passwort']   ?? '';
        $neuesPasswort  = $_POST['neues_passwort']   ?? '';
        $neuesPasswort2 = $_POST['neues_passwort2']  ?? '';

        // Validierung
        if (empty($altesPasswort)) {
            $pwErrors[] = 'Bitte geben Sie Ihr aktuelles Passwort ein.';
        }
        if (strlen($neuesPasswort) < 8) {
            $pwErrors[] = 'Das neue Passwort muss mindestens 8 Zeichen lang sein.';
        }
        if ($neuesPasswort !== $neuesPasswort2) {
            $pwErrors[] = 'Die neuen Passwörter stimmen nicht überein.';
        }

        if (empty($pwErrors)) {
            // Aktuelles Passwort aus DB laden und prüfen
            $stmtPw = $pdo->prepare('SELECT passwort FROM users WHERE id = ?');
            $stmtPw->execute([$userId]);
            $row = $stmtPw->fetch();

            if (!$row || !password_verify($altesPasswort, $row['passwort'])) {
                $pwErrors[] = 'Das aktuelle Passwort ist falsch.';
            } elseif ($altesPasswort === $neuesPasswort) {
                $pwErrors[] = 'Das neue Passwort darf nicht identisch mit dem aktuellen Passwort sein.';
            } else {
                $neuerHash = password_hash($neuesPasswort, PASSWORD_BCRYPT, ['cost' => 12]);
                $pdo->prepare('UPDATE users SET passwort = ? WHERE id = ?')
                    ->execute([$neuerHash, $userId]);

                logAudit('PASSWORT_GEAENDERT', 'users', $userId, 'Passwort selbst geändert');

                setFlash('success', 'Ihr Passwort wurde erfolgreich geändert.');
                redirect('/pages/profil.php');
            }
        }
    }
}

$zahlungsarten = [
    'bar'          => 'Bar',
    'ueberweisung' => 'Überweisung',
    'paypal'       => 'PayPal',
];

$pageTitle = 'Mein Profil';
$bodyClass = 'bg-light';
$extraHead = '';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="py-5">
    <div class="container">

        <!-- Seitenheader -->
        <div class="row align-items-center mb-4">
            <div class="col">
                <h1 class="fw-bold mb-1">
                    <i class="bi bi-person-circle text-warning me-2"></i>Mein Profil
                </h1>
                <p class="text-muted mb-0">
                    Verwalten Sie Ihre persönlichen Daten und Einstellungen.
                </p>
            </div>
            <div class="col-auto">
                <a href="/pages/meine_reservierungen.php" class="btn btn-outline-warning">
                    <i class="bi bi-ticket-perforated me-1"></i>
                    Meine Reservierungen
                    <?php if ($aktiveReservierungen > 0): ?>
                        <span class="badge bg-warning text-dark ms-1"><?= $aktiveReservierungen ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>

        <?= getFlash() ?>

        <div class="row g-4">

            <!-- Linke Spalte: Profilkarte -->
            <div class="col-lg-4">

                <!-- Benutzerkarte -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body text-center py-4">
                        <div class="mb-3">
                            <div class="rounded-circle bg-warning d-inline-flex align-items-center justify-content-center shadow"
                                 style="width: 80px; height: 80px;">
                                <i class="bi bi-person-fill text-dark" style="font-size: 2.2rem;"></i>
                            </div>
                        </div>
                        <h5 class="fw-bold mb-1">
                            <?= htmlspecialchars($user['vorname'] . ' ' . $user['nachname']) ?>
                        </h5>
                        <p class="text-muted small mb-2">
                            <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($user['email']) ?>
                        </p>
                        <div class="mb-3">
                            <?php
                            $rolleMap = [
                                'admin'     => ['danger',  'bi-shield-fill', 'Administrator'],
                                'kassierer' => ['warning', 'bi-cash-register', 'Kassierer'],
                                'user'      => ['secondary','bi-person',       'Benutzer'],
                            ];
                            [$rColor, $rIcon, $rLabel] = $rolleMap[$user['rolle']] ?? ['secondary', 'bi-person', ucfirst($user['rolle'])];
                            ?>
                            <span class="badge bg-<?= $rColor ?> <?= $rColor === 'warning' ? 'text-dark' : '' ?>">
                                <i class="bi <?= $rIcon ?> me-1"></i><?= $rLabel ?>
                            </span>
                        </div>

                        <hr>

                        <div class="row text-center g-0">
                            <div class="col-6 border-end">
                                <div class="fw-bold text-warning fs-4"><?= $aktiveReservierungen ?></div>
                                <div class="small text-muted">Reservierungen</div>
                            </div>
                            <div class="col-6">
                                <div class="fw-bold text-secondary fs-5">
                                    <?= htmlspecialchars($zahlungsarten[$user['zahlungsart']] ?? ucfirst($user['zahlungsart'])) ?>
                                </div>
                                <div class="small text-muted">Zahlungsart</div>
                            </div>
                        </div>

                        <?php if (!empty($user['adresse'])): ?>
                        <hr>
                        <p class="small text-muted mb-0">
                            <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($user['adresse']) ?>
                        </p>
                        <?php endif; ?>

                        <hr>
                        <p class="small text-muted mb-0">
                            <i class="bi bi-calendar-check me-1"></i>
                            Mitglied seit <?= formatDatum($user['erstellt_am']) ?>
                        </p>
                    </div>
                </div>

                <!-- Schnelllinks -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-dark text-white fw-semibold border-0">
                        <i class="bi bi-lightning me-1"></i>Schnellzugriff
                    </div>
                    <div class="list-group list-group-flush rounded-bottom">
                        <a href="/pages/events.php" class="list-group-item list-group-item-action py-3">
                            <i class="bi bi-calendar-event text-warning me-2"></i>Veranstaltungen ansehen
                        </a>
                        <a href="/pages/meine_reservierungen.php" class="list-group-item list-group-item-action py-3">
                            <i class="bi bi-ticket-perforated text-warning me-2"></i>Meine Reservierungen
                        </a>
                        <a href="/includes/auth.php?action=logout" class="list-group-item list-group-item-action py-3 text-danger">
                            <i class="bi bi-box-arrow-right me-2"></i>Abmelden
                        </a>
                    </div>
                </div>

            </div>

            <!-- Rechte Spalte: Formulare -->
            <div class="col-lg-8">

                <!-- ==========================================
                     FORMULAR 1: Profildaten bearbeiten
                     ========================================== -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-warning text-dark border-0 py-3">
                        <h5 class="mb-0 fw-bold">
                            <i class="bi bi-pencil-square me-2"></i>Persönliche Daten
                        </h5>
                    </div>
                    <div class="card-body p-4">

                        <?php if (!empty($profilErrors)): ?>
                        <div class="alert alert-danger alert-dismissible" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Bitte korrigieren Sie folgende Fehler:</strong>
                            <ul class="mb-0 mt-2 ps-3">
                                <?php foreach ($profilErrors as $err): ?>
                                    <li><?= htmlspecialchars($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php endif; ?>

                        <form method="POST" action="" novalidate>
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="profil">

                            <div class="row g-3">

                                <!-- Vorname -->
                                <div class="col-sm-6">
                                    <label for="vorname" class="form-label fw-semibold">
                                        <i class="bi bi-person me-1"></i>Vorname <span class="text-danger">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="vorname"
                                        name="vorname"
                                        class="form-control <?= (!empty($profilErrors) && strlen($formData['vorname']) < 2) ? 'is-invalid' : '' ?>"
                                        value="<?= htmlspecialchars($formData['vorname']) ?>"
                                        required
                                        minlength="2"
                                        maxlength="100"
                                        autocomplete="given-name"
                                    >
                                    <div class="invalid-feedback">Mindestens 2 Zeichen erforderlich.</div>
                                </div>

                                <!-- Nachname -->
                                <div class="col-sm-6">
                                    <label for="nachname" class="form-label fw-semibold">
                                        Nachname <span class="text-danger">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="nachname"
                                        name="nachname"
                                        class="form-control <?= (!empty($profilErrors) && strlen($formData['nachname']) < 2) ? 'is-invalid' : '' ?>"
                                        value="<?= htmlspecialchars($formData['nachname']) ?>"
                                        required
                                        minlength="2"
                                        maxlength="100"
                                        autocomplete="family-name"
                                    >
                                    <div class="invalid-feedback">Mindestens 2 Zeichen erforderlich.</div>
                                </div>

                                <!-- E-Mail (nur anzeigen, nicht änderbar) -->
                                <div class="col-12">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-envelope me-1"></i>E-Mail-Adresse
                                    </label>
                                    <div class="input-group">
                                        <input
                                            type="email"
                                            class="form-control bg-light"
                                            value="<?= htmlspecialchars($user['email']) ?>"
                                            readonly
                                            aria-describedby="email-hint"
                                        >
                                        <span class="input-group-text bg-light" id="email-hint">
                                            <i class="bi bi-lock text-muted"></i>
                                        </span>
                                    </div>
                                    <div class="form-text text-muted">
                                        <i class="bi bi-info-circle me-1"></i>
                                        Die E-Mail-Adresse kann nicht geändert werden.
                                        Kontaktieren Sie den Administrator für Änderungen.
                                    </div>
                                </div>

                                <!-- Zahlungsart -->
                                <div class="col-sm-6">
                                    <label for="zahlungsart" class="form-label fw-semibold">
                                        <i class="bi bi-credit-card me-1"></i>Zahlungsart <span class="text-danger">*</span>
                                    </label>
                                    <select
                                        id="zahlungsart"
                                        name="zahlungsart"
                                        class="form-select <?= (!empty($profilErrors) && !in_array($formData['zahlungsart'], ['bar','ueberweisung','paypal'], true)) ? 'is-invalid' : '' ?>"
                                        required
                                    >
                                        <?php foreach ($zahlungsarten as $value => $label): ?>
                                        <option value="<?= htmlspecialchars($value) ?>"
                                            <?= $formData['zahlungsart'] === $value ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Bitte wählen Sie eine Zahlungsart.</div>
                                </div>

                                <!-- Adresse (optional) -->
                                <div class="col-sm-6">
                                    <label for="adresse" class="form-label fw-semibold">
                                        <i class="bi bi-geo-alt me-1"></i>Adresse
                                        <span class="text-muted fw-normal small">(optional)</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="adresse"
                                        name="adresse"
                                        class="form-control"
                                        value="<?= htmlspecialchars($formData['adresse']) ?>"
                                        maxlength="255"
                                        placeholder="Musterstraße 1, 12345 Musterstadt"
                                        autocomplete="street-address"
                                    >
                                </div>

                                <!-- Pflichtfeld-Hinweis -->
                                <div class="col-12">
                                    <p class="text-muted small mb-0">
                                        <span class="text-danger">*</span> Pflichtfelder
                                    </p>
                                </div>

                                <!-- Submit -->
                                <div class="col-12">
                                    <button type="submit" class="btn btn-warning fw-bold px-4">
                                        <i class="bi bi-check-lg me-2"></i>Daten speichern
                                    </button>
                                </div>

                            </div>
                        </form>

                    </div>
                </div>

                <!-- ==========================================
                     FORMULAR 2: Passwort ändern
                     ========================================== -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-dark text-white border-0 py-3">
                        <h5 class="mb-0 fw-bold">
                            <i class="bi bi-key me-2"></i>Passwort ändern
                        </h5>
                    </div>
                    <div class="card-body p-4">

                        <?php if (!empty($pwErrors)): ?>
                        <div class="alert alert-danger alert-dismissible" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Bitte korrigieren Sie folgende Fehler:</strong>
                            <ul class="mb-0 mt-2 ps-3">
                                <?php foreach ($pwErrors as $err): ?>
                                    <li><?= htmlspecialchars($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php endif; ?>

                        <div class="alert alert-info d-flex align-items-start py-2 mb-4" role="alert">
                            <i class="bi bi-shield-lock me-2 flex-shrink-0 mt-1"></i>
                            <small>
                                Aus Sicherheitsgründen müssen Sie Ihr aktuelles Passwort eingeben.
                                Das neue Passwort muss mindestens <strong>8 Zeichen</strong> lang sein.
                            </small>
                        </div>

                        <form method="POST" action="" novalidate autocomplete="off" id="pwForm">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="passwort">

                            <div class="row g-3">

                                <!-- Aktuelles Passwort -->
                                <div class="col-12">
                                    <label for="altes_passwort" class="form-label fw-semibold">
                                        <i class="bi bi-lock me-1"></i>Aktuelles Passwort <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <input
                                            type="password"
                                            id="altes_passwort"
                                            name="altes_passwort"
                                            class="form-control <?= !empty($pwErrors) ? 'is-invalid' : '' ?>"
                                            placeholder="Aktuelles Passwort eingeben"
                                            required
                                            autocomplete="current-password"
                                        >
                                        <button type="button" class="btn btn-outline-secondary toggle-pw-btn"
                                                data-target="altes_passwort" title="Passwort anzeigen"
                                                aria-label="Passwort anzeigen/verbergen">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Neues Passwort -->
                                <div class="col-sm-6">
                                    <label for="neues_passwort" class="form-label fw-semibold">
                                        <i class="bi bi-lock-fill me-1"></i>Neues Passwort <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <input
                                            type="password"
                                            id="neues_passwort"
                                            name="neues_passwort"
                                            class="form-control"
                                            placeholder="Min. 8 Zeichen"
                                            required
                                            minlength="8"
                                            autocomplete="new-password"
                                        >
                                        <button type="button" class="btn btn-outline-secondary toggle-pw-btn"
                                                data-target="neues_passwort" title="Passwort anzeigen"
                                                aria-label="Passwort anzeigen/verbergen">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>

                                    <!-- Passwortstärke-Anzeige -->
                                    <div class="mt-2" id="pw-strength-bar" style="display:none;">
                                        <div class="progress" style="height: 6px;">
                                            <div class="progress-bar" id="pw-strength-fill" style="width: 0%;"></div>
                                        </div>
                                        <small id="pw-strength-label" class="text-muted"></small>
                                    </div>
                                    <div class="form-text">Mindestens 8 Zeichen.</div>
                                </div>

                                <!-- Neues Passwort bestätigen -->
                                <div class="col-sm-6">
                                    <label for="neues_passwort2" class="form-label fw-semibold">
                                        Passwort bestätigen <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <input
                                            type="password"
                                            id="neues_passwort2"
                                            name="neues_passwort2"
                                            class="form-control"
                                            placeholder="Wiederholen"
                                            required
                                            minlength="8"
                                            autocomplete="new-password"
                                        >
                                        <button type="button" class="btn btn-outline-secondary toggle-pw-btn"
                                                data-target="neues_passwort2" title="Passwort anzeigen"
                                                aria-label="Passwort anzeigen/verbergen">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="invalid-feedback" id="pw-match-feedback">
                                        Die Passwörter stimmen nicht überein.
                                    </div>
                                </div>

                                <!-- Pflichtfeld-Hinweis -->
                                <div class="col-12">
                                    <p class="text-muted small mb-0">
                                        <span class="text-danger">*</span> Pflichtfelder
                                    </p>
                                </div>

                                <!-- Submit -->
                                <div class="col-12">
                                    <button type="submit" class="btn btn-dark fw-bold px-4">
                                        <i class="bi bi-key me-2"></i>Passwort ändern
                                    </button>
                                </div>

                            </div>
                        </form>

                    </div>
                </div>

            </div><!-- /.col-lg-8 -->
        </div><!-- /.row -->

    </div><!-- /.container -->
</main>

<?php
$extraScripts = <<<'HTML'
<script>
(function () {
    'use strict';

    // ── Passwort-Sichtbarkeit umschalten ──────────────────────────────────
    document.querySelectorAll('.toggle-pw-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = this.dataset.target;
            var input    = document.getElementById(targetId);
            var icon     = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
                this.title = 'Passwort verbergen';
            } else {
                input.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
                this.title = 'Passwort anzeigen';
            }
        });
    });

    // ── Live-Passwortstärke ───────────────────────────────────────────────
    var newPwInput   = document.getElementById('neues_passwort');
    var strengthBar  = document.getElementById('pw-strength-bar');
    var strengthFill = document.getElementById('pw-strength-fill');
    var strengthLbl  = document.getElementById('pw-strength-label');

    function calcStrength(pw) {
        var score = 0;
        if (pw.length >= 8)  score++;
        if (pw.length >= 12) score++;
        if (/[A-Z]/.test(pw)) score++;
        if (/[0-9]/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;
        return score; // 0-5
    }

    if (newPwInput) {
        newPwInput.addEventListener('input', function () {
            var pw = this.value;
            if (pw.length === 0) {
                strengthBar.style.display = 'none';
                return;
            }
            strengthBar.style.display = 'block';
            var score = calcStrength(pw);
            var pct   = Math.min(100, score * 20);
            var map   = [
                ['bg-danger',  'text-danger',  'Sehr schwach'],
                ['bg-danger',  'text-danger',  'Schwach'],
                ['bg-warning', 'text-warning', 'Mittel'],
                ['bg-info',    'text-info',    'Gut'],
                ['bg-success', 'text-success', 'Sehr stark'],
                ['bg-success', 'text-success', 'Ausgezeichnet'],
            ];
            var entry = map[score] || map[0];
            strengthFill.style.width = pct + '%';
            strengthFill.className   = 'progress-bar ' + entry[0];
            strengthLbl.className    = 'small ' + entry[1];
            strengthLbl.textContent  = entry[2];
        });
    }

    // ── Live-Passwortübereinstimmung ──────────────────────────────────────
    var pw1         = document.getElementById('neues_passwort');
    var pw2         = document.getElementById('neues_passwort2');
    var matchFeedback = document.getElementById('pw-match-feedback');

    function checkMatch() {
        if (!pw2 || pw2.value.length === 0) {
            if (pw2) {
                pw2.classList.remove('is-valid', 'is-invalid');
            }
            return;
        }
        if (pw1.value === pw2.value) {
            pw2.classList.add('is-valid');
            pw2.classList.remove('is-invalid');
        } else {
            pw2.classList.add('is-invalid');
            pw2.classList.remove('is-valid');
        }
    }

    if (pw1) pw1.addEventListener('input', checkMatch);
    if (pw2) pw2.addEventListener('input', checkMatch);

    // ── Formular-Absende-Validierung ─────────────────────────────────────
    var pwForm = document.getElementById('pwForm');
    if (pwForm) {
        pwForm.addEventListener('submit', function (e) {
            if (pw1 && pw2 && pw1.value !== pw2.value) {
                e.preventDefault();
                pw2.classList.add('is-invalid');
                pw2.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    }
})();
</script>
HTML;

include __DIR__ . '/../includes/footer.php';

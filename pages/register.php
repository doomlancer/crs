<?php
/**
 * Registrierungs-Seite
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Bereits eingeloggt → weiterleiten
if (isLoggedIn()) {
    redirect('/pages/events.php');
}

$errors       = [];
$formData     = [
    'vorname'     => '',
    'nachname'    => '',
    'email'       => '',
    'zahlungsart' => '',
    'adresse'     => '',
];

$zahlungsarten = [
    'bar'          => 'Bar',
    'ueberweisung' => 'Überweisung',
    'paypal'       => 'PayPal',
];

// POST-Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF prüfen
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Ungültiger Sicherheitstoken. Bitte laden Sie die Seite neu.';
    } else {
        // Formulardaten übernehmen (für Wiederanzeige)
        $formData['vorname']     = trim($_POST['vorname']     ?? '');
        $formData['nachname']    = trim($_POST['nachname']    ?? '');
        $formData['email']       = trim($_POST['email']       ?? '');
        $formData['zahlungsart'] = trim($_POST['zahlungsart'] ?? '');
        $formData['adresse']     = trim($_POST['adresse']     ?? '');

        $passwort  = $_POST['passwort']  ?? '';
        $passwort2 = $_POST['passwort2'] ?? '';

        // Clientseitige Vor-Validierung
        if (strlen($formData['vorname']) < 2) {
            $errors[] = 'Vorname muss mindestens 2 Zeichen lang sein.';
        }
        if (strlen($formData['nachname']) < 2) {
            $errors[] = 'Nachname muss mindestens 2 Zeichen lang sein.';
        }
        if (empty($formData['email']) || !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
        }
        if (strlen($passwort) < 8) {
            $errors[] = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
        }
        if ($passwort !== $passwort2) {
            $errors[] = 'Die Passwörter stimmen nicht überein.';
        }
        if (!array_key_exists($formData['zahlungsart'], $zahlungsarten)) {
            $errors[] = 'Bitte wählen Sie eine gültige Zahlungsart.';
        }

        if (empty($errors)) {
            $result = registerUser([
                'vorname'     => $formData['vorname'],
                'nachname'    => $formData['nachname'],
                'email'       => $formData['email'],
                'passwort'    => $passwort,
                'passwort2'   => $passwort2,
                'zahlungsart' => $formData['zahlungsart'],
                'adresse'     => $formData['adresse'],
            ]);

            if ($result === true) {
                // Auto-Login nach erfolgreicher Registrierung
                $loginResult = loginUser($formData['email'], $passwort);
                if ($loginResult === true) {
                    setFlash('success', 'Willkommen! Ihr Konto wurde erfolgreich erstellt.');
                    redirect('/pages/events.php');
                } else {
                    setFlash('success', 'Registrierung erfolgreich! Bitte melden Sie sich an.');
                    redirect('/pages/login.php');
                }
            } else {
                // registerUser gibt Array mit Fehlern zurück
                $errors = array_merge($errors, (array)$result);
            }
        }
    }
}

$pageTitle = 'Registrieren';
$bodyClass = 'auth-page bg-dark';
$extraHead = '';

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid min-vh-100 d-flex align-items-center justify-content-center py-4">
    <div class="row w-100 justify-content-center">
        <div class="col-12 col-sm-11 col-md-9 col-lg-7 col-xl-6">

            <!-- Logo / App-Name -->
            <div class="text-center mb-4">
                <a href="/index.php" class="text-decoration-none">
                    <i class="bi bi-music-note-beamed display-4 text-warning"></i>
                    <h1 class="h4 text-white fw-bold mt-2"><?= htmlspecialchars(APP_NAME) ?></h1>
                </a>
            </div>

            <!-- Register-Card -->
            <div class="card border-0 shadow-lg">
                <div class="card-header bg-warning text-dark text-center py-3 border-0">
                    <h2 class="h5 mb-0 fw-bold">
                        <i class="bi bi-person-plus me-2"></i>Konto erstellen
                    </h2>
                </div>
                <div class="card-body p-4">

                    <?= getFlash() ?>

                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Bitte korrigieren Sie folgende Fehler:</strong>
                        <ul class="mb-0 mt-2 ps-3">
                            <?php foreach ($errors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="" novalidate autocomplete="on">
                        <?= csrfField() ?>

                        <!-- Name -->
                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <label for="vorname" class="form-label fw-semibold">
                                    <i class="bi bi-person me-1"></i>Vorname <span class="text-danger">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="vorname"
                                    name="vorname"
                                    class="form-control <?= (!empty($errors) && strlen($formData['vorname']) < 2) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($formData['vorname']) ?>"
                                    placeholder="Max"
                                    required
                                    minlength="2"
                                    maxlength="100"
                                    autofocus
                                    autocomplete="given-name"
                                >
                                <div class="invalid-feedback">Mindestens 2 Zeichen erforderlich.</div>
                            </div>
                            <div class="col-sm-6">
                                <label for="nachname" class="form-label fw-semibold">
                                    Nachname <span class="text-danger">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="nachname"
                                    name="nachname"
                                    class="form-control <?= (!empty($errors) && strlen($formData['nachname']) < 2) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($formData['nachname']) ?>"
                                    placeholder="Mustermann"
                                    required
                                    minlength="2"
                                    maxlength="100"
                                    autocomplete="family-name"
                                >
                                <div class="invalid-feedback">Mindestens 2 Zeichen erforderlich.</div>
                            </div>
                        </div>

                        <!-- E-Mail -->
                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold">
                                <i class="bi bi-envelope me-1"></i>E-Mail-Adresse <span class="text-danger">*</span>
                            </label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                class="form-control <?= (!empty($errors) && !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($formData['email']) ?>"
                                placeholder="name@beispiel.de"
                                required
                                maxlength="255"
                                autocomplete="email"
                            >
                            <div class="invalid-feedback">Bitte eine gültige E-Mail-Adresse eingeben.</div>
                        </div>

                        <!-- Passwort -->
                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <label for="passwort" class="form-label fw-semibold">
                                    <i class="bi bi-lock me-1"></i>Passwort <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input
                                        type="password"
                                        id="passwort"
                                        name="passwort"
                                        class="form-control"
                                        placeholder="Min. 8 Zeichen"
                                        required
                                        minlength="8"
                                        autocomplete="new-password"
                                    >
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary toggle-pw"
                                        data-target="passwort"
                                        title="Passwort anzeigen"
                                        aria-label="Passwort anzeigen/verbergen"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text">Mindestens 8 Zeichen.</div>
                            </div>
                            <div class="col-sm-6">
                                <label for="passwort2" class="form-label fw-semibold">
                                    Passwort bestätigen <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input
                                        type="password"
                                        id="passwort2"
                                        name="passwort2"
                                        class="form-control"
                                        placeholder="Wiederholen"
                                        required
                                        minlength="8"
                                        autocomplete="new-password"
                                    >
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary toggle-pw"
                                        data-target="passwort2"
                                        title="Passwort anzeigen"
                                        aria-label="Passwort anzeigen/verbergen"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="invalid-feedback" id="pw-match-error">Die Passwörter stimmen nicht überein.</div>
                            </div>
                        </div>

                        <!-- Zahlungsart -->
                        <div class="mb-3">
                            <label for="zahlungsart" class="form-label fw-semibold">
                                <i class="bi bi-credit-card me-1"></i>Bevorzugte Zahlungsart <span class="text-danger">*</span>
                            </label>
                            <select
                                id="zahlungsart"
                                name="zahlungsart"
                                class="form-select <?= (!empty($errors) && !array_key_exists($formData['zahlungsart'], $zahlungsarten) && $formData['zahlungsart'] !== '') ? 'is-invalid' : '' ?>"
                                required
                            >
                                <option value="" disabled <?= $formData['zahlungsart'] === '' ? 'selected' : '' ?>>
                                    Bitte wählen…
                                </option>
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
                        <div class="mb-4">
                            <label for="adresse" class="form-label fw-semibold">
                                <i class="bi bi-geo-alt me-1"></i>Adresse
                                <span class="text-muted fw-normal">(optional)</span>
                            </label>
                            <input
                                type="text"
                                id="adresse"
                                name="adresse"
                                class="form-control"
                                value="<?= htmlspecialchars($formData['adresse']) ?>"
                                placeholder="Musterstraße 1, 12345 Musterstadt"
                                maxlength="255"
                                autocomplete="street-address"
                            >
                        </div>

                        <!-- Hinweis Pflichtfelder -->
                        <p class="text-muted small mb-3">
                            <span class="text-danger">*</span> Pflichtfelder
                        </p>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-warning btn-lg fw-bold">
                                <i class="bi bi-person-check me-2"></i>Konto erstellen
                            </button>
                        </div>
                    </form>
                </div>
                <div class="card-footer bg-light text-center py-3 border-0">
                    <span class="text-muted">Bereits registriert?</span>
                    <a href="/pages/login.php" class="text-warning fw-semibold text-decoration-none ms-1">
                        Jetzt anmelden <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>

            <!-- Zurück zur Startseite -->
            <div class="text-center mt-3">
                <a href="/index.php" class="text-white-50 text-decoration-none small">
                    <i class="bi bi-arrow-left me-1"></i>Zurück zur Startseite
                </a>
            </div>

        </div>
    </div>
</div>

<?php
$extraScripts = <<<HTML
<script>
// Passwort-Sichtbarkeit umschalten
document.querySelectorAll('.toggle-pw').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const targetId = this.dataset.target;
        const input    = document.getElementById(targetId);
        const icon     = this.querySelector('i');
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

// Live-Passwortübereinstimmung prüfen
const pw1 = document.getElementById('passwort');
const pw2 = document.getElementById('passwort2');

function checkMatch() {
    if (pw2.value.length === 0) {
        pw2.classList.remove('is-valid', 'is-invalid');
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

pw1.addEventListener('input', checkMatch);
pw2.addEventListener('input', checkMatch);
</script>
HTML;

include __DIR__ . '/../includes/footer.php';

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
    'bar'          => __('payment.bar'),
    'ueberweisung' => __('payment.ueberweisung'),
    'paypal'       => __('payment.paypal'), // Markenname, wird nicht übersetzt
];

// POST-Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF prüfen
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = __('auth.invalid_csrf');
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
            $errors[] = __('auth.firstname_min_length');
        }
        if (strlen($formData['nachname']) < 2) {
            $errors[] = __('auth.lastname_min_length');
        }
        if (empty($formData['email']) || !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('auth.invalid_email');
        }
        if (strlen($passwort) < 8) {
            $errors[] = __('auth.password_min_length');
        }
        if ($passwort !== $passwort2) {
            $errors[] = __('auth.password_mismatch');
        }
        if (!array_key_exists($formData['zahlungsart'], $zahlungsarten)) {
            $errors[] = __('auth.invalid_payment_method');
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
                    setFlash('success', __('auth.registration_welcome'));
                    redirect('/pages/events.php');
                } else {
                    setFlash('success', __('auth.registration_success_login'));
                    redirect('/pages/login.php');
                }
            } else {
                // registerUser gibt Array mit Fehlern zurück
                $errors = array_merge($errors, (array)$result);
            }
        }
    }
}

$pageTitle = __('nav.register');
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
                        <i class="bi bi-person-plus me-2"></i><?= __('auth.create_account_header') ?>
                    </h2>
                </div>
                <div class="card-body p-4">

                    <?= getFlash() ?>

                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong><?= __('auth.fix_errors_prefix') ?></strong>
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
                                    <i class="bi bi-person me-1"></i><?= __('auth.firstname_label') ?> <span class="text-danger">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="vorname"
                                    name="vorname"
                                    class="form-control <?= (!empty($errors) && strlen($formData['vorname']) < 2) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($formData['vorname']) ?>"
                                    placeholder="<?= htmlspecialchars(__('auth.firstname_placeholder')) ?>"
                                    required
                                    minlength="2"
                                    maxlength="100"
                                    autofocus
                                    autocomplete="given-name"
                                >
                                <div class="invalid-feedback"><?= __('auth.min_2_chars_feedback') ?></div>
                            </div>
                            <div class="col-sm-6">
                                <label for="nachname" class="form-label fw-semibold">
                                    <?= __('auth.lastname_label') ?> <span class="text-danger">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="nachname"
                                    name="nachname"
                                    class="form-control <?= (!empty($errors) && strlen($formData['nachname']) < 2) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($formData['nachname']) ?>"
                                    placeholder="<?= htmlspecialchars(__('auth.lastname_placeholder')) ?>"
                                    required
                                    minlength="2"
                                    maxlength="100"
                                    autocomplete="family-name"
                                >
                                <div class="invalid-feedback"><?= __('auth.min_2_chars_feedback') ?></div>
                            </div>
                        </div>

                        <!-- E-Mail -->
                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold">
                                <i class="bi bi-envelope me-1"></i><?= __('auth.email') ?> <span class="text-danger">*</span>
                            </label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                class="form-control <?= (!empty($errors) && !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($formData['email']) ?>"
                                placeholder="<?= htmlspecialchars(__('auth.email_placeholder')) ?>"
                                required
                                maxlength="255"
                                autocomplete="email"
                            >
                            <div class="invalid-feedback"><?= __('auth.email_invalid_feedback') ?></div>
                        </div>

                        <!-- Passwort -->
                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <label for="passwort" class="form-label fw-semibold">
                                    <i class="bi bi-lock me-1"></i><?= __('auth.password') ?> <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input
                                        type="password"
                                        id="passwort"
                                        name="passwort"
                                        class="form-control"
                                        placeholder="<?= htmlspecialchars(__('auth.password_placeholder_min8')) ?>"
                                        required
                                        minlength="8"
                                        autocomplete="new-password"
                                    >
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary toggle-pw"
                                        data-target="passwort"
                                        title="<?= htmlspecialchars(__('auth.show_password_title')) ?>"
                                        aria-label="<?= htmlspecialchars(__('auth.toggle_password_aria')) ?>"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text"><?= __('auth.min_8_chars_hint') ?></div>
                            </div>
                            <div class="col-sm-6">
                                <label for="passwort2" class="form-label fw-semibold">
                                    <?= __('auth.confirm_password_label') ?> <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input
                                        type="password"
                                        id="passwort2"
                                        name="passwort2"
                                        class="form-control"
                                        placeholder="<?= htmlspecialchars(__('auth.repeat_placeholder')) ?>"
                                        required
                                        minlength="8"
                                        autocomplete="new-password"
                                    >
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary toggle-pw"
                                        data-target="passwort2"
                                        title="<?= htmlspecialchars(__('auth.show_password_title')) ?>"
                                        aria-label="<?= htmlspecialchars(__('auth.toggle_password_aria')) ?>"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="invalid-feedback" id="pw-match-error"><?= __('auth.password_mismatch') ?></div>
                            </div>
                        </div>

                        <!-- Zahlungsart -->
                        <div class="mb-3">
                            <label for="zahlungsart" class="form-label fw-semibold">
                                <i class="bi bi-credit-card me-1"></i><?= __('auth.preferred_payment_label') ?> <span class="text-danger">*</span>
                            </label>
                            <select
                                id="zahlungsart"
                                name="zahlungsart"
                                class="form-select <?= (!empty($errors) && !array_key_exists($formData['zahlungsart'], $zahlungsarten) && $formData['zahlungsart'] !== '') ? 'is-invalid' : '' ?>"
                                required
                            >
                                <option value="" disabled <?= $formData['zahlungsart'] === '' ? 'selected' : '' ?>>
                                    <?= __('auth.please_choose') ?>
                                </option>
                                <?php foreach ($zahlungsarten as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value) ?>"
                                    <?= $formData['zahlungsart'] === $value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback"><?= __('auth.select_payment_feedback') ?></div>
                        </div>

                        <!-- Adresse (optional) -->
                        <div class="mb-4">
                            <label for="adresse" class="form-label fw-semibold">
                                <i class="bi bi-geo-alt me-1"></i><?= __('auth.address_label') ?>
                                <span class="text-muted fw-normal"><?= __('general.optional') ?></span>
                            </label>
                            <input
                                type="text"
                                id="adresse"
                                name="adresse"
                                class="form-control"
                                value="<?= htmlspecialchars($formData['adresse']) ?>"
                                placeholder="<?= htmlspecialchars(__('auth.address_placeholder')) ?>"
                                maxlength="255"
                                autocomplete="street-address"
                            >
                        </div>

                        <!-- Hinweis Pflichtfelder -->
                        <p class="text-muted small mb-3">
                            <span class="text-danger">*</span> <?= __('general.required_fields_note') ?>
                        </p>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-warning btn-lg fw-bold">
                                <i class="bi bi-person-check me-2"></i><?= __('auth.create_account_header') ?>
                            </button>
                        </div>
                    </form>
                </div>
                <div class="card-footer bg-light text-center py-3 border-0">
                    <span class="text-muted"><?= __('auth.already_registered') ?></span>
                    <a href="/pages/login.php" class="text-warning fw-semibold text-decoration-none ms-1">
                        <?= __('auth.login_now') ?> <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>

            <!-- Zurück zur Startseite -->
            <div class="text-center mt-3">
                <a href="/index.php" class="text-white-50 text-decoration-none small">
                    <i class="bi bi-arrow-left me-1"></i><?= __('auth.back_to_home') ?>
                </a>
            </div>

        </div>
    </div>
</div>

<?php
$jsShowPw = json_encode(__('auth.show_password_title'));
$jsHidePw = json_encode(__('auth.hide_password_title'));
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
            this.title = {$jsHidePw};
        } else {
            input.type = 'password';
            icon.classList.replace('bi-eye-slash', 'bi-eye');
            this.title = {$jsShowPw};
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

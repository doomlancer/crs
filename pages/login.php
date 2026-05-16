<?php
/**
 * Login-Seite
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Bereits eingeloggt → weiterleiten
if (isLoggedIn()) {
    redirect('/pages/events.php');
}

$errors  = [];
$email   = '';

// POST-Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF prüfen
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Ungültiger Sicherheitstoken. Bitte laden Sie die Seite neu.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $passwort = $_POST['passwort'] ?? '';

        if (empty($email)) {
            $errors[] = 'Bitte geben Sie Ihre E-Mail-Adresse ein.';
        }
        if (empty($passwort)) {
            $errors[] = 'Bitte geben Sie Ihr Passwort ein.';
        }

        if (empty($errors)) {
            $result = loginUser($email, $passwort);
            if ($result === true) {
                // Nach Login ggf. auf ursprünglich angefragte Seite weiterleiten
                $redirect = $_SESSION['redirect_after_login'] ?? '/pages/events.php';
                unset($_SESSION['redirect_after_login']);
                setFlash('success', 'Willkommen zurück, ' . htmlspecialchars($_SESSION['vorname']) . '!');
                redirect($redirect);
            } else {
                $errors[] = $result;
            }
        }
    }
}

// Session-Timeout-Nachricht auslesen und löschen
$timeoutMessage = '';
if (!empty($_SESSION['timeout_message'])) {
    $timeoutMessage = $_SESSION['timeout_message'];
    unset($_SESSION['timeout_message']);
}

$pageTitle  = 'Anmelden';
$bodyClass  = 'auth-page bg-dark';
$extraHead  = '';

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid min-vh-100 d-flex align-items-center justify-content-center py-4">
    <div class="row w-100 justify-content-center">
        <div class="col-12 col-sm-10 col-md-8 col-lg-5 col-xl-4">

            <!-- Logo / App-Name -->
            <div class="text-center mb-4">
                <a href="/index.php" class="text-decoration-none">
                    <i class="bi bi-music-note-beamed display-4 text-warning"></i>
                    <h1 class="h4 text-white fw-bold mt-2"><?= htmlspecialchars(APP_NAME) ?></h1>
                </a>
            </div>

            <!-- Login-Card -->
            <div class="card border-0 shadow-lg">
                <div class="card-header bg-warning text-dark text-center py-3 border-0">
                    <h2 class="h5 mb-0 fw-bold">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Anmelden
                    </h2>
                </div>
                <div class="card-body p-4">

                    <?= getFlash() ?>

                    <?php if ($timeoutMessage): ?>
                    <div class="alert alert-warning alert-dismissible d-flex align-items-start" role="alert">
                        <i class="bi bi-clock-history me-2 mt-1 flex-shrink-0"></i>
                        <div><?= htmlspecialchars($timeoutMessage) ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php if (count($errors) === 1): ?>
                            <?= htmlspecialchars($errors[0]) ?>
                        <?php else: ?>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($errors as $err): ?>
                                    <li><?= htmlspecialchars($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        <?php
                        // Rate-Limiting-Hinweis anzeigen wenn Login-Sperre erwähnt wird
                        $isRateLimit = false;
                        foreach ($errors as $err) {
                            if (str_contains($err, 'Versuch') || str_contains($err, 'gesperrt') || str_contains($err, 'Minute')) {
                                $isRateLimit = true;
                                break;
                            }
                        }
                        if ($isRateLimit): ?>
                        <hr class="my-2">
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            Aus Sicherheitsgründen wird der Zugang nach <?= MAX_LOGIN_VERSUCHE ?> fehlgeschlagenen
                            Versuchen für <?= LOGIN_SPERRZEIT / 60 ?> Minuten gesperrt.
                        </small>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="" novalidate autocomplete="on">
                        <?= csrfField() ?>

                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold">
                                <i class="bi bi-envelope me-1"></i>E-Mail-Adresse
                            </label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                class="form-control form-control-lg <?= !empty($errors) && !empty($email) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($email) ?>"
                                placeholder="name@beispiel.de"
                                required
                                autofocus
                                autocomplete="email"
                            >
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <label for="passwort" class="form-label fw-semibold mb-0">
                                    <i class="bi bi-lock me-1"></i>Passwort
                                </label>
                            </div>
                            <div class="input-group mt-1">
                                <input
                                    type="password"
                                    id="passwort"
                                    name="passwort"
                                    class="form-control form-control-lg"
                                    placeholder="Ihr Passwort"
                                    required
                                    autocomplete="current-password"
                                >
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    id="togglePasswort"
                                    title="Passwort anzeigen"
                                    aria-label="Passwort anzeigen/verbergen"
                                >
                                    <i class="bi bi-eye" id="togglePasswortIcon"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-warning btn-lg fw-bold">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Anmelden
                            </button>
                        </div>
                    </form>
                </div>
                <div class="card-footer bg-light text-center py-3 border-0">
                    <span class="text-muted">Noch kein Konto?</span>
                    <a href="/pages/register.php" class="text-warning fw-semibold text-decoration-none ms-1">
                        Jetzt registrieren <i class="bi bi-arrow-right"></i>
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
document.getElementById('togglePasswort').addEventListener('click', function () {
    const input = document.getElementById('passwort');
    const icon  = document.getElementById('togglePasswortIcon');
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
</script>
HTML;

include __DIR__ . '/../includes/footer.php';

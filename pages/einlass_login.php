<?php
/**
 * Einlass-Zugang: Kurzcode-Login für Einlass-Helfer ohne eigenes Konto.
 * Bewusst ohne Navbar/Header-Schnickschnack – gebaut für ein einfaches
 * Handy am Eingang. Nach erfolgreichem Login geht es direkt zur Scan-Seite.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Bereits als Einlass angemeldet → direkt zur Scan-Seite
if (hasRole('einlass')) {
    redirect('/pages/einlass_scan.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Ungültiges Sicherheitstoken. Bitte erneut versuchen.';
    } else {
        $code   = trim($_POST['code'] ?? '');
        $result = loginEinlass($code);
        if ($result === true) {
            redirect('/pages/einlass_scan.php');
        }
        $errors[] = $result;
    }
}

$pageTitle = 'Einlass-Zugang';
$bodyClass = 'auth-page bg-dark';
include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid min-vh-100 d-flex align-items-center justify-content-center py-4">
    <div class="row w-100 justify-content-center">
        <div class="col-12 col-sm-8 col-md-6 col-lg-4 col-xl-3">

            <div class="text-center mb-4">
                <div class="mx-auto mb-3 d-flex align-items-center justify-content-center"
                     style="width:56px;height:56px;border-radius:14px;background:var(--club-red,#cf2e2e);">
                    <i class="bi bi-shield-check text-white fs-3"></i>
                </div>
                <h1 class="h4 text-white fw-bold mb-1">Einlass-Zugang</h1>
                <p class="text-white-50 small mb-0">Kein Passwort nötig</p>
            </div>

            <?= getFlash() ?>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= htmlspecialchars($errors[0]) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="" novalidate>
                <?= csrfField() ?>
                <div class="mb-3">
                    <label for="code" class="form-label fw-semibold text-white-50 small">Zugangscode</label>
                    <input
                        type="text"
                        id="code"
                        name="code"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        class="form-control form-control-lg text-center font-monospace"
                        style="font-size:1.75rem; letter-spacing:.1em;"
                        placeholder="z. B. 4471"
                        autofocus
                        autocomplete="off"
                        required
                    >
                </div>
                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-danger btn-lg fw-bold py-3">
                        Anmelden
                    </button>
                </div>
            </form>

            <p class="text-white-50 small text-center mt-3 mb-0">
                Der Code wurde euch vom Orga-Team für diese Veranstaltung und euer Gerät zugeteilt.
                Jeder Check-in wird eurem Code zugeordnet.
            </p>

            <div class="text-center mt-4">
                <a href="/pages/login.php" class="text-white-50 text-decoration-none small">
                    Normaler Login für Kassierer/Admin <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

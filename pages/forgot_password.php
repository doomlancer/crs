<?php
/**
 * Passwort vergessen - Token anfordern
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    redirect('/pages/events.php');
}

$errors  = [];
$success = false;
$email   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Ungültiger Sicherheitstoken. Bitte laden Sie die Seite neu.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!validateEmail($email)) {
            $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
        } else {
            $pdo  = getDB();
            $stmt = $pdo->prepare('SELECT id, vorname FROM users WHERE email = ? AND aktiv = 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $token     = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 Stunde

                // Alte Tokens für diesen Benutzer löschen
                $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['id']]);
                $pdo->prepare(
                    'INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)'
                )->execute([$user['id'], hash('sha256', $token), $expiresAt]);

                // E-Mail versenden
                $resetUrl = APP_URL . '/pages/reset_password.php?token=' . urlencode($token);
                sendPasswordResetEmail($email, $user['vorname'], $resetUrl);

                logAudit('PASSWORD_RESET_ANGEFORDERT', 'users', $user['id'], "Reset-E-Mail angefordert");
            }
            // Immer gleiche Meldung (verhindert User-Enumeration)
            $success = true;
        }
    }
}

$pageTitle = 'Passwort vergessen';
$bodyClass = 'auth-page bg-dark';
include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid min-vh-100 d-flex align-items-center justify-content-center py-4">
    <div class="row w-100 justify-content-center">
        <div class="col-12 col-sm-10 col-md-8 col-lg-5 col-xl-4">

            <div class="text-center mb-4">
                <a href="/index.php" class="text-decoration-none">
                    <i class="bi bi-music-note-beamed display-4 text-warning"></i>
                    <h1 class="h4 text-white fw-bold mt-2"><?= htmlspecialchars(APP_NAME) ?></h1>
                </a>
            </div>

            <div class="card border-0 shadow-lg">
                <div class="card-header bg-warning text-dark text-center py-3 border-0">
                    <h2 class="h5 mb-0 fw-bold">
                        <i class="bi bi-key me-2"></i>Passwort vergessen
                    </h2>
                </div>
                <div class="card-body p-4">

                    <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        Falls ein Konto mit dieser E-Mail-Adresse existiert, wurde eine E-Mail mit einem Reset-Link gesendet. Bitte prüfen Sie Ihren Posteingang.
                    </div>
                    <div class="text-center mt-3">
                        <a href="/pages/login.php" class="btn btn-warning">
                            <i class="bi bi-box-arrow-in-right me-1"></i>Zurück zur Anmeldung
                        </a>
                    </div>
                    <?php else: ?>

                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?= htmlspecialchars($errors[0]) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <p class="text-muted small mb-4">
                        Geben Sie Ihre E-Mail-Adresse ein. Wir senden Ihnen einen Link zum Zurücksetzen Ihres Passworts.
                    </p>

                    <form method="POST" action="" novalidate>
                        <?= csrfField() ?>
                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold">
                                <i class="bi bi-envelope me-1"></i>E-Mail-Adresse
                            </label>
                            <input type="email" id="email" name="email"
                                   class="form-control form-control-lg"
                                   value="<?= htmlspecialchars($email) ?>"
                                   placeholder="name@beispiel.de"
                                   required autofocus>
                        </div>
                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-warning btn-lg fw-bold">
                                <i class="bi bi-send me-2"></i>Reset-Link senden
                            </button>
                        </div>
                    </form>

                    <?php endif; ?>
                </div>
                <div class="card-footer bg-light text-center py-3 border-0">
                    <a href="/pages/login.php" class="text-warning fw-semibold text-decoration-none">
                        <i class="bi bi-arrow-left me-1"></i>Zurück zur Anmeldung
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

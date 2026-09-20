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
        $errors[] = __('auth.invalid_csrf');
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!validateEmail($email)) {
            $errors[] = __('auth.invalid_email');
        } elseif (rateLimitExceeded('pwreset_ip', getClientIP(), 5, 3600)
               || rateLimitExceeded('pwreset_mail', $email, 3, 3600)) {
            // Ohne Drosselung ließen sich beliebig viele Reset-Mails an ein
            // fremdes Postfach auslösen – und weil jede Anfrage den zuvor
            // verschickten Link entwertet, käme das Opfer nie zum Zurücksetzen.
            // Die Meldung bleibt dieselbe wie im Erfolgsfall, damit sie nicht
            // verrät, ob die Adresse überhaupt registriert ist.
            $success = true;
        } else {
            rateLimitHit('pwreset_ip', getClientIP());
            rateLimitHit('pwreset_mail', $email);

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

$pageTitle = __('auth.forgot_password_title');
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
                        <i class="bi bi-key me-2"></i><?= __('auth.forgot_password_title') ?>
                    </h2>
                </div>
                <div class="card-body p-4">

                    <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?= __('auth.reset_email_sent') ?>
                    </div>
                    <div class="text-center mt-3">
                        <a href="/pages/login.php" class="btn btn-warning">
                            <i class="bi bi-box-arrow-in-right me-1"></i><?= __('auth.back_to_login') ?>
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
                        <?= __('auth.forgot_password_instructions') ?>
                    </p>

                    <form method="POST" action="" novalidate>
                        <?= csrfField() ?>
                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold">
                                <i class="bi bi-envelope me-1"></i><?= __('auth.email') ?>
                            </label>
                            <input type="email" id="email" name="email"
                                   class="form-control form-control-lg"
                                   value="<?= htmlspecialchars($email) ?>"
                                   placeholder="<?= htmlspecialchars(__('auth.email_placeholder')) ?>"
                                   required autofocus>
                        </div>
                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-warning btn-lg fw-bold">
                                <i class="bi bi-send me-2"></i><?= __('auth.send_reset_link') ?>
                            </button>
                        </div>
                    </form>

                    <?php endif; ?>
                </div>
                <div class="card-footer bg-light text-center py-3 border-0">
                    <a href="/pages/login.php" class="text-warning fw-semibold text-decoration-none">
                        <i class="bi bi-arrow-left me-1"></i><?= __('auth.back_to_login') ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

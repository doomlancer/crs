<?php
/**
 * Passwort zurücksetzen via Token
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    redirect('/pages/events.php');
}

$token   = trim($_GET['token'] ?? '');
$errors  = [];
$success = false;

// Token vorab validieren
$pdo        = getDB();
$tokenHash  = hash('sha256', $token);
$stmt       = $pdo->prepare(
    'SELECT pr.id, pr.user_id, u.email, u.vorname
     FROM password_resets pr
     JOIN users u ON pr.user_id = u.id
     WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used = 0'
);
$stmt->execute([$tokenHash]);
$resetRow = $stmt->fetch();

$tokenValid = (bool)$resetRow;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Ungültiger Sicherheitstoken.';
    } elseif (!$tokenValid) {
        $errors[] = 'Dieser Link ist ungültig oder abgelaufen.';
    } else {
        $passwort  = $_POST['passwort'] ?? '';
        $passwort2 = $_POST['passwort2'] ?? '';

        if (!validatePassword($passwort)) {
            $errors[] = 'Passwort muss mindestens 8 Zeichen lang sein.';
        } elseif ($passwort !== $passwort2) {
            $errors[] = 'Die Passwörter stimmen nicht überein.';
        } else {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET passwort = ?, login_versuche = 0, gesperrt_bis = NULL WHERE id = ?')
                ->execute([hashPassword($passwort), $resetRow['user_id']]);
            $pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = ?')
                ->execute([$resetRow['id']]);
            $pdo->commit();

            logAudit('PASSWORD_RESET', 'users', $resetRow['user_id'], 'Passwort zurückgesetzt');
            $success = true;
        }
    }
}

$pageTitle = 'Neues Passwort setzen';
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
                        <i class="bi bi-shield-lock me-2"></i>Neues Passwort setzen
                    </h2>
                </div>
                <div class="card-body p-4">

                    <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        Ihr Passwort wurde erfolgreich geändert. Sie können sich jetzt anmelden.
                    </div>
                    <div class="text-center mt-3">
                        <a href="/pages/login.php" class="btn btn-warning fw-bold">
                            <i class="bi bi-box-arrow-in-right me-1"></i>Zur Anmeldung
                        </a>
                    </div>

                    <?php elseif (!$tokenValid && empty($errors)): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        Dieser Passwort-Reset-Link ist ungültig oder abgelaufen. Bitte fordern Sie einen neuen an.
                    </div>
                    <div class="text-center mt-3">
                        <a href="/pages/forgot_password.php" class="btn btn-warning fw-bold">
                            <i class="bi bi-arrow-repeat me-1"></i>Neuen Link anfordern
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

                    <form method="POST" action="" novalidate>
                        <?= csrfField() ?>
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                        <div class="mb-3">
                            <label for="passwort" class="form-label fw-semibold">
                                <i class="bi bi-lock me-1"></i>Neues Passwort
                            </label>
                            <div class="input-group">
                                <input type="password" id="passwort" name="passwort"
                                       class="form-control form-control-lg"
                                       placeholder="Mindestens 8 Zeichen"
                                       required autofocus>
                                <button type="button" class="btn btn-outline-secondary" id="togglePw1">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="passwort2" class="form-label fw-semibold">
                                <i class="bi bi-lock-fill me-1"></i>Passwort bestätigen
                            </label>
                            <div class="input-group">
                                <input type="password" id="passwort2" name="passwort2"
                                       class="form-control form-control-lg"
                                       placeholder="Passwort wiederholen"
                                       required>
                                <button type="button" class="btn btn-outline-secondary" id="togglePw2">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-warning btn-lg fw-bold">
                                <i class="bi bi-check-circle me-2"></i>Passwort speichern
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

<?php
$extraScripts = <<<HTML
<script>
['1','2'].forEach(n => {
    document.getElementById('togglePw'+n).addEventListener('click', function() {
        const inp  = document.getElementById('passwort'+n);
        const icon = this.querySelector('i');
        const show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
});
</script>
HTML;
include __DIR__ . '/../includes/footer.php';
?>

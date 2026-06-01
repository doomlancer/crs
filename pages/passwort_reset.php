<?php
/**
 * Passwort-Vergessen-Flow (2-stufig)
 * Step 1 (request): E-Mail eingeben → Token senden
 * Step 2 (reset):   Neues Passwort mit Token setzen
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/mailer.php';

if (isLoggedIn()) {
    redirect('/pages/events.php');
}

$pdo    = getDB();
$step   = $_GET['step'] ?? 'request';
$token  = trim($_GET['token'] ?? '');
$errors = [];
$info   = '';

// ─────────────────────────────────────────────────────────────────
// POST: Step 1 – E-Mail-Adresse prüfen und Token versenden
// ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'request') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Ungültiger Sicherheitstoken. Bitte laden Sie die Seite neu.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!validateEmail($email)) {
            $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
        } else {
            // Unabhängig ob E-Mail existiert: gleiche Meldung (verhindert User-Enumeration)
            $stmt = $pdo->prepare('SELECT id, vorname FROM users WHERE email = ? AND aktiv = 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Alte Token für diese E-Mail invalidieren
                $pdo->prepare('UPDATE password_resets SET used = 1 WHERE email = ? AND used = 0')
                    ->execute([$email]);

                // Neuen Token generieren (1 Stunde gültig)
                $resetToken = bin2hex(random_bytes(32));
                $expiresAt  = date('Y-m-d H:i:s', time() + 3600);
                $pdo->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)')
                    ->execute([$email, $resetToken, $expiresAt]);

                $resetUrl = APP_URL . '/pages/passwort_reset.php?step=reset&token=' . urlencode($resetToken);
                sendMail($email, 'Passwort zurücksetzen', 'passwort_reset', [
                    'vorname'    => $user['vorname'],
                    'reset_url'  => $resetUrl,
                    'expires_in' => '1 Stunde',
                ]);
            }

            // Immer gleiche Meldung
            setFlash('success', 'Wenn diese E-Mail-Adresse registriert ist, erhalten Sie in Kürze eine E-Mail mit einem Link zum Zurücksetzen des Passworts.');
            redirect('/pages/passwort_reset.php?step=request');
        }
    }
}

// ─────────────────────────────────────────────────────────────────
// POST: Step 2 – Neues Passwort setzen
// ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'reset') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Ungültiger Sicherheitstoken. Bitte laden Sie die Seite neu.';
    } else {
        $submitToken  = trim($_POST['token'] ?? '');
        $neuesPw      = $_POST['passwort'] ?? '';
        $neuesPw2     = $_POST['passwort2'] ?? '';

        if (strlen($neuesPw) < 8) {
            $errors[] = 'Das neue Passwort muss mindestens 8 Zeichen lang sein.';
        }
        if ($neuesPw !== $neuesPw2) {
            $errors[] = 'Die Passwörter stimmen nicht überein.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare(
                'SELECT id, email FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW()'
            );
            $stmt->execute([$submitToken]);
            $reset = $stmt->fetch();

            if (!$reset) {
                $errors[] = 'Dieser Link ist ungültig oder abgelaufen. Bitte fordern Sie einen neuen Link an.';
            } else {
                // Benutzer suchen
                $stmtU = $pdo->prepare('SELECT id FROM users WHERE email = ? AND aktiv = 1');
                $stmtU->execute([$reset['email']]);
                $user = $stmtU->fetch();

                if (!$user) {
                    $errors[] = 'Konto nicht gefunden oder deaktiviert.';
                } else {
                    // Passwort aktualisieren
                    $pdo->prepare('UPDATE users SET passwort = ? WHERE id = ?')
                        ->execute([hashPassword($neuesPw), $user['id']]);
                    // Token als genutzt markieren
                    $pdo->prepare('UPDATE password_resets SET used = 1 WHERE token = ?')
                        ->execute([$submitToken]);

                    logAudit('PASSWORT_RESET', 'users', $user['id'], 'Passwort via Reset-Link zurückgesetzt');
                    setFlash('success', 'Ihr Passwort wurde erfolgreich zurückgesetzt. Sie können sich jetzt anmelden.');
                    redirect('/pages/login.php');
                }
            }
        }
        // Bei Fehler: Step-2-Formular mit demselben Token anzeigen
        $step  = 'reset';
    }
}

// ─────────────────────────────────────────────────────────────────
// GET: Step 2 – Token validieren bevor Formular gezeigt wird
// ─────────────────────────────────────────────────────────────────
$resetValid = false;
if ($step === 'reset') {
    if (empty($token)) {
        setFlash('error', 'Ungültiger oder fehlender Token.');
        redirect('/pages/passwort_reset.php');
    }
    $stmt = $pdo->prepare(
        'SELECT id FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW()'
    );
    $stmt->execute([$token]);
    if (!$stmt->fetch()) {
        setFlash('error', 'Dieser Link ist ungültig oder abgelaufen. Bitte fordern Sie einen neuen Link an.');
        redirect('/pages/passwort_reset.php');
    }
    $resetValid = true;
}

$pageTitle = 'Passwort zurücksetzen';
$bodyClass = 'auth-page bg-dark';
$extraHead = '';

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid min-vh-100 d-flex align-items-center justify-content-center py-4">
    <div class="row w-100 justify-content-center">
        <div class="col-12 col-sm-10 col-md-7 col-lg-5 col-xl-4">

            <div class="text-center mb-4">
                <a href="/index.php" class="text-decoration-none">
                    <i class="bi bi-music-note-beamed display-4 text-warning"></i>
                    <h1 class="h4 text-white fw-bold mt-2"><?= htmlspecialchars(APP_NAME) ?></h1>
                </a>
            </div>

            <div class="card border-0 shadow-lg">
                <div class="card-header bg-warning text-dark text-center py-3 border-0">
                    <h2 class="h5 mb-0 fw-bold">
                        <i class="bi bi-key me-2"></i>Passwort zurücksetzen
                    </h2>
                </div>
                <div class="card-body p-4">

                    <?= getFlash() ?>

                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?= count($errors) === 1 ? htmlspecialchars($errors[0]) : '<ul class="mb-0 ps-3">' . implode('', array_map(fn($e) => '<li>' . htmlspecialchars($e) . '</li>', $errors)) . '</ul>' ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <?php if ($step === 'request'): ?>
                    <!-- Step 1: E-Mail-Adresse eingeben -->
                    <p class="text-muted mb-4">
                        Geben Sie Ihre E-Mail-Adresse ein. Wir senden Ihnen einen Link zum Zurücksetzen des Passworts.
                    </p>
                    <form method="POST" novalidate>
                        <?= csrfField() ?>
                        <input type="hidden" name="step" value="request">
                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold">
                                <i class="bi bi-envelope me-1"></i>E-Mail-Adresse
                            </label>
                            <input type="email" id="email" name="email"
                                   class="form-control form-control-lg"
                                   placeholder="name@beispiel.de"
                                   required autofocus autocomplete="email">
                        </div>
                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-warning btn-lg fw-bold">
                                <i class="bi bi-envelope me-2"></i>Reset-Link senden
                            </button>
                        </div>
                    </form>

                    <?php elseif ($step === 'reset' && $resetValid): ?>
                    <!-- Step 2: Neues Passwort eingeben -->
                    <p class="text-muted mb-4">Geben Sie Ihr neues Passwort ein.</p>
                    <form method="POST" novalidate id="resetForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="step" value="reset">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token ?: ($_POST['token'] ?? '')) ?>">
                        <div class="mb-3">
                            <label for="passwort" class="form-label fw-semibold">
                                <i class="bi bi-lock me-1"></i>Neues Passwort
                            </label>
                            <div class="input-group">
                                <input type="password" id="passwort" name="passwort"
                                       class="form-control form-control-lg"
                                       placeholder="Min. 8 Zeichen" required minlength="8"
                                       autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary" id="togglePw1"
                                        aria-label="Passwort anzeigen"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="passwort2" class="form-label fw-semibold">
                                Passwort bestätigen
                            </label>
                            <div class="input-group">
                                <input type="password" id="passwort2" name="passwort2"
                                       class="form-control form-control-lg"
                                       placeholder="Wiederholen" required minlength="8"
                                       autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary" id="togglePw2"
                                        aria-label="Passwort anzeigen"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-warning btn-lg fw-bold">
                                <i class="bi bi-check-lg me-2"></i>Passwort setzen
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>

                </div>
                <div class="card-footer bg-light text-center py-3 border-0">
                    <a href="/pages/login.php" class="text-muted text-decoration-none small">
                        <i class="bi bi-arrow-left me-1"></i>Zurück zum Login
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

<?php
$extraScripts = <<<'HTML'
<script>
(function() {
    function togglePw(btnId, inputId) {
        var btn   = document.getElementById(btnId);
        var input = document.getElementById(inputId);
        if (!btn || !input) return;
        btn.addEventListener('click', function() {
            var icon = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        });
    }
    togglePw('togglePw1', 'passwort');
    togglePw('togglePw2', 'passwort2');
})();
</script>
HTML;

include __DIR__ . '/../includes/footer.php';

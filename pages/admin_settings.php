<?php
/**
 * Admin-Einstellungen – Logo-Upload und allgemeine App-Konfiguration
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

requireRole('admin');

$errors = [];

// ── POST: Logo-Upload / Reset ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiges CSRF-Token. Bitte erneut versuchen.');
        redirect('/pages/admin_settings.php');
    }

    $postAction = $_POST['post_action'] ?? '';

    // ── Logo hochladen ──────────────────────────────────────────────────────
    if ($postAction === 'upload_logo') {
        if (empty($_FILES['logo_file']['name'])) {
            $errors[] = 'Bitte eine Datei auswählen.';
        } else {
            $file     = $_FILES['logo_file'];
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed  = ['png', 'jpg', 'jpeg', 'svg'];
            $maxBytes = 2 * 1024 * 1024; // 2 MB

            if (!in_array($ext, $allowed, true)) {
                $errors[] = 'Nur PNG, JPG und SVG sind erlaubt.';
            } elseif ($file['size'] > $maxBytes) {
                $errors[] = 'Die Datei darf maximal 2 MB groß sein.';
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Upload-Fehler (Code ' . $file['error'] . ').';
            } else {
                $destName = 'logo_custom.' . $ext;
                $destPath = UPLOAD_DIR . $destName;
                $webPath  = '/uploads/' . $destName;

                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    file_put_contents(UPLOAD_DIR . '.logo_config', $webPath);
                    setFlash('success', 'Logo erfolgreich hochgeladen.');
                    redirect('/pages/admin_settings.php');
                } else {
                    $errors[] = 'Datei konnte nicht gespeichert werden. Bitte Verzeichnis-Berechtigungen prüfen.';
                }
            }
        }
    }

    // ── Logo zurücksetzen ───────────────────────────────────────────────────
    if ($postAction === 'reset_logo') {
        $configFile = UPLOAD_DIR . '.logo_config';
        if (file_exists($configFile)) {
            unlink($configFile);
        }
        setFlash('success', 'Logo wurde auf den Standard zurückgesetzt.');
        redirect('/pages/admin_settings.php');
    }
}

// ── Aktuelles Logo ermitteln ──────────────────────────────────────────────────
$configFile  = UPLOAD_DIR . '.logo_config';
$currentLogo = file_exists($configFile) ? trim(file_get_contents($configFile)) : '/uploads/logo.svg';
$logoFileAbs = __DIR__ . '/../' . ltrim($currentLogo, '/');
$logoExists  = file_exists($logoFileAbs);

$pageTitle = 'Einstellungen';
$bodyClass = 'bg-light';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 fw-bold mb-0">
            <i class="bi bi-sliders text-warning me-2"></i>Einstellungen
        </h1>
        <a href="/pages/admin_dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Dashboard
        </a>
    </div>

    <?= getFlash() ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger alert-dismissible" role="alert">
            <ul class="mb-0">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ── Logo-Einstellungen ──────────────────────────────────────────── -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-dark text-white fw-semibold">
                    <i class="bi bi-image me-1"></i> Logo-Einstellungen
                </div>
                <div class="card-body">

                    <h6 class="text-muted mb-2">Aktuelles Logo</h6>
                    <div class="border rounded p-3 mb-2 bg-dark d-inline-block">
                        <?php if ($logoExists): ?>
                            <img src="<?= htmlspecialchars($currentLogo) ?>?v=<?= filemtime($logoFileAbs) ?>"
                                 alt="Aktuelles Logo"
                                 style="max-height:60px; max-width:300px;">
                        <?php else: ?>
                            <span class="text-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                Logo-Datei nicht gefunden
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-muted small mb-4">Pfad: <code><?= htmlspecialchars($currentLogo) ?></code></p>

                    <!-- Upload-Formular -->
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                        <input type="hidden" name="post_action" value="upload_logo">

                        <div class="mb-3">
                            <label for="logo_file" class="form-label fw-semibold">
                                Neues Logo hochladen
                            </label>
                            <input type="file"
                                   class="form-control"
                                   id="logo_file"
                                   name="logo_file"
                                   accept=".png,.jpg,.jpeg,.svg"
                                   required>
                            <div class="form-text">
                                Erlaubte Formate: PNG, JPG, SVG &nbsp;&middot;&nbsp; Max. 2 MB
                            </div>
                        </div>

                        <!-- Live-Vorschau -->
                        <div id="previewBox" class="border rounded p-3 bg-dark mb-3 d-none">
                            <small class="text-muted d-block mb-1">Vorschau (nach Upload)</small>
                            <img id="previewImg" src="" alt="Vorschau"
                                 style="max-height:60px; max-width:300px;">
                        </div>

                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-upload me-1"></i> Logo hochladen
                        </button>
                    </form>

                    <hr>

                    <!-- Reset -->
                    <form method="post"
                          onsubmit="return confirm('Logo wirklich auf den Standard zurücksetzen?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                        <input type="hidden" name="post_action" value="reset_logo">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Auf Standard zurücksetzen
                        </button>
                        <small class="text-muted ms-2">Setzt auf <code>/uploads/logo.svg</code> zurück</small>
                    </form>

                </div>
            </div>
        </div>

        <!-- ── App-Informationen ───────────────────────────────────────────── -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-dark text-white fw-semibold">
                    <i class="bi bi-info-circle me-1"></i> App-Informationen
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5">App-Name</dt>
                        <dd class="col-7"><code><?= htmlspecialchars(APP_NAME) ?></code></dd>

                        <dt class="col-5">App-URL</dt>
                        <dd class="col-7 text-break"><code><?= htmlspecialchars(APP_URL) ?></code></dd>

                        <dt class="col-5">Debug-Modus</dt>
                        <dd class="col-7">
                            <?php if (DEBUG_MODE): ?>
                                <span class="badge bg-warning text-dark">Aktiv</span>
                            <?php else: ?>
                                <span class="badge bg-success">Deaktiviert</span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-5">PHP-Version</dt>
                        <dd class="col-7"><code><?= phpversion() ?></code></dd>
                    </dl>
                </div>
            </div>
        </div>

    </div>
</main>

<?php
$extraScripts = '<script>
document.getElementById("logo_file").addEventListener("change", function() {
    const file = this.files[0];
    if (!file) return;
    const box = document.getElementById("previewBox");
    const img = document.getElementById("previewImg");
    const reader = new FileReader();
    reader.onload = function(e) {
        img.src = e.target.result;
        box.classList.remove("d-none");
    };
    reader.readAsDataURL(file);
});
</script>';

include __DIR__ . '/../includes/footer.php';
?>

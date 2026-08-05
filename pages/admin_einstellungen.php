<?php
/**
 * Admin: Design & Branding Einstellungen
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');
$pdo = getDB();

$errors = [];

// ─── Auto-Migration: settings-Tabelle bei Bedarf erstellen ───────────────────
if (!settingsTableExists()) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `settings` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `setting_key`   VARCHAR(100) NOT NULL,
              `setting_value` TEXT DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_key` (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
              ('color_primary','#cf2e2e'),('color_primary_dark','#a82424'),
              ('color_primary_light','#e84444'),('color_dark','#1a1a1a'),
              ('color_dark2','#2d2d2d'),('color_bg','#f5f5f5'),
              ('app_name','Kameruner-Tickets'),('app_slogan',''),
              ('app_logo',''),('app_favicon',''),
              ('font_family','system'),('theme_version','1')
            ON DUPLICATE KEY UPDATE setting_key = setting_key
        ");
        redirect('/pages/admin_einstellungen.php');
    } catch (PDOException $e) {
        setFlash('error', 'Tabelle konnte nicht erstellt werden: ' . $e->getMessage());
        redirect('/pages/admin_dashboard.php');
    }
}

// ─── POST-Handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiger Sicherheitstoken.');
        redirect('/pages/admin_einstellungen.php');
    }

    $postAction = $_POST['post_action'] ?? '';

    // ── Allgemeine Einstellungen (Farben, Marke, Schrift) ──────────────────────
    if ($postAction === 'save_settings') {
        $hexRe = '/^#[0-9a-fA-F]{6}$/';
        $colorFields = [
            'color_primary', 'color_primary_dark', 'color_primary_light',
            'color_dark', 'color_dark2', 'color_bg',
        ];
        foreach ($colorFields as $field) {
            $val = trim($_POST[$field] ?? '');
            if ($val !== '' && !preg_match($hexRe, $val)) {
                $errors[] = 'Ungültiger Farbwert für ' . $field . ': ' . htmlspecialchars($val);
            }
        }

        $allowedFonts = ['system', 'humanist', 'geometric', 'classic', 'condensed', 'mono'];
        $fontVal = trim($_POST['font_family'] ?? 'system');
        if (!in_array($fontVal, $allowedFonts, true)) {
            $errors[] = 'Ungültige Schriftart.';
        }

        if (empty($errors)) {
            foreach ($colorFields as $field) {
                $val = trim($_POST[$field] ?? '');
                if (preg_match($hexRe, $val)) setSetting($field, $val);
            }
            setSetting('app_name',    sanitize($_POST['app_name']    ?? getSetting('app_name', APP_NAME)));
            setSetting('app_slogan',  sanitize($_POST['app_slogan']  ?? ''));
            setSetting('font_family', $fontVal);
            setSetting('theme_version', (string)((int)getSetting('theme_version', '1') + 1));

            logAudit('UPDATE', 'settings', null, 'Design-Einstellungen geändert');
            setFlash('success', 'Einstellungen gespeichert.');
            redirect('/pages/admin_einstellungen.php');
        }
    }

    // ── Logo hochladen ─────────────────────────────────────────────────────────
    if ($postAction === 'upload_logo') {
        $res = saveUploadedImage($_FILES['logo_file'] ?? [], 'logo', 2 * 1024 * 1024);
        if ($res['ok']) {
            $old = getSetting('app_logo', '');
            if ($old && $old !== $res['name']) {
                deleteUploadedFile($old);
            }
            setSetting('app_logo', $res['name']);
            setSetting('theme_version', (string)((int)getSetting('theme_version', '1') + 1));
            logAudit('UPDATE', 'settings', null, 'Logo hochgeladen: ' . $res['name']);
            setFlash('success', 'Logo gespeichert.');
        } else {
            setFlash('error', $res['error']);
        }
        redirect('/pages/admin_einstellungen.php');
    }

    // ── Logo entfernen ─────────────────────────────────────────────────────────
    if ($postAction === 'remove_logo') {
        deleteUploadedFile(getSetting('app_logo', ''));
        setSetting('app_logo', '');
        setSetting('theme_version', (string)((int)getSetting('theme_version', '1') + 1));
        logAudit('UPDATE', 'settings', null, 'Logo entfernt');
        setFlash('success', 'Logo entfernt.');
        redirect('/pages/admin_einstellungen.php');
    }

    // ── Favicon hochladen ──────────────────────────────────────────────────────
    if ($postAction === 'upload_favicon') {
        $res = saveUploadedImage(
            $_FILES['favicon_file'] ?? [],
            'favicon',
            512 * 1024,
            [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP, IMAGETYPE_ICO]
        );
        if ($res['ok']) {
            $old = getSetting('app_favicon', '');
            if ($old && $old !== $res['name']) {
                deleteUploadedFile($old);
            }
            setSetting('app_favicon', $res['name']);
            setSetting('theme_version', (string)((int)getSetting('theme_version', '1') + 1));
            logAudit('UPDATE', 'settings', null, 'Favicon hochgeladen: ' . $res['name']);
            setFlash('success', 'Favicon gespeichert.');
        } else {
            setFlash('error', $res['error']);
        }
        redirect('/pages/admin_einstellungen.php');
    }

    // ── Favicon entfernen ──────────────────────────────────────────────────────
    if ($postAction === 'remove_favicon') {
        deleteUploadedFile(getSetting('app_favicon', ''));
        setSetting('app_favicon', '');
        setSetting('theme_version', (string)((int)getSetting('theme_version', '1') + 1));
        logAudit('UPDATE', 'settings', null, 'Favicon entfernt');
        setFlash('success', 'Favicon entfernt.');
        redirect('/pages/admin_einstellungen.php');
    }
}

// ─── Aktuelle Einstellungen laden ─────────────────────────────────────────────
$s = getAllSettings();
$defaults = [
    'color_primary'       => '#cf2e2e',
    'color_primary_dark'  => '#a82424',
    'color_primary_light' => '#e84444',
    'color_dark'          => '#1a1a1a',
    'color_dark2'         => '#2d2d2d',
    'color_bg'            => '#f5f5f5',
    'app_name'            => APP_NAME,
    'app_slogan'          => '',
    'font_family'         => 'system',
];
foreach ($defaults as $k => $v) {
    if (!isset($s[$k]) || $s[$k] === '') $s[$k] = $v;
}

$pageTitle = 'Design & Einstellungen';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container py-4">

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-palette text-warning me-2"></i>Design & Einstellungen
            </h1>
            <p class="text-muted mb-0 small">Farben, Logo, Schriftart und Markentext konfigurieren</p>
        </div>
        <a href="/pages/admin_dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Zurück
        </a>
    </div>

    <?= getFlash() ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" id="settingsForm">
        <?= csrfField() ?>
        <input type="hidden" name="post_action" value="save_settings">

        <div class="row g-4">

            <!-- ── Presets ─────────────────────────────────────────────────── -->
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-stars text-warning me-2"></i>Design-Vorlagen
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Klicken Sie auf eine Vorlage, um alle Farbfelder zu befüllen – danach können Sie die Werte weiter anpassen.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sm preset-btn"
                                    style="background:#cf2e2e;color:#fff;border:none;"
                                    data-p="#cf2e2e" data-pd="#a82424" data-pl="#e84444"
                                    data-d="#1a1a1a" data-d2="#2d2d2d" data-bg="#f5f5f5">
                                🎪 Karneval Rot
                            </button>
                            <button type="button" class="btn btn-sm preset-btn"
                                    style="background:#1d4ed8;color:#fff;border:none;"
                                    data-p="#1d4ed8" data-pd="#1e40af" data-pl="#3b82f6"
                                    data-d="#0f172a" data-d2="#1e293b" data-bg="#f8fafc">
                                👑 Königsblau
                            </button>
                            <button type="button" class="btn btn-sm preset-btn"
                                    style="background:#059669;color:#fff;border:none;"
                                    data-p="#059669" data-pd="#047857" data-pl="#10b981"
                                    data-d="#1a1a1a" data-d2="#2d2d2d" data-bg="#f0fdf4">
                                🌿 Smaragd
                            </button>
                            <button type="button" class="btn btn-sm preset-btn"
                                    style="background:#7c3aed;color:#fff;border:none;"
                                    data-p="#7c3aed" data-pd="#6d28d9" data-pl="#8b5cf6"
                                    data-d="#1a1a1a" data-d2="#2d2d2d" data-bg="#f5f3ff">
                                💜 Violett
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Farben ──────────────────────────────────────────────────── -->
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-droplet-half text-warning me-2"></i>Farbpalette
                    </div>
                    <div class="card-body">
                        <div class="row g-3">

                            <?php
                            $colorFields = [
                                ['key' => 'color_primary',       'label' => 'Akzentfarbe (Primär)',     'hint' => 'Buttons, Navbar-Rand, Highlights'],
                                ['key' => 'color_primary_dark',  'label' => 'Akzent dunkel',            'hint' => 'Hover-Zustand, Verläufe'],
                                ['key' => 'color_primary_light', 'label' => 'Akzent hell',              'hint' => 'Leichte Highlights'],
                                ['key' => 'color_dark',          'label' => 'Dunkel (Navbar/Footer)',   'hint' => 'Haupthintergrund Navbar & Footer'],
                                ['key' => 'color_dark2',         'label' => 'Dunkel-2',                 'hint' => 'Sekundäre dunkle Flächen'],
                                ['key' => 'color_bg',            'label' => 'Seitenhintergrund',        'hint' => 'Hintergrundfarbe der Seite'],
                            ];
                            foreach ($colorFields as $cf):
                                $val = htmlspecialchars($s[$cf['key']]);
                            ?>
                            <div class="col-12 col-md-6 col-lg-4">
                                <label class="form-label fw-semibold small">
                                    <?= $cf['label'] ?>
                                    <span class="text-muted fw-normal"> – <?= $cf['hint'] ?></span>
                                </label>
                                <div class="input-group">
                                    <input type="color"
                                           class="form-control form-control-color color-picker"
                                           name="<?= $cf['key'] ?>"
                                           value="<?= $val ?>"
                                           data-var-name="<?= $cf['key'] ?>"
                                           title="<?= $cf['label'] ?>">
                                    <input type="text"
                                           class="form-control font-monospace color-text"
                                           value="<?= $val ?>"
                                           maxlength="7"
                                           pattern="^#[0-9a-fA-F]{6}$"
                                           data-for="<?= $cf['key'] ?>">
                                </div>
                            </div>
                            <?php endforeach; ?>

                        </div>

                        <!-- Live-Vorschau -->
                        <div class="mt-4 p-3 rounded" id="colorPreview"
                             style="background:<?= htmlspecialchars($s['color_dark']) ?>;border-top:3px solid <?= htmlspecialchars($s['color_primary']) ?>;">
                            <span style="color:#fff;font-weight:700;font-size:1rem;">
                                <span id="previewName" style="color:<?= htmlspecialchars($s['color_primary']) ?>;"><?= htmlspecialchars($s['app_name'] ?: APP_NAME) ?></span>
                            </span>
                            &nbsp;
                            <span class="badge ms-2" id="previewBadge"
                                  style="background:<?= htmlspecialchars($s['color_primary']) ?>;color:#fff;">
                                Vorschau
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Marke & Texte ───────────────────────────────────────────── -->
            <div class="col-12 col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-type text-warning me-2"></i>Marke & Texte
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">App-Name / Markenname</label>
                            <input type="text" name="app_name" class="form-control"
                                   value="<?= htmlspecialchars($s['app_name']) ?>"
                                   maxlength="60" placeholder="z.B. Kameruner-Tickets">
                            <div class="form-text">Wird in Navbar, Browser-Tab und E-Mails angezeigt.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Slogan (Startseite)</label>
                            <input type="text" name="app_slogan" class="form-control"
                                   value="<?= htmlspecialchars($s['app_slogan']) ?>"
                                   maxlength="200" placeholder="Ihr Slogan für die Startseite (optional)">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Schriftart ──────────────────────────────────────────────── -->
            <div class="col-12 col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-fonts text-warning me-2"></i>Schriftart
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Schriftart auswählen</label>
                            <select name="font_family" id="fontSelect" class="form-select">
                                <?php
                                $fonts = [
                                    'system'    => 'Modern (Standard)',
                                    'humanist'  => 'Freundlich',
                                    'geometric' => 'Geometrisch',
                                    'classic'   => 'Klassisch (Serif)',
                                    'condensed' => 'Schmal',
                                    'mono'      => 'Technisch (Monospace)',
                                ];
                                $fontStacks = [
                                    'system'    => "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
                                    'humanist'  => "'Segoe UI', Candara, 'Trebuchet MS', Verdana, sans-serif",
                                    'geometric' => "Futura, 'Century Gothic', 'Avenir Next', Avenir, sans-serif",
                                    'classic'   => "Georgia, 'Times New Roman', serif",
                                    'condensed' => "'Arial Narrow', 'Roboto Condensed', Impact, sans-serif",
                                    'mono'      => "'SF Mono', 'Cascadia Mono', Menlo, Consolas, monospace",
                                ];
                                foreach ($fonts as $fk => $fl):
                                ?>
                                <option value="<?= $fk ?>" <?= $s['font_family'] === $fk ? 'selected' : '' ?>>
                                    <?= $fl ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="p-3 border rounded" id="fontPreview"
                             style="font-family: <?= htmlspecialchars($fontStacks[$s['font_family']] ?? $fontStacks['system']) ?>;">
                            <p class="mb-1 fw-bold">Schriftvorschau</p>
                            <p class="mb-0 text-muted small">Reservierungen, Events und mehr – in Ihrer gewählten Schrift.</p>
                        </div>
                        <div class="form-text mt-2">
                            System-Schriften – kein Download nötig, funktioniert auch offline.
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Speichern ───────────────────────────────────────────────── -->
            <div class="col-12">
                <button type="submit" class="btn btn-warning px-4 fw-bold">
                    <i class="bi bi-floppy me-2"></i>Einstellungen speichern
                </button>
            </div>

        </div>
    </form>

    <!-- ── Logo ──────────────────────────────────────────────────────────────── -->
    <div class="row g-4 mt-2">
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-semibold">
                    <i class="bi bi-image text-warning me-2"></i>Logo
                </div>
                <div class="card-body">
                    <?php $logo = getSetting('app_logo', ''); ?>
                    <?php if ($logo && file_exists(UPLOAD_DIR . $logo)): ?>
                    <div class="mb-3 p-3 bg-dark rounded text-center" style="max-width:200px;">
                        <img src="/uploads/<?= htmlspecialchars($logo) ?>"
                             alt="Logo" style="max-width:120px;max-height:80px;object-fit:contain;">
                    </div>
                    <form method="POST" class="mb-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="post_action" value="remove_logo">
                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                onclick="return confirm('Logo entfernen?')">
                            <i class="bi bi-trash me-1"></i>Logo entfernen
                        </button>
                    </form>
                    <?php else: ?>
                    <p class="text-muted small mb-3">Kein Logo hochgeladen. Ohne Logo wird kein Bild in Navbar und Startseite angezeigt.</p>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data">
                        <?= csrfField() ?>
                        <input type="hidden" name="post_action" value="upload_logo">
                        <div class="mb-2">
                            <input type="file" name="logo_file" class="form-control form-control-sm"
                                   accept="image/png,image/jpeg,image/gif,image/webp">
                        </div>
                        <div class="form-text mb-2">PNG, JPG, GIF oder WebP – max. 2 MB. Empfohlen: quadratisch, mind. 200×200 px.</div>
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-upload me-1"></i>Logo hochladen
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ── Favicon ──────────────────────────────────────────────────────── -->
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-semibold">
                    <i class="bi bi-browser-chrome text-warning me-2"></i>Favicon (Browser-Tab-Symbol)
                </div>
                <div class="card-body">
                    <?php $fav = getSetting('app_favicon', ''); ?>
                    <?php if ($fav && file_exists(UPLOAD_DIR . $fav)): ?>
                    <div class="mb-3 p-2 bg-light rounded d-inline-flex align-items-center gap-2 border">
                        <img src="/uploads/<?= htmlspecialchars($fav) ?>"
                             alt="Favicon" style="width:32px;height:32px;object-fit:contain;">
                        <small class="text-muted"><?= htmlspecialchars($fav) ?></small>
                    </div>
                    <br>
                    <form method="POST" class="mb-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="post_action" value="remove_favicon">
                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                onclick="return confirm('Favicon entfernen?')">
                            <i class="bi bi-trash me-1"></i>Favicon entfernen
                        </button>
                    </form>
                    <?php else: ?>
                    <p class="text-muted small mb-3">Kein Favicon hochgeladen (Browser verwendet Standard-Symbol).</p>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data">
                        <?= csrfField() ?>
                        <input type="hidden" name="post_action" value="upload_favicon">
                        <div class="mb-2">
                            <input type="file" name="favicon_file" class="form-control form-control-sm"
                                   accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/webp">
                        </div>
                        <div class="form-text mb-2">PNG oder ICO – max. 512 KB. Empfohlen: 32×32 px oder 64×64 px.</div>
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-upload me-1"></i>Favicon hochladen
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</main>

<?php
$googleFontMap = ['lato' => 'Lato', 'poppins' => 'Poppins', 'roboto' => 'Roboto', 'oswald' => 'Oswald'];
$extraScripts = <<<HTML
<script>
(function () {
    // ─── Presets ───────────────────────────────────────────────────────────
    document.querySelectorAll('.preset-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var map = {
                color_primary:       btn.dataset.p,
                color_primary_dark:  btn.dataset.pd,
                color_primary_light: btn.dataset.pl,
                color_dark:          btn.dataset.d,
                color_dark2:         btn.dataset.d2,
                color_bg:            btn.dataset.bg,
            };
            Object.keys(map).forEach(function (key) {
                var picker = document.querySelector('[name="' + key + '"][type="color"]');
                var text   = document.querySelector('[data-for="' + key + '"]');
                if (picker) picker.value = map[key];
                if (text)   text.value  = map[key];
            });
            updatePreview();
        });
    });

    // ─── Color picker ↔ Text field sync ───────────────────────────────────
    document.querySelectorAll('.color-picker').forEach(function (picker) {
        picker.addEventListener('input', function () {
            var text = document.querySelector('[data-for="' + picker.name + '"]');
            if (text) text.value = picker.value;
            updatePreview();
        });
    });
    document.querySelectorAll('.color-text').forEach(function (text) {
        text.addEventListener('input', function () {
            if (/^#[0-9a-fA-F]{6}$/.test(text.value)) {
                var picker = document.querySelector('[name="' + text.dataset.for + '"][type="color"]');
                if (picker) picker.value = text.value;
                updatePreview();
            }
        });
    });

    // ─── Live-Vorschau aktualisieren ────────────────────────────────────
    function getColor(key) {
        var el = document.querySelector('[name="' + key + '"][type="color"]');
        return el ? el.value : null;
    }
    function updatePreview() {
        var p  = getColor('color_primary');
        var d  = getColor('color_dark');
        var bg = getColor('color_bg');
        var preview = document.getElementById('colorPreview');
        var badge   = document.getElementById('previewBadge');
        var name    = document.getElementById('previewName');
        if (preview && p && d) {
            preview.style.background   = d;
            preview.style.borderTopColor = p;
        }
        if (badge && p)  { badge.style.background = p; }
        if (name  && p)  { name.style.color = p; }
    }

    // ─── Schriftart-Vorschau (System-Schriften, kein Nachladen nötig) ─────
    var fontSelect  = document.getElementById('fontSelect');
    var fontPreview = document.getElementById('fontPreview');
    var fontStacks = {
        'system':    "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
        'humanist':  "'Segoe UI', Candara, 'Trebuchet MS', Verdana, sans-serif",
        'geometric': "Futura, 'Century Gothic', 'Avenir Next', Avenir, sans-serif",
        'classic':   "Georgia, 'Times New Roman', serif",
        'condensed': "'Arial Narrow', 'Roboto Condensed', Impact, sans-serif",
        'mono':      "'SF Mono', 'Cascadia Mono', Menlo, Consolas, monospace"
    };
    if (fontSelect) {
        fontSelect.addEventListener('change', function () {
            var stack = fontStacks[fontSelect.value] || fontStacks['system'];
            if (fontPreview) fontPreview.style.fontFamily = stack;
        });
    }
})();
</script>
HTML;
include __DIR__ . '/../includes/footer.php';
?>

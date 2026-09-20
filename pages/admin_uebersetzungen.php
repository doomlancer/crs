<?php
/**
 * Admin: Übersetzungsverwaltung
 *
 * Zeigt alle Schlüssel aus lang/de.php und lang/en.php als bearbeitbare
 * Tabelle. Änderungen landen in translation_overrides (Migration 013) und
 * überschreiben die Sprachdatei nur für den jeweiligen Schlüssel — die
 * Sprachdatei selbst bleibt unverändert und ist die Vorgabe für neue
 * Installationen. "Zurücksetzen" entfernt die Überschreibung wieder.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');

$pdo   = getDB();
$myId  = (int)$_SESSION['user_id'];

// ─── POST-Handler ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiger Sicherheitstoken.');
        redirect('/pages/admin_uebersetzungen.php');
    }

    $postAction = $_POST['post_action'] ?? '';

    // ── Einen Schlüssel speichern ───────────────────────────────────────────
    if ($postAction === 'save') {
        $key = trim($_POST['key'] ?? '');
        $de  = (string)($_POST['de'] ?? '');
        $en  = (string)($_POST['en'] ?? '');

        // Nur bekannte Schlüssel akzeptieren – der Admin kann bestehende
        // Texte ändern, aber keine neuen, im Code nicht referenzierten
        // Schlüssel anlegen (die würden nirgends erscheinen und verwaisen).
        $catalog = getTranslationCatalog();
        if ($key === '' || !isset($catalog[$key])) {
            setFlash('error', 'Unbekannter Übersetzungsschlüssel.');
        } else {
            setTranslationOverride($key, $de, $en, $myId);
            logAudit('UPDATE', 'translation_overrides', null, "Schlüssel geändert: {$key}");
            setFlash('success', "„{$key}“ gespeichert.");
        }
        redirect('/pages/admin_uebersetzungen.php' . (isset($_POST['redirect_qs']) ? $_POST['redirect_qs'] : ''));
    }

    // ── Einen Schlüssel zurücksetzen ────────────────────────────────────────
    if ($postAction === 'reset') {
        $key = trim($_POST['key'] ?? '');
        if ($key !== '') {
            resetTranslationOverride($key);
            logAudit('UPDATE', 'translation_overrides', null, "Schlüssel zurückgesetzt: {$key}");
            setFlash('success', "„{$key}“ auf Vorgabe zurückgesetzt.");
        }
        redirect('/pages/admin_uebersetzungen.php' . (isset($_POST['redirect_qs']) ? $_POST['redirect_qs'] : ''));
    }
}

// ─── Katalog laden + filtern ─────────────────────────────────────────────────
$catalog = getTranslationCatalog();

$search = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? ''; // '', 'overridden', 'missing_en', 'unused'

$rows = [];
foreach ($catalog as $key => $c) {
    $isOverridden = $c['de_override'] !== null || $c['en_override'] !== null;
    $missingEn    = trim($c['en_override'] ?? $c['en_default']) === '';

    if ($search !== '') {
        $haystack = $key . ' ' . $c['de_default'] . ' ' . $c['en_default']
            . ' ' . ($c['de_override'] ?? '') . ' ' . ($c['en_override'] ?? '');
        if (mb_stripos($haystack, $search) === false) continue;
    }
    if ($filter === 'overridden' && !$isOverridden) continue;
    if ($filter === 'missing_en' && !$missingEn) continue;
    if ($filter === 'unused' && $c['used']) continue;

    $rows[$key] = $c + ['is_overridden' => $isOverridden, 'missing_en' => $missingEn];
}
ksort($rows);

$totalCount      = count($catalog);
$overriddenCount = count(array_filter($catalog, fn($c) => $c['de_override'] !== null || $c['en_override'] !== null));
$unusedCount     = count(array_filter($catalog, fn($c) => !$c['used']));

$qs = $_SERVER['QUERY_STRING'] ? '?' . htmlspecialchars($_SERVER['QUERY_STRING'], ENT_QUOTES) : '';

$pageTitle = 'Übersetzungen';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container-fluid py-4">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-translate text-warning me-2"></i>Übersetzungen
            </h1>
            <p class="text-muted mb-0 small">
                <?= $totalCount ?> Schlüssel · <?= $overriddenCount ?> angepasst ·
                <?= $unusedCount ?> im Code nicht (mehr) verwendet
            </p>
        </div>
        <a href="/pages/admin_dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Zurück
        </a>
    </div>

    <?= getFlash() ?>

    <div class="alert alert-info small mb-4">
        <i class="bi bi-info-circle me-1"></i>
        Änderungen hier überschreiben nur den angezeigten Schlüssel. Der
        Originaltext aus der Sprachdatei geht dabei nicht verloren –
        „Zurücksetzen“ stellt ihn wieder her. Neue Schlüssel lassen sich hier
        nicht anlegen, nur bestehende ändern.
    </div>

    <!-- Suche & Filter -->
    <form method="GET" class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-sm-5">
                    <label class="form-label small fw-semibold">Suche</label>
                    <input type="text" name="q" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($search) ?>"
                           placeholder="Schlüssel oder Text durchsuchen …">
                </div>
                <div class="col-sm-4">
                    <label class="form-label small fw-semibold">Filter</label>
                    <select name="filter" class="form-select form-select-sm">
                        <option value="">Alle Schlüssel</option>
                        <option value="overridden" <?= $filter === 'overridden' ? 'selected' : '' ?>>Nur angepasste</option>
                        <option value="missing_en" <?= $filter === 'missing_en' ? 'selected' : '' ?>>Ohne englischen Text</option>
                        <option value="unused" <?= $filter === 'unused' ? 'selected' : '' ?>>Im Code nicht verwendet</option>
                    </select>
                </div>
                <div class="col-sm-3 d-flex gap-2">
                    <button type="submit" class="btn btn-warning btn-sm flex-grow-1">
                        <i class="bi bi-search me-1"></i>Filtern
                    </button>
                    <?php if ($search !== '' || $filter !== ''): ?>
                    <a href="/pages/admin_uebersetzungen.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-lg"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:18%">Schlüssel</th>
                        <th style="width:36%">Deutsch</th>
                        <th style="width:36%">Englisch</th>
                        <th style="width:10%">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">Keine Treffer.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $key => $c): ?>
                    <tr>
                        <td>
                            <code class="small"><?= htmlspecialchars($key) ?></code>
                            <?php if (!$c['used']): ?>
                            <br><span class="badge bg-secondary-subtle text-secondary mt-1" data-bs-toggle="tooltip"
                                      title="Kein __() -Aufruf mit diesem Schlüssel im Code gefunden.">
                                <i class="bi bi-exclamation-triangle"></i> ungenutzt
                            </span>
                            <?php endif; ?>
                        </td>
                        <td colspan="2">
                            <form method="POST" class="row g-2">
                                <?= csrfField() ?>
                                <input type="hidden" name="post_action" value="save">
                                <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">
                                <input type="hidden" name="redirect_qs" value="<?= $qs ?>">
                                <div class="col-md-6">
                                    <textarea name="de" class="form-control form-control-sm" rows="1"
                                              placeholder="<?= htmlspecialchars($c['de_default']) ?>"
                                              onInput="this.rows = Math.min(4, this.value.split('\n').length)"
                                              ><?= htmlspecialchars($c['de_override'] ?? $c['de_default']) ?></textarea>
                                    <?php if ($c['de_override'] !== null): ?>
                                    <div class="form-text small">Vorgabe: <?= htmlspecialchars($c['de_default']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <textarea name="en" class="form-control form-control-sm" rows="1"
                                              placeholder="<?= htmlspecialchars($c['en_default']) ?>"
                                              onInput="this.rows = Math.min(4, this.value.split('\n').length)"
                                              ><?= htmlspecialchars($c['en_override'] ?? $c['en_default']) ?></textarea>
                                    <?php if ($c['en_override'] !== null): ?>
                                    <div class="form-text small">Vorgabe: <?= htmlspecialchars($c['en_default']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($c['missing_en'] ?? false): ?>
                                    <div class="form-text small text-danger">
                                        <i class="bi bi-exclamation-circle"></i> Kein englischer Text hinterlegt.
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-12 d-flex gap-2 justify-content-end">
                                    <button type="submit" class="btn btn-warning btn-sm">
                                        <i class="bi bi-check-lg me-1"></i>Speichern
                                    </button>
                                </div>
                            </form>
                            <?php if ($c['is_overridden'] ?? false): ?>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="post_action" value="reset">
                                <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">
                                <input type="hidden" name="redirect_qs" value="<?= $qs ?>">
                                <button type="submit" class="btn btn-link btn-sm text-muted p-0 mt-1"
                                        data-confirm="„<?= htmlspecialchars($key, ENT_QUOTES) ?>“ auf die Vorgabe aus der Sprachdatei zurücksetzen?">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Zurücksetzen
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($c['is_overridden'] ?? false): ?>
                            <span class="badge bg-warning text-dark">angepasst</span>
                            <?php else: ?>
                            <span class="badge bg-light text-muted border">Vorgabe</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php
/**
 * Visueller Tischplan-Editor
 * Bild hochladen und Tische per Klick positionieren.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');

$pdo     = getDB();
$eventId = (int)($_GET['event_id'] ?? 0);
$errors  = [];

if ($eventId < 1) {
    setFlash('error', 'Kein Event angegeben.');
    redirect('/pages/admin_events.php');
}

// Event laden
$stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
$stmt->execute([$eventId]);
$event = $stmt->fetch();
if (!$event) {
    setFlash('error', 'Event nicht gefunden.');
    redirect('/pages/admin_events.php');
}

// POST: Bild hochladen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiges CSRF-Token.');
        redirect('/pages/admin_tischplan_editor.php?event_id=' . $eventId);
    }

    $postAction = $_POST['post_action'] ?? '';

    if ($postAction === 'upload_image') {
        $file = $_FILES['tischplan_bild'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Fehler beim Hochladen. Bitte erneut versuchen.';
        } else {
            $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, $allowedMime, true)) {
                $errors[] = 'Nur JPG, PNG und WebP sind erlaubt.';
            } elseif ($file['size'] > 10 * 1024 * 1024) {
                $errors[] = 'Bild darf maximal 10 MB groß sein.';
            } else {
                $ext      = match($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    default      => 'webp',
                };
                $filename = 'event_' . $eventId . '_' . time() . '.' . $ext;
                $destDir  = __DIR__ . '/../uploads/tischplan/';
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0750, true);
                }
                $destPath = $destDir . $filename;

                // Altes Bild löschen
                if (!empty($event['tischplan_bild'])) {
                    $oldPath = $destDir . basename($event['tischplan_bild']);
                    if (file_exists($oldPath)) @unlink($oldPath);
                }

                if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                    $errors[] = 'Bild konnte nicht gespeichert werden.';
                } else {
                    $pdo->prepare('UPDATE events SET tischplan_bild = ? WHERE id = ?')
                        ->execute([$filename, $eventId]);
                    logAudit('UPDATE', 'events', $eventId, "Tischplan-Bild hochgeladen: {$filename}");
                    setFlash('success', 'Bild erfolgreich hochgeladen.');
                    redirect('/pages/admin_tischplan_editor.php?event_id=' . $eventId);
                }
            }
        }
    }

    if ($postAction === 'delete_image') {
        if (!empty($event['tischplan_bild'])) {
            $oldPath = __DIR__ . '/../uploads/tischplan/' . basename($event['tischplan_bild']);
            if (file_exists($oldPath)) @unlink($oldPath);
        }
        $pdo->prepare('UPDATE events SET tischplan_bild = NULL WHERE id = ?')->execute([$eventId]);
        $pdo->prepare('UPDATE `tables` SET pos_x = NULL, pos_y = NULL WHERE event_id = ?')->execute([$eventId]);
        logAudit('UPDATE', 'events', $eventId, 'Tischplan-Bild gelöscht, Positionen zurückgesetzt');
        setFlash('success', 'Bild und Positionen wurden gelöscht.');
        redirect('/pages/admin_tischplan_editor.php?event_id=' . $eventId);
    }
}

// Tische laden (mit Positionsdaten)
$stmt = $pdo->prepare(
    'SELECT t.id, t.tischnummer, t.max_plaetze, t.pos_x, t.pos_y,
            COUNT(s.id) AS sitze_gesamt,
            SUM(CASE WHEN s.status != "verfuegbar" THEN 1 ELSE 0 END) AS sitze_belegt
     FROM `tables` t
     LEFT JOIN seats s ON s.table_id = t.id
     WHERE t.event_id = ?
     GROUP BY t.id
     ORDER BY t.tischnummer'
);
$stmt->execute([$eventId]);
$tische = $stmt->fetchAll();

$hatBild      = !empty($event['tischplan_bild']);
$positioniert = count(array_filter($tische, fn($t) => $t['pos_x'] !== null));

$pageTitle = 'Tischplan-Editor: ' . $event['name'];
$bodyClass = 'bg-light';

$extraHead = '<style>
    #editor-canvas {
        position: relative;
        display: inline-block;
        cursor: crosshair;
        user-select: none;
        border: 2px dashed #dee2e6;
        border-radius: 8px;
        overflow: hidden;
        max-width: 100%;
    }
    #editor-canvas img {
        display: block;
        max-width: 100%;
        height: auto;
    }
    #editor-canvas.placing {
        cursor: crosshair;
        border-color: #f59e0b;
        box-shadow: 0 0 0 3px rgba(245,158,11,0.3);
    }
    .table-marker {
        position: absolute;
        transform: translate(-50%, -50%);
        background: rgba(59,130,246,0.85);
        color: #fff;
        border: 2px solid #1d4ed8;
        border-radius: 8px;
        padding: 4px 8px;
        font-size: 0.7rem;
        font-weight: 700;
        white-space: nowrap;
        cursor: pointer;
        z-index: 10;
        pointer-events: all;
        text-shadow: 0 1px 2px rgba(0,0,0,0.3);
        transition: background 0.15s;
    }
    .table-marker:hover { background: rgba(29,78,216,0.95); }
    .table-marker.active { background: rgba(245,158,11,0.9); border-color: #d97706; }
    .table-marker .remove-btn {
        margin-left: 4px;
        opacity: 0.8;
        cursor: pointer;
    }
    .table-marker .remove-btn:hover { opacity: 1; }
    .table-list-item { cursor: pointer; transition: background 0.1s; }
    .table-list-item:hover { background: #f8f9fa; }
    .table-list-item.placed { background: #e0f2fe; }
    .table-list-item.placing-active { background: #fef3c7; border-left: 3px solid #f59e0b; }
</style>';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container-fluid py-4">

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-map text-warning me-2"></i>Tischplan-Editor
            </h1>
            <p class="text-muted mb-0 small">
                <?= htmlspecialchars($event['name']) ?> &mdash; <?= formatDatum($event['datum']) ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="/pages/admin_events.php?action=tables&event_id=<?= $eventId ?>"
               class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Zurück zu Tischen
            </a>
            <a href="/pages/tischplan.php?event_id=<?= $eventId ?>"
               class="btn btn-sm btn-outline-primary" target="_blank">
                <i class="bi bi-eye me-1"></i>Tischplan ansehen
            </a>
        </div>
    </div>

    <?= getFlash() ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($tische)): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        Für dieses Event sind noch keine Tische angelegt.
        <a href="/pages/admin_events.php?action=tables&event_id=<?= $eventId ?>">Tische anlegen</a>
    </div>
    <?php else: ?>

    <div class="row g-4">

        <!-- Linke Spalte: Tischliste -->
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-list-ul me-2 text-secondary"></i>Tische
                    <span class="badge bg-secondary ms-1"><?= count($tische) ?></span>
                    <?php if ($positioniert > 0): ?>
                    <span class="badge bg-success ms-1"><?= $positioniert ?> platziert</span>
                    <?php endif; ?>
                </div>
                <div class="list-group list-group-flush" id="tableList">
                    <?php foreach ($tische as $t): ?>
                    <div class="list-group-item table-list-item <?= $t['pos_x'] !== null ? 'placed' : '' ?>"
                         data-table-id="<?= $t['id'] ?>"
                         data-tischnummer="<?= $t['tischnummer'] ?>"
                         data-max-plaetze="<?= $t['max_plaetze'] ?>"
                         data-pos-x="<?= $t['pos_x'] ?? '' ?>"
                         data-pos-y="<?= $t['pos_y'] ?? '' ?>"
                         onclick="selectTable(this)"
                         title="Klicken zum Platzieren">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <strong>Tisch <?= $t['tischnummer'] ?></strong>
                                <small class="text-muted d-block"><?= $t['max_plaetze'] ?> Plätze</small>
                            </div>
                            <div class="text-end">
                                <?php if ($t['pos_x'] !== null): ?>
                                <span class="badge bg-success"><i class="bi bi-check"></i></span>
                                <small class="text-muted d-block" style="font-size:0.65rem;">
                                    <?= round($t['pos_x'], 1) ?>% / <?= round($t['pos_y'], 1) ?>%
                                </small>
                                <?php else: ?>
                                <span class="badge bg-secondary">nicht gesetzt</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="card-footer bg-white">
                    <button class="btn btn-sm btn-outline-danger w-100" id="resetAllBtn"
                            onclick="resetAllPositions()">
                        <i class="bi bi-trash me-1"></i>Alle Positionen zurücksetzen
                    </button>
                </div>
            </div>

            <!-- Anleitung -->
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-body p-3">
                    <h6 class="fw-semibold mb-2"><i class="bi bi-info-circle text-info me-1"></i>Anleitung</h6>
                    <ol class="small text-muted ps-3 mb-0">
                        <li>Tischplan-Bild hochladen</li>
                        <li>Tisch aus der Liste anklicken</li>
                        <li>Auf dem Bild klicken, wo der Tisch stehen soll</li>
                        <li>Alle Tische platzieren</li>
                        <li>Auf <strong>Speichern</strong> klicken</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Mittlere Spalte: Bild-Editor -->
        <div class="col-lg-9">

            <!-- Bild hochladen -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <?php if ($hatBild): ?>
                            <span class="text-success fw-semibold">
                                <i class="bi bi-check-circle me-1"></i>Bild hochgeladen
                            </span>
                            <?php else: ?>
                            <span class="text-muted">
                                <i class="bi bi-image me-1"></i>Noch kein Bild hochgeladen
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
                                <?= csrfField() ?>
                                <input type="hidden" name="post_action" value="upload_image">
                                <input type="file" name="tischplan_bild" class="form-control form-control-sm"
                                       accept="image/jpeg,image/png,image/webp" required style="max-width:220px;">
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="bi bi-upload me-1"></i><?= $hatBild ? 'Ersetzen' : 'Hochladen' ?>
                                </button>
                            </form>
                            <?php if ($hatBild): ?>
                            <form method="post" class="d-inline"
                                  onsubmit="return confirm('Bild und alle Positionen löschen?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="post_action" value="delete_image">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Editor-Bereich -->
            <?php if ($hatBild): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <div>
                        <span id="placingHint" class="text-muted small">
                            <i class="bi bi-cursor me-1"></i>Tisch aus der Liste wählen, dann auf dem Bild platzieren
                        </span>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-secondary" id="cancelPlaceBtn"
                                style="display:none;" onclick="cancelPlacing()">
                            <i class="bi bi-x me-1"></i>Abbrechen
                        </button>
                        <button class="btn btn-sm btn-success" id="saveBtn" onclick="savePositions()">
                            <i class="bi bi-save me-1"></i>Positionen speichern
                        </button>
                    </div>
                </div>
                <div class="card-body text-center p-2">
                    <div id="editor-canvas" onclick="placeTable(event)">
                        <img src="/api/tischplan_image.php?event_id=<?= $eventId ?>"
                             alt="Tischplan" id="editorImg" draggable="false">
                        <!-- Marker werden per JS gerendert -->
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5 text-muted">
                    <i class="bi bi-image fs-1 d-block mb-3 opacity-25"></i>
                    <p>Lade ein Bild des Tischplans hoch, um mit dem Platzieren zu beginnen.</p>
                    <small>Empfohlen: JPG oder PNG, Querformat, mindestens 800×600 px</small>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</main>

<?php
// Tisch-Daten für JavaScript aufbereiten
$tischeJs = json_encode(array_map(fn($t) => [
    'id'          => (int)$t['id'],
    'tischnummer' => (int)$t['tischnummer'],
    'max_plaetze' => (int)$t['max_plaetze'],
    'pos_x'       => $t['pos_x'] !== null ? (float)$t['pos_x'] : null,
    'pos_y'       => $t['pos_y'] !== null ? (float)$t['pos_y'] : null,
], $tische));

$extraScripts = '<script>
const EVENT_ID   = ' . $eventId . ';
const CSRF_TOKEN = ' . json_encode($_SESSION['csrf_token'] ?? '') . ';
const tische     = ' . $tischeJs . ';

// Positionen im Speicher (table_id => {x, y})
const positions = {};
tische.forEach(t => {
    if (t.pos_x !== null && t.pos_y !== null) {
        positions[t.id] = { x: t.pos_x, y: t.pos_y };
    }
});

let activeTableId = null;

// Alle vorhandenen Marker beim Laden rendern
window.addEventListener("DOMContentLoaded", () => {
    tische.forEach(t => {
        if (t.pos_x !== null && t.pos_y !== null) {
            renderMarker(t.id, t.tischnummer, t.pos_x, t.pos_y);
        }
    });
});

function selectTable(el) {
    const tableId = parseInt(el.dataset.tableId);

    // Bereits aktiven deaktivieren
    document.querySelectorAll(".table-list-item").forEach(e => e.classList.remove("placing-active"));

    if (activeTableId === tableId) {
        // Zweiter Klick = abbrechen
        activeTableId = null;
        updatePlacingHint(null);
        document.getElementById("cancelPlaceBtn").style.display = "none";
        document.getElementById("editor-canvas")?.classList.remove("placing");
        return;
    }

    activeTableId = tableId;
    el.classList.add("placing-active");
    updatePlacingHint(el.dataset.tischnummer);
    document.getElementById("cancelPlaceBtn").style.display = "";
    document.getElementById("editor-canvas")?.classList.add("placing");
}

function cancelPlacing() {
    activeTableId = null;
    document.querySelectorAll(".table-list-item").forEach(e => e.classList.remove("placing-active"));
    updatePlacingHint(null);
    document.getElementById("cancelPlaceBtn").style.display = "none";
    document.getElementById("editor-canvas")?.classList.remove("placing");
}

function placeTable(event) {
    if (!activeTableId) return;

    const canvas = document.getElementById("editor-canvas");
    const img    = document.getElementById("editorImg");
    if (!img) return;

    const rect = img.getBoundingClientRect();
    const x    = ((event.clientX - rect.left) / rect.width)  * 100;
    const y    = ((event.clientY - rect.top)  / rect.height) * 100;

    positions[activeTableId] = { x: parseFloat(x.toFixed(2)), y: parseFloat(y.toFixed(2)) };

    // Marker aktualisieren
    const t = tische.find(t => t.id === activeTableId);
    if (t) renderMarker(t.id, t.tischnummer, x, y);

    // Listenelement aktualisieren
    const listItem = document.querySelector(`.table-list-item[data-table-id="${activeTableId}"]`);
    if (listItem) {
        listItem.classList.add("placed");
        listItem.querySelector(".badge.bg-secondary")?.remove();
        const badge = listItem.querySelector(".badge.bg-success") || document.createElement("span");
        badge.className = "badge bg-success";
        badge.innerHTML = "<i class=\"bi bi-check\"></i>";
        listItem.querySelector(".text-end").innerHTML =
            `<span class="badge bg-success"><i class="bi bi-check"></i></span>
             <small class="text-muted d-block" style="font-size:0.65rem;">${x.toFixed(1)}% / ${y.toFixed(1)}%</small>`;
    }

    cancelPlacing();
}

function renderMarker(tableId, tischnummer, x, y) {
    // Alten Marker entfernen
    document.getElementById("marker-" + tableId)?.remove();

    const canvas = document.getElementById("editor-canvas");
    if (!canvas) return;

    const marker = document.createElement("div");
    marker.className = "table-marker";
    marker.id = "marker-" + tableId;
    marker.style.left = x + "%";
    marker.style.top  = y + "%";
    marker.innerHTML  =
        `<i class="bi bi-grid-3x3 me-1"></i>T${tischnummer}` +
        `<span class="remove-btn" onclick="removeMarker(event, ${tableId})" title="Position entfernen">` +
        `<i class="bi bi-x"></i></span>`;
    canvas.appendChild(marker);
}

function removeMarker(event, tableId) {
    event.stopPropagation();
    delete positions[tableId];
    document.getElementById("marker-" + tableId)?.remove();

    const listItem = document.querySelector(`.table-list-item[data-table-id="${tableId}"]`);
    if (listItem) {
        listItem.classList.remove("placed");
        listItem.querySelector(".text-end").innerHTML =
            `<span class="badge bg-secondary">nicht gesetzt</span>`;
    }
}

function updatePlacingHint(tischnummer) {
    const hint = document.getElementById("placingHint");
    if (!hint) return;
    if (tischnummer) {
        hint.innerHTML = `<i class="bi bi-cursor-fill text-warning me-1"></i>` +
            `<strong class="text-warning">Tisch ${tischnummer}</strong> – Klick auf Bild zum Platzieren`;
    } else {
        hint.innerHTML = `<i class="bi bi-cursor me-1"></i>Tisch aus der Liste wählen, dann auf dem Bild platzieren`;
    }
}

async function savePositions() {
    const posArray = Object.entries(positions).map(([tableId, pos]) => ({
        table_id: parseInt(tableId),
        x: pos.x,
        y: pos.y
    }));

    if (posArray.length === 0) {
        alert("Keine Positionen zum Speichern.");
        return;
    }

    const btn = document.getElementById("saveBtn");
    btn.disabled = true;
    btn.innerHTML = "<span class=\"spinner-border spinner-border-sm me-1\"></span>Speichern...";

    try {
        const res = await fetch("/api/save_table_positions.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ event_id: EVENT_ID, positions: posArray })
        });
        const data = await res.json();
        if (data.ok) {
            btn.innerHTML = "<i class=\"bi bi-check me-1\"></i>Gespeichert!";
            btn.className = "btn btn-sm btn-success";
            setTimeout(() => {
                btn.innerHTML = "<i class=\"bi bi-save me-1\"></i>Positionen speichern";
                btn.disabled = false;
            }, 2000);
        } else {
            throw new Error(data.error || "Unbekannter Fehler");
        }
    } catch (e) {
        alert("Fehler: " + e.message);
        btn.disabled = false;
        btn.innerHTML = "<i class=\"bi bi-save me-1\"></i>Positionen speichern";
    }
}

async function resetAllPositions() {
    if (!confirm("Alle Positionen wirklich zurücksetzen?")) return;

    const posArray = tische.map(t => ({ table_id: t.id, x: -1, y: -1 }));

    try {
        await fetch("/api/save_table_positions.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                event_id: EVENT_ID,
                positions: tische.map(t => ({ table_id: t.id, x: null, y: null }))
            })
        });
    } catch(e) {}

    // Alle auf NULL setzen via POST reload
    location.reload();
}
</script>';

include __DIR__ . '/../includes/footer.php';
?>

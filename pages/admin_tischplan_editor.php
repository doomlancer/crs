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
        -webkit-user-select: none;
        -moz-user-select: none;
        user-select: none;
        /* touch-action: none verhindert Scroll/Zoom beim Tippen auf dem Canvas (iOS) */
        touch-action: none;
        -ms-touch-action: none;
        border: 2px dashed #dee2e6;
        border-radius: 8px;
        overflow: hidden;
        max-width: 100%;
        /* cursor:pointer als Fallback aktiviert click-Events auf iOS Safari divs */
        -webkit-tap-highlight-color: rgba(0,0,0,0);
    }
    #editor-canvas img {
        display: block;
        max-width: 100%;
        height: auto;
        /* pointer-events:none leitet Klick/Touch direkt an den Canvas-Div weiter */
        pointer-events: none;
        -webkit-user-drag: none;
        user-drag: none;
    }
    #editor-canvas.placing {
        cursor: crosshair;
        border-color: #f59e0b;
        box-shadow: 0 0 0 3px rgba(245,158,11,0.3);
    }
    .table-marker {
        position: absolute;
        transform: translate(-50%, -50%);
        -webkit-transform: translate(-50%, -50%);
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
        -webkit-tap-highlight-color: rgba(0,0,0,0);
    }
    .table-marker:hover { background: rgba(29,78,216,0.95); }
    .table-marker.active { background: rgba(245,158,11,0.9); border-color: #d97706; }
    .table-marker .remove-btn {
        display: inline-block;
        margin-left: 4px;
        opacity: 0.8;
        cursor: pointer;
        padding: 2px 4px;
        -webkit-tap-highlight-color: rgba(0,0,0,0);
    }
    .table-marker .remove-btn:hover { opacity: 1; }
    .table-list-item {
        cursor: pointer;
        transition: background 0.1s;
        -webkit-tap-highlight-color: rgba(0,0,0,0.05);
    }
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
                    <div id="editor-canvas" onclick="">
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
// Tisch-Daten für JavaScript aufbereiten (JSON, sicher für direkte JS-Einbettung)
$tischeJs = json_encode(array_map(fn($t) => [
    'id'          => (int)$t['id'],
    'tischnummer' => (int)$t['tischnummer'],
    'max_plaetze' => (int)$t['max_plaetze'],
    'pos_x'       => $t['pos_x'] !== null ? (float)$t['pos_x'] : null,
    'pos_y'       => $t['pos_y'] !== null ? (float)$t['pos_y'] : null,
], $tische), JSON_HEX_TAG | JSON_HEX_AMP);
?>
<script>
/* ============================================================
   Tischplan-Editor – Tische visuell platzieren
   Direkt eingebettet, kein IIFE, globale Funktionen
   ============================================================ */

var _EVT   = <?= $eventId ?>;
var _CSRF  = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG) ?>;
var _TBL   = <?= $tischeJs ?>;

/* Zustand */
var _pos       = {};   /* table_id => {x, y} */
var _active    = null; /* aktive table_id */
var _touched   = false;/* Touch-Guard: verhindert touch→click Doppelauslösung */

/* Positionen aus DB vorbelegen */
for (var _i = 0; _i < _TBL.length; _i++) {
    if (_TBL[_i].pos_x !== null && _TBL[_i].pos_y !== null) {
        _pos[_TBL[_i].id] = { x: _TBL[_i].pos_x, y: _TBL[_i].pos_y };
    }
}

/* ----------------------------------------------------------
   Editor initialisieren (nach DOM-Bereitschaft)
   ---------------------------------------------------------- */
function _initEditor() {
    var canvas = document.getElementById("editor-canvas");
    if (!canvas) return;

    /* Touch: Scroll blockieren wenn Tisch aktiv, Platzierung auslösen */
    canvas.addEventListener("touchstart", function(e) {
        if (_active) e.preventDefault();
    }, { passive: false });

    canvas.addEventListener("touchend", function(e) {
        if (!_active) return;
        if (!e.changedTouches || e.changedTouches.length !== 1) return;
        e.preventDefault();
        _touched = true;
        var t = e.changedTouches[0];
        _doPlace(t.clientX, t.clientY, t.target || e.target);
        setTimeout(function() { _touched = false; }, 600);
    }, { passive: false });

    /* Click: Desktop und iOS (touchGuard verhindert Doppelauslösung) */
    canvas.addEventListener("click", function(e) {
        if (_touched) return;
        _doPlace(e.clientX, e.clientY, e.target || e.srcElement);
    });

    /* Vorhandene Marker zeichnen */
    for (var i = 0; i < _TBL.length; i++) {
        if (_TBL[i].pos_x !== null && _TBL[i].pos_y !== null) {
            _renderMarker(_TBL[i].id, _TBL[i].tischnummer, _TBL[i].pos_x, _TBL[i].pos_y);
        }
    }
}

/* ----------------------------------------------------------
   Tisch aus Liste auswählen  (onclick="selectTable(this)")
   ---------------------------------------------------------- */
function selectTable(el) {
    var tid   = parseInt(el.getAttribute("data-table-id"), 10);
    var items = document.querySelectorAll(".table-list-item");
    for (var i = 0; i < items.length; i++) items[i].classList.remove("placing-active");

    var cBtn   = document.getElementById("cancelPlaceBtn");
    var canvas = document.getElementById("editor-canvas");

    if (_active === tid) { cancelPlacing(); return; }

    _active = tid;
    el.classList.add("placing-active");
    _hint(el.getAttribute("data-tischnummer"));
    if (cBtn)   cBtn.style.display   = "";
    if (canvas) canvas.classList.add("placing");
}

/* ----------------------------------------------------------
   Platzierung abbrechen  (onclick="cancelPlacing()")
   ---------------------------------------------------------- */
function cancelPlacing() {
    _active = null;
    var items = document.querySelectorAll(".table-list-item");
    for (var i = 0; i < items.length; i++) items[i].classList.remove("placing-active");
    _hint(null);
    var cBtn   = document.getElementById("cancelPlaceBtn");
    var canvas = document.getElementById("editor-canvas");
    if (cBtn)   cBtn.style.display = "none";
    if (canvas) canvas.classList.remove("placing");
}

/* ----------------------------------------------------------
   Koordinaten berechnen und Marker setzen (intern)
   ---------------------------------------------------------- */
function _doPlace(clientX, clientY, target) {
    if (!_active) return;

    /* Klick auf Marker selbst → ignorieren */
    var node = target;
    while (node && node.id !== "editor-canvas") {
        if (node.classList &&
            (node.classList.contains("table-marker") ||
             node.classList.contains("remove-btn"))) return;
        node = node.parentNode;
        if (!node) break;
    }

    var img = document.getElementById("editorImg");
    if (!img) return;

    var r = img.getBoundingClientRect();
    var x = ((clientX - r.left) / r.width)  * 100;
    var y = ((clientY - r.top)  / r.height) * 100;
    if (x < 0 || x > 100 || y < 0 || y > 100) return;

    x = Math.round(x * 100) / 100;
    y = Math.round(y * 100) / 100;
    _pos[_active] = { x: x, y: y };

    /* Tisch-Objekt finden */
    var found = null;
    for (var i = 0; i < _TBL.length; i++) {
        if (_TBL[i].id === _active) { found = _TBL[i]; break; }
    }
    if (found) _renderMarker(found.id, found.tischnummer, x, y);

    /* Listeneintrag aktualisieren */
    var li = document.querySelector(".table-list-item[data-table-id='" + _active + "']");
    if (li) {
        li.classList.add("placed");
        var te = li.querySelector(".text-end");
        if (te) te.innerHTML =
            '<span class="badge bg-success"><i class="bi bi-check"></i></span>' +
            '<small class="text-muted d-block" style="font-size:0.65rem;">' +
            x.toFixed(1) + '% / ' + y.toFixed(1) + '%</small>';
    }

    cancelPlacing();
}

/* ----------------------------------------------------------
   Marker im Canvas zeichnen
   ---------------------------------------------------------- */
function _renderMarker(tableId, tischnummer, x, y) {
    var old = document.getElementById("marker-" + tableId);
    if (old) old.parentNode.removeChild(old);

    var canvas = document.getElementById("editor-canvas");
    if (!canvas) return;

    var m = document.createElement("div");
    m.className  = "table-marker";
    m.id         = "marker-" + tableId;
    m.style.left = x + "%";
    m.style.top  = y + "%";
    m.innerHTML  = '<i class="bi bi-grid-3x3 me-1"></i>T' + tischnummer +
                   '<span class="remove-btn" title="Entfernen"><i class="bi bi-x"></i></span>';

    var rb = m.querySelector(".remove-btn");
    if (rb) {
        rb.addEventListener("touchend", function(ev) {
            ev.stopPropagation(); ev.preventDefault();
            _touched = true;
            _removeMarker(tableId);
            setTimeout(function() { _touched = false; }, 600);
        }, { passive: false });
        rb.addEventListener("click", function(ev) {
            ev.stopPropagation();
            if (_touched) return;
            _removeMarker(tableId);
        });
    }
    canvas.appendChild(m);
}

/* ----------------------------------------------------------
   Marker entfernen
   ---------------------------------------------------------- */
function _removeMarker(tableId) {
    delete _pos[tableId];
    var el = document.getElementById("marker-" + tableId);
    if (el) el.parentNode.removeChild(el);
    var li = document.querySelector(".table-list-item[data-table-id='" + tableId + "']");
    if (li) {
        li.classList.remove("placed");
        var te = li.querySelector(".text-end");
        if (te) te.innerHTML = '<span class="badge bg-secondary">nicht gesetzt</span>';
    }
}

/* ----------------------------------------------------------
   Hinweistext im Editor-Header
   ---------------------------------------------------------- */
function _hint(tischnummer) {
    var h = document.getElementById("placingHint");
    if (!h) return;
    h.innerHTML = tischnummer
        ? '<i class="bi bi-cursor-fill text-warning me-1"></i><strong class="text-warning">Tisch ' +
          tischnummer + '</strong> &ndash; Klicken/Tippen auf Bild'
        : '<i class="bi bi-cursor me-1"></i>Tisch aus Liste w&auml;hlen, dann auf Bild platzieren';
}

/* ----------------------------------------------------------
   Positionen speichern  (onclick="savePositions()")
   ---------------------------------------------------------- */
function savePositions() {
    var arr  = [];
    var keys = Object.keys(_pos);
    for (var i = 0; i < keys.length; i++) {
        arr.push({ table_id: parseInt(keys[i], 10), x: _pos[keys[i]].x, y: _pos[keys[i]].y });
    }
    if (arr.length === 0) { alert("Keine Positionen zum Speichern."); return; }

    var btn = document.getElementById("saveBtn");
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Speichern...';

    fetch("/api/save_table_positions.php", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ event_id: _EVT, csrf_token: _CSRF, positions: arr })
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.ok) {
            btn.innerHTML = '<i class="bi bi-check me-1"></i>Gespeichert!';
            btn.className = "btn btn-sm btn-success";
            setTimeout(function() {
                btn.innerHTML = '<i class="bi bi-save me-1"></i>Positionen speichern';
                btn.disabled  = false;
            }, 2000);
        } else { throw new Error(d.error || "Fehler"); }
    })
    .catch(function(err) {
        alert("Fehler: " + err.message);
        btn.disabled  = false;
        btn.innerHTML = '<i class="bi bi-save me-1"></i>Positionen speichern';
    });
}

/* ----------------------------------------------------------
   Alle Positionen zurücksetzen  (onclick="resetAllPositions()")
   ---------------------------------------------------------- */
function resetAllPositions() {
    if (!confirm("Alle Positionen wirklich zur\u00fccksetzen?")) return;
    var arr = [];
    for (var i = 0; i < _TBL.length; i++) arr.push({ table_id: _TBL[i].id, x: null, y: null });
    fetch("/api/save_table_positions.php", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ event_id: _EVT, csrf_token: _CSRF, positions: arr })
    })
    .then(function() { location.reload(); })
    .catch(function() { location.reload(); });
}

/* ----------------------------------------------------------
   Start
   ---------------------------------------------------------- */
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", _initEditor);
} else {
    _initEditor();
}
</script>
<?php
include __DIR__ . '/../includes/footer.php';
?>

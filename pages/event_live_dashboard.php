<?php
/**
 * Live-Event-Dashboard – Ampel-Übersicht pro Sitzplatz/Ticket.
 * Rot = nicht verkauft, Gelb = verkauft/reserviert, Grün = eingecheckt.
 * Aktualisiert sich live per Polling; Klick auf eine Kachel zeigt den
 * QR-Code des Tickets (z.B. bei einem verlorenen Ticket erneut anzeigen).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

requireRole('kassierer', 'admin');

$pdo = getDB();

// ─── Saalfoto hochladen (Editor-Modus) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['post_action'] ?? '') === 'upload_floorplan') {
    $uploadEventId = (int)($_POST['event_id'] ?? 0);
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Sicherheitsfehler. Bitte erneut versuchen.');
        redirect('/pages/event_live_dashboard.php?event_id=' . $uploadEventId . '&mode=editor');
    }
    if ($uploadEventId > 0 && !empty($_FILES['floorplan']['name'])) {
        $result = saveUploadedImage($_FILES['floorplan'], 'floorplan', 5 * 1024 * 1024);
        if ($result['ok']) {
            ensureTischplanEditorColumns();
            $stmtOld = $pdo->prepare('SELECT tischplan_bild FROM events WHERE id = ?');
            $stmtOld->execute([$uploadEventId]);
            $oldFile = $stmtOld->fetchColumn();
            $pdo->prepare('UPDATE events SET tischplan_bild = ? WHERE id = ?')
                ->execute([$result['name'], $uploadEventId]);
            if ($oldFile) deleteUploadedFile($oldFile);
            logAudit('UPDATE', 'events', $uploadEventId, 'Saalfoto hochgeladen: ' . $result['name']);
            setFlash('success', 'Saalfoto hochgeladen.');
        } else {
            setFlash('error', $result['error']);
        }
    }
    redirect('/pages/event_live_dashboard.php?event_id=' . $uploadEventId . '&mode=editor');
}

// ─── Event-Selektor (gleiche Query wie kassierer_dashboard.php) ──────────────
$events = $pdo->query(
    "SELECT id, name, datum, status
     FROM events
     WHERE status IN ('aktiv','planung')
     ORDER BY datum ASC"
)->fetchAll();

$selectedEventId = (int)($_GET['event_id'] ?? ($events[0]['id'] ?? 0));

$currentEvent = null;
$grid         = null;
if ($selectedEventId) {
    $stmtEv = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $stmtEv->execute([$selectedEventId]);
    $currentEvent = $stmtEv->fetch() ?: null;
    if ($currentEvent) {
        $grid = getEventLiveGrid($selectedEventId);
    }
}

$sitzplanMode = ($_GET['mode'] ?? '') === 'editor' ? 'editor' : 'auto';

// Fallback-Position für Tische ohne gespeicherte Koordinaten (Migration 003,
// bisher ungenutzt): einfaches Raster, damit im Editor beim ersten Aufruf
// nichts übereinander liegt. Sobald ein Tisch verschoben wird, wird seine
// echte Position gespeichert und dieser Fallback greift für ihn nicht mehr.
if ($grid && $grid['event_typ'] === 'tischplan') {
    foreach ($grid['tables'] as $i => &$t) {
        if ($t['pos_x'] === null || $t['pos_y'] === null) {
            $col = $i % 4;
            $row = intdiv($i, 4);
            $t['pos_x'] = 12 + $col * 25;
            $t['pos_y'] = 15 + $row * 28;
        }
    }
    unset($t);
}

$pageTitle = 'Live-Übersicht';
$bodyClass = 'bg-light';

$extraHead = '
<style>
/* Ampel-Kacheln: eigene, hartkodierte Farben – unabhängig vom Vereins-Theme
   (.bg-warning/.text-warning sind im Theme-System auf die Vereinsfarbe
   gemappt, siehe css/style.css – für die Ampel-Semantik ungeeignet). */
.live-tile {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 46px;
    height: 46px;
    padding: 0 8px;
    border-radius: 10px;
    font-weight: 700;
    font-size: .8rem;
    border: 2px solid transparent;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
    transition: background-color .2s ease;
}
.tile-rot   { background:#ef4444; color:#fff; border-color:#dc2626; cursor:default; }
.tile-gelb  { background:#eab308; color:#1a1a1a; border-color:#ca8a04; cursor:pointer; }
.tile-gruen { background:#22c55e; color:#fff; border-color:#16a34a; cursor:pointer; }
.tile-ghost { background:transparent; color:#ef4444; border:2px dashed #ef4444; opacity:.5; cursor:default; }
.legend-dot { display:inline-block; width:16px; height:16px; border-radius:4px; vertical-align:middle; margin-right:4px; }
.live-table-card { border-left: 3px solid #eab308; }
#search-results .list-group-item { cursor: pointer; }

/* ── Sitzplan: Automatisch-Modus ─────────────────────────────────────────── */
.table-tile-btn {
    display: flex; flex-direction: column; gap: 6px; width: 100%; text-align: left;
    background: #fff; border: 2px solid #e9ecef; border-radius: 10px; padding: 10px 12px;
    cursor: pointer;
}
.table-tile-btn[aria-expanded="true"] { border-color: #eab308; }
.occ-bar { display: flex; width: 100%; height: 7px; border-radius: 4px; overflow: hidden; background: #e9ecef; }
.occ-bar span { display: block; height: 100%; }

/* ── Sitzplan: Editor-Modus ───────────────────────────────────────────────── */
.editor-canvas {
    position: relative; width: 100%; aspect-ratio: 16/10; border-radius: 12px;
    background-color: #f8f9fa; background-size: cover; background-position: center;
    background-image: repeating-linear-gradient(0deg, #eee, #eee 1px, transparent 1px, transparent 40px),
                       repeating-linear-gradient(90deg, #eee, #eee 1px, transparent 1px, transparent 40px);
    border: 1px dashed #ced4da; overflow: hidden; touch-action: none;
}
.editor-canvas.has-photo { background-image: none; border-style: solid; }
.editor-chip {
    position: absolute; transform: translate(-50%, -50%); cursor: grab;
    background: #1a1a1a; color: #fff; border-radius: 8px; padding: 6px 10px;
    font-size: .78rem; font-weight: 700; box-shadow: 0 2px 6px rgba(0,0,0,.25);
    user-select: none; touch-action: none; white-space: nowrap;
}
.editor-chip:active { cursor: grabbing; }
.editor-chip.dragging { opacity: .85; box-shadow: 0 4px 14px rgba(0,0,0,.4); z-index: 5; }
</style>';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container-fluid py-4">

    <!-- Seitentitel -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-broadcast text-warning me-2"></i>Live-Übersicht
            </h1>
            <p class="text-muted mb-0 small">
                <?php if ($currentEvent): ?>
                    <?= htmlspecialchars($currentEvent['name']) ?>
                    &bull; <?= formatDatum($currentEvent['datum']) ?>
                <?php else: ?>
                    Bitte ein Event auswählen
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="/pages/kassierer_dashboard.php<?= $selectedEventId ? '?event_id=' . $selectedEventId : '' ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
            <a href="/pages/kassierer_guestlist.php<?= $selectedEventId ? '?event_id=' . $selectedEventId : '' ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-people me-1"></i>Gästeliste
            </a>
            <a href="/pages/kassierer_scan.php" class="btn btn-warning btn-sm">
                <i class="bi bi-qr-code-scan me-1"></i>QR-Scanner
            </a>
        </div>
    </div>

    <?= getFlash() ?>

    <!-- Event-Selektor -->
    <?php if (count($events) > 1): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="" class="d-flex align-items-center gap-2">
                <label for="event_id" class="form-label mb-0 fw-semibold text-nowrap small">
                    <i class="bi bi-calendar3 text-warning me-1"></i>Event:
                </label>
                <select name="event_id" id="event_id" class="form-select form-select-sm"
                        style="max-width:400px;" data-autosubmit>
                    <?php foreach ($events as $ev): ?>
                    <option value="<?= (int)$ev['id'] ?>" <?= $ev['id'] == $selectedEventId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ev['name']) ?> (<?= formatDatum($ev['datum']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$currentEvent): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>Kein Event ausgewählt.
    </div>
    <?php else: ?>

    <!-- Ticket-Suche: verlorenes Ticket per Namen finden -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <label class="form-label fw-semibold small">
                <i class="bi bi-search me-1"></i>Ticket suchen (verlorenes Ticket erneut anzeigen)
            </label>
            <div class="position-relative">
                <input type="text" id="ticket-search" class="form-control"
                       placeholder="Name, E-Mail oder Buchungsnummer eingeben…" autocomplete="off">
                <div id="search-results" class="list-group position-absolute w-100 shadow-sm d-none"
                     style="z-index:1000; max-height:320px; overflow-y:auto;"></div>
            </div>
        </div>
    </div>

    <!-- Legende + Live-Zähler -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2 d-flex flex-wrap gap-4 align-items-center">
            <span><span class="legend-dot" style="background:#ef4444;"></span>
                Nicht verkauft (<strong id="cnt-rot"><?= $grid['counts']['rot'] ?></strong>)</span>
            <span><span class="legend-dot" style="background:#eab308;"></span>
                Verkauft (<strong id="cnt-gelb"><?= $grid['counts']['gelb'] ?></strong>)</span>
            <span><span class="legend-dot" style="background:#22c55e;"></span>
                Eingecheckt (<strong id="cnt-gruen"><?= $grid['counts']['gruen'] ?></strong>)</span>
            <span class="ms-auto text-muted small">
                <i class="bi bi-broadcast text-success me-1"></i>Live · aktualisiert alle 4s
            </span>
        </div>
    </div>

    <?php if ($grid['event_typ'] === 'tischplan'): ?>

        <?php if (empty($grid['tables'])): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>Für dieses Event sind noch keine Tische angelegt.
        </div>
        <?php else: ?>

        <!-- Umschalter: kompakte Übersicht vs. freies Einrichten -->
        <div class="d-flex align-items-center gap-2 mb-3">
            <div class="btn-group btn-group-sm" role="group" aria-label="Sitzplan-Ansicht">
                <button type="button" class="btn btn-outline-dark <?= $sitzplanMode === 'auto' ? 'active' : '' ?>" data-mode-btn="auto">
                    <i class="bi bi-grid-3x3-gap me-1"></i>Automatisch
                </button>
                <button type="button" class="btn btn-outline-dark <?= $sitzplanMode === 'editor' ? 'active' : '' ?>" data-mode-btn="editor">
                    <i class="bi bi-arrows-move me-1"></i>Frei einrichten
                </button>
            </div>
        </div>

        <!-- ═══ Automatisch: kompakte Kacheln mit Klick-zum-Aufklappen ═══════════ -->
        <div id="mode-auto" style="<?= $sitzplanMode === 'editor' ? 'display:none;' : '' ?>">
            <div class="row g-3">
                <?php foreach ($grid['tables'] as $tisch):
                    $counts = ['rot' => 0, 'gelb' => 0, 'gruen' => 0];
                    foreach ($tisch['seats'] as $s) { $counts[$s['farbe']]++; }
                    $gesamt = max(1, count($tisch['seats']));
                    $pctOf = fn($n) => round($n / $gesamt * 100);
                ?>
                <div class="col-6 col-md-4 col-xl-3">
                    <div class="card h-100 shadow-sm live-table-card">
                        <div class="card-body p-2">
                            <button type="button" class="table-tile-btn" data-table-toggle="<?= $tisch['table_id'] ?>"
                                    aria-expanded="false" aria-controls="table-seats-<?= $tisch['table_id'] ?>">
                                <span class="fw-bold small">
                                    <i class="bi bi-table text-warning me-1"></i>Tisch <?= (int)$tisch['tischnummer'] ?>
                                </span>
                                <span class="occ-bar" data-table-bar="<?= $tisch['table_id'] ?>">
                                    <span style="background:#22c55e;width:<?= $pctOf($counts['gruen']) ?>%" data-bar-gruen></span>
                                    <span style="background:#eab308;width:<?= $pctOf($counts['gelb']) ?>%" data-bar-gelb></span>
                                    <span style="background:#ef4444;width:<?= $pctOf($counts['rot']) ?>%" data-bar-rot></span>
                                </span>
                                <span class="small text-muted" data-table-counts="<?= $tisch['table_id'] ?>">
                                    <?= $counts['gruen'] ?> eingecheckt &middot; <?= $counts['gelb'] ?> verkauft &middot; <?= $counts['rot'] ?> frei
                                </span>
                            </button>
                            <div id="table-seats-<?= $tisch['table_id'] ?>" class="d-flex flex-wrap mt-2" style="gap:4px;display:none;">
                                <?php foreach ($tisch['seats'] as $seat):
                                    $clickable = $seat['reservation_id'] !== null;
                                    $title = $clickable
                                        ? htmlspecialchars($seat['gast'] . ' – ' . $seat['buchungsnummer'])
                                        : 'Frei';
                                ?>
                                <span class="live-tile tile-<?= $seat['farbe'] ?>"
                                      data-seat-key="<?= $seat['seat_id'] ?>"
                                      data-table-parent="<?= $tisch['table_id'] ?>"
                                      <?= $clickable ? 'data-reservation-id="' . (int)$seat['reservation_id'] . '"' : '' ?>
                                      title="<?= $title ?>">
                                    <?= $seat['sitzplatznummer'] ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ═══ Frei einrichten: Tische per Ziehen positionieren ═════════════════ -->
        <div id="mode-editor" style="<?= $sitzplanMode === 'editor' ? '' : 'display:none;' ?>">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body d-flex flex-wrap align-items-center gap-3">
                    <form method="post" enctype="multipart/form-data" action="/pages/event_live_dashboard.php"
                          class="d-flex align-items-center gap-2 flex-wrap mb-0">
                        <?= csrfField() ?>
                        <input type="hidden" name="post_action" value="upload_floorplan">
                        <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                        <label class="small fw-semibold mb-0" for="floorplan-input">
                            <i class="bi bi-image me-1"></i>Saalfoto:
                        </label>
                        <input type="file" name="floorplan" id="floorplan-input" accept="image/*"
                               class="form-control form-control-sm" style="max-width:240px;" required>
                        <button type="submit" class="btn btn-sm btn-outline-dark">Hochladen</button>
                    </form>
                    <span class="text-muted small ms-md-auto">
                        <i class="bi bi-hand-index-thumb me-1"></i>Tisch anklicken und ziehen, um ihn zu platzieren.
                    </span>
                </div>
            </div>
            <div class="editor-canvas <?= !empty($currentEvent['tischplan_bild']) ? 'has-photo' : '' ?>"
                 id="editor-canvas"
                 <?php if (!empty($currentEvent['tischplan_bild'])): ?>
                 style="background-image:url('/uploads/<?= htmlspecialchars($currentEvent['tischplan_bild']) ?>')"
                 <?php endif; ?>>
                <?php foreach ($grid['tables'] as $tisch):
                    $belegt = count(array_filter($tisch['seats'], fn($s) => $s['farbe'] !== 'rot'));
                    $total  = count($tisch['seats']);
                ?>
                <div class="editor-chip" data-editor-table="<?= $tisch['table_id'] ?>"
                     style="left:<?= $tisch['pos_x'] ?>%;top:<?= $tisch['pos_y'] ?>%;">
                    T<?= (int)$tisch['tischnummer'] ?> <span class="opacity-75">(<?= $belegt ?>/<?= $total ?>)</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($currentEvent['tischplan_bild'])): ?>
            <p class="text-muted small mt-2">
                <i class="bi bi-info-circle me-1"></i>Noch kein Saalfoto hochgeladen – die Tische lassen sich schon
                jetzt frei anordnen, auf dem karierten Hintergrund. Ein Foto könnt ihr jederzeit nachreichen.
            </p>
            <?php endif; ?>
        </div>

        <?php endif; ?>

    <?php else: /* freie_tickets */ ?>

        <?php if ($grid['capacity']): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between small mb-1">
                    <span class="text-muted">Kapazität</span>
                    <strong id="capacity-label"><?= $grid['capacity']['verkauft'] ?> / <?= $grid['capacity']['max_gaeste'] ?></strong>
                </div>
                <div class="progress" style="height:8px;">
                    <?php $pct = $grid['capacity']['max_gaeste'] > 0
                        ? round($grid['capacity']['verkauft'] / $grid['capacity']['max_gaeste'] * 100) : 0; ?>
                    <div class="progress-bar bg-warning" id="capacity-bar" style="width:<?= $pct ?>%"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <?php if (empty($grid['tickets']) && !$grid['capacity']): ?>
                <div class="text-center text-muted py-4" id="ticket-empty-hint">
                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>Noch keine Tickets verkauft.
                </div>
                <?php endif; ?>

                <div id="ticket-grid" class="d-flex flex-wrap gap-2 mb-2">
                    <?php foreach ($grid['tickets'] as $t):
                        $label = strtoupper(substr($t['buchungsnummer'], -4));
                    ?>
                    <span class="live-tile tile-<?= $t['farbe'] ?>"
                          data-ticket-key="<?= $t['reservation_id'] ?>"
                          data-reservation-id="<?= $t['reservation_id'] ?>"
                          title="<?= htmlspecialchars($t['gast'] . ' – ' . $t['buchungsnummer']) ?>">
                        <?= htmlspecialchars($label) ?>
                    </span>
                    <?php endforeach; ?>
                </div>

                <?php if ($grid['capacity'] && $grid['capacity']['ghost_tiles'] > 0): ?>
                <div class="small text-muted mt-2 mb-1">Restkapazität:</div>
                <div id="ghost-grid" class="d-flex flex-wrap gap-2">
                    <?php for ($i = 0; $i < $grid['capacity']['ghost_tiles']; $i++): ?>
                    <span class="live-tile tile-ghost" title="Freie Kapazität">
                        <i class="bi bi-ticket-perforated"></i>
                    </span>
                    <?php endfor; ?>
                </div>
                <?php if ($grid['capacity']['ghost_extra'] > 0): ?>
                <div class="text-muted small mt-2" id="ghost-extra-hint">
                    + <?= $grid['capacity']['ghost_extra'] ?> weitere frei
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>

    <?php endif; // currentEvent ?>

</main>

<!-- ══ Ticket-QR-Modal ═══════════════════════════════════════════════════ -->
<div class="modal fade" id="ticketModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="tm-gast">&nbsp;</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="tm-qr" class="mb-3 d-flex justify-content-center"></div>
                <div id="tm-buchungsnr" class="font-monospace fw-bold fs-5 mb-2"></div>
                <div id="tm-details" class="text-muted small"></div>
            </div>
        </div>
    </div>
</div>

<?php
$jsEid  = json_encode($selectedEventId);
$jsCsrf = json_encode(generateCsrfToken());
$extraScripts = <<<'JS'
<script>
(function () {
    'use strict';
    var EVENT_ID = __EVENT_ID__;
    var CSRF     = __CSRF__;

    // ─── Sitzplan-Modus umschalten ─────────────────────────────────────────
    var modeAuto   = document.getElementById('mode-auto');
    var modeEditor = document.getElementById('mode-editor');
    document.querySelectorAll('[data-mode-btn]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var mode = btn.getAttribute('data-mode-btn');
            document.querySelectorAll('[data-mode-btn]').forEach(function (b) {
                b.classList.toggle('active', b === btn);
            });
            if (modeAuto)   modeAuto.style.display   = mode === 'auto'   ? '' : 'none';
            if (modeEditor) modeEditor.style.display = mode === 'editor' ? '' : 'none';
            var url = new URL(window.location.href);
            url.searchParams.set('mode', mode);
            window.history.replaceState({}, '', url);
        });
    });

    // ─── Tisch-Kachel aufklappen (Automatisch-Modus) ───────────────────────
    document.querySelectorAll('[data-table-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-table-toggle');
            var seats = document.getElementById('table-seats-' + id);
            var open = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', open ? 'false' : 'true');
            if (seats) seats.style.display = open ? 'none' : 'flex';
        });
    });

    // Kachel-Balken/Zähler aus dem aktuellen (auch eingeklappten) DOM-Zustand
    // neu berechnen – läuft nach jedem Poll, damit die Übersicht live bleibt,
    // ohne dass die Kachel dafür aufgeklappt sein muss.
    function recomputeTableBar(tableId) {
        var seats = document.getElementById('table-seats-' + tableId);
        var barWrap = document.querySelector('[data-table-bar="' + tableId + '"]');
        var countsEl = document.querySelector('[data-table-counts="' + tableId + '"]');
        if (!seats || !barWrap) return;
        var counts = { rot: 0, gelb: 0, gruen: 0 };
        seats.querySelectorAll('[data-seat-key]').forEach(function (el) {
            if (el.classList.contains('tile-gruen')) counts.gruen++;
            else if (el.classList.contains('tile-gelb')) counts.gelb++;
            else counts.rot++;
        });
        var total = Math.max(1, counts.rot + counts.gelb + counts.gruen);
        var gruenEl = barWrap.querySelector('[data-bar-gruen]');
        var gelbEl  = barWrap.querySelector('[data-bar-gelb]');
        var rotEl   = barWrap.querySelector('[data-bar-rot]');
        if (gruenEl) gruenEl.style.width = Math.round(counts.gruen / total * 100) + '%';
        if (gelbEl)  gelbEl.style.width  = Math.round(counts.gelb  / total * 100) + '%';
        if (rotEl)   rotEl.style.width   = Math.round(counts.rot   / total * 100) + '%';
        if (countsEl) {
            countsEl.textContent = counts.gruen + ' eingecheckt · ' + counts.gelb + ' verkauft · ' + counts.rot + ' frei';
        }
    }

    // ─── Editor: Tische per Zeigergeräte (Maus/Touch) verschieben ──────────
    var canvas = document.getElementById('editor-canvas');
    if (canvas) {
        var dragChip = null;

        canvas.querySelectorAll('.editor-chip').forEach(function (chip) {
            chip.addEventListener('pointerdown', function (e) {
                dragChip = chip;
                chip.classList.add('dragging');
                chip.setPointerCapture(e.pointerId);
            });
        });

        canvas.addEventListener('pointermove', function (e) {
            if (!dragChip) return;
            var rect = canvas.getBoundingClientRect();
            var x = ((e.clientX - rect.left) / rect.width) * 100;
            var y = ((e.clientY - rect.top) / rect.height) * 100;
            x = Math.max(0, Math.min(100, x));
            y = Math.max(0, Math.min(100, y));
            dragChip.style.left = x + '%';
            dragChip.style.top  = y + '%';
        });

        function endDrag() {
            if (!dragChip) return;
            var chip = dragChip;
            dragChip = null;
            chip.classList.remove('dragging');

            var tableId = chip.getAttribute('data-editor-table');
            var x = parseFloat(chip.style.left);
            var y = parseFloat(chip.style.top);

            var fd = new FormData();
            fd.append('table_id', tableId);
            fd.append('pos_x', x);
            fd.append('pos_y', y);
            fd.append('csrf_token', CSRF);

            fetch('/api/tischplan_position.php', {
                method: 'POST', body: fd, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            }).catch(function () { /* nächste Verschiebung versucht es erneut */ });
        }

        canvas.addEventListener('pointerup', endDrag);
        canvas.addEventListener('pointercancel', endDrag);
    }

    // ─── QR-Modal ──────────────────────────────────────────────────────────
    function openTicketModal(reservationId) {
        var modalEl = document.getElementById('ticketModal');
        if (!modalEl || !window.bootstrap) return;
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        document.getElementById('tm-qr').innerHTML =
            '<div class="spinner-border text-warning" role="status"></div>';
        document.getElementById('tm-gast').textContent = 'Lade …';
        document.getElementById('tm-buchungsnr').textContent = '';
        document.getElementById('tm-details').textContent = '';
        modal.show();

        fetch('/api/ticket_qr.php?reservation_id=' + encodeURIComponent(reservationId), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.success) {
                document.getElementById('tm-gast').textContent = 'Nicht gefunden';
                document.getElementById('tm-qr').innerHTML = '';
                document.getElementById('tm-details').textContent =
                    (res && res.message) || 'Ticket nicht gefunden.';
                return;
            }
            var d = res.data;
            document.getElementById('tm-gast').textContent = d.gast;
            document.getElementById('tm-qr').innerHTML = d.qr_html;
            document.getElementById('tm-buchungsnr').textContent = d.buchungsnummer;
            var zahlHinweis = d.zahl_status !== 'bezahlt' ? ' · Zahlung offen' : '';
            document.getElementById('tm-details').textContent =
                d.event_name + ' · ' + d.event_datum + ' · ' + d.platz + zahlHinweis;
        })
        .catch(function () {
            document.getElementById('tm-gast').textContent = 'Netzwerkfehler';
            document.getElementById('tm-qr').innerHTML = '';
        });
    }

    // Delegierter Klick auf jede Kachel/jedes Suchergebnis mit reservation-id
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-reservation-id]');
        if (!el) {
            if (!e.target.closest('#search-results') && e.target.id !== 'ticket-search') {
                var box = document.getElementById('search-results');
                if (box) box.classList.add('d-none');
            }
            return;
        }
        if (el.classList.contains('search-result-item')) {
            document.getElementById('search-results').classList.add('d-none');
            var input = document.getElementById('ticket-search');
            if (input) input.value = '';
        }
        openTicketModal(el.dataset.reservationId);
    });

    // ─── Ticket-Suche ──────────────────────────────────────────────────────
    var searchInput = document.getElementById('ticket-search');
    if (searchInput) {
        var searchTimer = null;
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var q = searchInput.value.trim();
            var box = document.getElementById('search-results');
            if (q.length < 2) { box.classList.add('d-none'); box.innerHTML = ''; return; }
            searchTimer = setTimeout(function () { runSearch(q); }, 300);
        });
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function runSearch(q) {
        fetch('/api/ticket_lookup.php?q=' + encodeURIComponent(q) + '&event_id=' + encodeURIComponent(EVENT_ID), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            var results = (res && res.data && res.data.results) || [];
            var box = document.getElementById('search-results');
            if (!results.length) {
                box.innerHTML = '<div class="list-group-item text-muted small">Keine Treffer</div>';
                box.classList.remove('d-none');
                return;
            }
            box.innerHTML = results.map(function (r) {
                var badge = r.res_status === 'eingecheckt'
                    ? '<span class="badge bg-success ms-2">Eingecheckt</span>'
                    : '<span class="badge" style="background:#eab308;color:#1a1a1a;" class="ms-2">Geplant</span>';
                return '<button type="button" class="list-group-item list-group-item-action search-result-item" ' +
                       'data-reservation-id="' + r.reservation_id + '">' +
                       '<div class="fw-semibold">' + esc(r.gast) + badge + '</div>' +
                       '<div class="small text-muted">' + esc(r.buchungsnummer) + ' · ' + esc(r.platz) + '</div>' +
                       '</button>';
            }).join('');
            box.classList.remove('d-none');
        })
        .catch(function () { /* nächster Tastendruck versucht es erneut */ });
    }

    // ─── Live-Polling (Voll-Snapshot, siehe api/event_live_grid.php) ──────
    if (!EVENT_ID) return;

    function updateTile(el, farbe, reservationId, gast, buchungsnummer) {
        var cls = 'tile-' + (farbe || 'rot');
        if (!el.classList.contains(cls)) {
            el.classList.remove('tile-rot', 'tile-gelb', 'tile-gruen');
            el.classList.add(cls);
        }
        if (reservationId) {
            el.setAttribute('data-reservation-id', reservationId);
            if (gast) el.title = gast + (buchungsnummer ? ' – ' + buchungsnummer : '');
        } else {
            el.removeAttribute('data-reservation-id');
            el.title = 'Frei';
        }
    }

    function updateCounts(counts) {
        if (!counts) return;
        ['rot', 'gelb', 'gruen'].forEach(function (k) {
            var el = document.getElementById('cnt-' + k);
            if (el) el.textContent = counts[k];
        });
    }

    function updateCapacity(capacity) {
        if (!capacity) return;
        var bar = document.getElementById('capacity-bar');
        var lbl = document.getElementById('capacity-label');
        if (bar && capacity.max_gaeste > 0) {
            bar.style.width = Math.round(capacity.verkauft / capacity.max_gaeste * 100) + '%';
        }
        if (lbl) lbl.textContent = capacity.verkauft + ' / ' + capacity.max_gaeste;

        var wrap = document.getElementById('ghost-grid');
        if (wrap) {
            var current = wrap.children.length;
            var target  = capacity.ghost_tiles;
            if (current < target) {
                for (var i = current; i < target; i++) {
                    var el = document.createElement('span');
                    el.className = 'live-tile tile-ghost';
                    el.title = 'Freie Kapazität';
                    el.innerHTML = '<i class="bi bi-ticket-perforated"></i>';
                    wrap.appendChild(el);
                }
            } else if (current > target) {
                for (var j = current; j > target; j--) wrap.removeChild(wrap.lastChild);
            }
        }
        var extraHint = document.getElementById('ghost-extra-hint');
        if (extraHint) {
            extraHint.textContent = capacity.ghost_extra > 0 ? ('+ ' + capacity.ghost_extra + ' weitere frei') : '';
        }
    }

    function applyGrid(data) {
        (data.tables || []).forEach(function (table) {
            (table.seats || []).forEach(function (seat) {
                var el = document.querySelector('[data-seat-key="' + seat.seat_id + '"]');
                if (el) updateTile(el, seat.farbe, seat.reservation_id, seat.gast, seat.buchungsnummer);
            });
            recomputeTableBar(table.table_id);
        });

        var grid = document.getElementById('ticket-grid');
        if (grid && data.tickets) {
            var seen = {};
            data.tickets.forEach(function (t) {
                var key = String(t.reservation_id);
                seen[key] = true;
                var el = grid.querySelector('[data-ticket-key="' + key + '"]');
                if (!el) {
                    el = document.createElement('span');
                    el.className = 'live-tile';
                    el.setAttribute('data-ticket-key', key);
                    grid.appendChild(el);
                    var hint = document.getElementById('ticket-empty-hint');
                    if (hint) hint.remove();
                }
                el.textContent = (t.buchungsnummer || '').slice(-4).toUpperCase();
                updateTile(el, t.farbe, t.reservation_id, t.gast, t.buchungsnummer);
            });
            grid.querySelectorAll('[data-ticket-key]').forEach(function (el) {
                if (!seen[el.getAttribute('data-ticket-key')]) el.remove();
            });
        }

        updateCounts(data.counts);
        if (data.capacity) updateCapacity(data.capacity);
    }

    function poll() {
        fetch('/api/event_live_grid.php?event_id=' + encodeURIComponent(EVENT_ID), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (res && res.success && res.data) applyGrid(res.data); })
        .catch(function () { /* nächster Versuch in 4s */ });
    }

    setInterval(poll, 4000);
})();
</script>
JS;
$extraScripts = str_replace(['__EVENT_ID__', '__CSRF__'], [$jsEid, $jsCsrf], $extraScripts);

include __DIR__ . '/../includes/footer.php';
?>

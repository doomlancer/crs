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
                        style="max-width:400px;" onchange="this.form.submit()">
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
        <div class="row g-3">
            <?php foreach ($grid['tables'] as $tisch): ?>
            <div class="col-6 col-md-4 col-xl-3">
                <div class="card h-100 shadow-sm live-table-card">
                    <div class="card-header bg-dark text-white py-2">
                        <span class="fw-bold small">
                            <i class="bi bi-table text-warning me-1"></i>Tisch <?= (int)$tisch['tischnummer'] ?>
                        </span>
                    </div>
                    <div class="card-body p-2 d-flex flex-wrap" style="gap:4px;">
                        <?php foreach ($tisch['seats'] as $seat):
                            $clickable = $seat['reservation_id'] !== null;
                            $title = $clickable
                                ? htmlspecialchars($seat['gast'] . ' – ' . $seat['buchungsnummer'])
                                : 'Frei';
                        ?>
                        <span class="live-tile tile-<?= $seat['farbe'] ?>"
                              data-seat-key="<?= $seat['seat_id'] ?>"
                              <?= $clickable ? 'data-reservation-id="' . (int)$seat['reservation_id'] . '"' : '' ?>
                              title="<?= $title ?>">
                            <?= $seat['sitzplatznummer'] ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
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
$jsEid = json_encode($selectedEventId);
$extraScripts = <<<'JS'
<script>
(function () {
    'use strict';
    var EVENT_ID = __EVENT_ID__;

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
$extraScripts = str_replace('__EVENT_ID__', $jsEid, $extraScripts);

include __DIR__ . '/../includes/footer.php';
?>

<?php
/**
 * Tischplan – vereinfachte Reservierung (Tisch + Anzahl)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Event auswählen
$eventId = (int)($_GET['event_id'] ?? 0);

// Verfügbare Events laden
$events = $pdo->query(
    "SELECT id, datum, name, status FROM events
     WHERE status != 'abgerechnet' AND datum >= CURDATE()
     ORDER BY datum ASC"
)->fetchAll();

if (!$eventId && !empty($events)) {
    $eventId = (int)$events[0]['id'];
}

// Aktuelles Event laden
$selectedEvent = null;
if ($eventId) {
    $stmt = $pdo->prepare('SELECT id, datum, name, beschreibung, status FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $selectedEvent = $stmt->fetch();
}

// Tische mit Verfügbarkeit laden
$tische = [];
if ($eventId) {
    $stmt = $pdo->prepare(
        'SELECT t.id AS table_id, t.tischnummer, t.max_plaetze,
                COUNT(s.id) AS gesamt,
                SUM(CASE WHEN s.status = "verfuegbar" THEN 1 ELSE 0 END) AS verfuegbar
         FROM `tables` t
         LEFT JOIN seats s ON s.table_id = t.id
         WHERE t.event_id = ?
         GROUP BY t.id, t.tischnummer, t.max_plaetze
         ORDER BY t.tischnummer'
    );
    $stmt->execute([$eventId]);
    $tische = $stmt->fetchAll();
}

// Eigene aktive Reservierungen für dieses Event
$meineTische = [];
if ($eventId) {
    $stmt = $pdo->prepare(
        'SELECT s.table_id, COUNT(r.id) AS anzahl
         FROM reservations r
         JOIN seats s ON r.seat_id = s.id
         JOIN `tables` t ON s.table_id = t.id
         WHERE t.event_id = ? AND r.user_id = ? AND r.status != "abgerechnet"
         GROUP BY s.table_id'
    );
    $stmt->execute([$eventId, $userId]);
    foreach ($stmt->fetchAll() as $row) {
        $meineTische[(int)$row['table_id']] = (int)$row['anzahl'];
    }
}

// Auslastung
$aul = $eventId ? getEventAuslastung($eventId) : ['prozent' => 0, 'frei' => 0, 'gesamt' => 0];

$pageTitle = 'Reservierung';
$extraHead = '<style>
    .tisch-card { transition: transform .15s, box-shadow .15s; }
    .tisch-card.hat-platz { cursor: default; }
    .tisch-card.ausgewaehlt { border-color: #8b5cf6 !important; background: #f3e8ff !important; }
    .qty-btn {
        width: 40px; height: 40px; border-radius: 50%; padding: 0; font-size: 1.2rem;
        display: flex; align-items: center; justify-content: center;
        touch-action: manipulation; -webkit-tap-highlight-color: rgba(0,0,0,.1);
    }
    .qty-display { font-size: 1.5rem; font-weight: 700; min-width: 2.2rem; text-align: center; }
    .avail-bar  { height: 6px; border-radius: 3px; background: #e5e7eb; overflow: hidden; }
    .avail-fill { height: 100%; border-radius: 3px; }
    .cart-item  { background: #8b5cf6; color: #fff; border-radius: 6px;
                  padding: 6px 10px; margin-bottom: 6px;
                  display: flex; justify-content: space-between; align-items: center;
                  font-size: .85rem; }
</style>';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="py-4">
<div class="container-fluid px-3 px-md-4">

    <?= getFlash() ?>

    <!-- Event-Auswahl -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <h4 class="mb-0 fw-bold">
                                <i class="bi bi-calendar-check text-warning me-2"></i>Reservierung
                            </h4>
                        </div>
                        <div class="col-md-5 mt-2 mt-md-0">
                            <select class="form-select"
                                    onchange="location.href='/pages/tischplan.php?event_id='+this.value">
                                <option value="">-- Event auswählen --</option>
                                <?php foreach ($events as $ev): ?>
                                <option value="<?= $ev['id'] ?>"
                                        <?= $ev['id'] == $eventId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(formatDatum($ev['datum']) . ' – ' . $ev['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($selectedEvent): ?>
                        <div class="col-md-3 text-md-end mt-2 mt-md-0">
                            <?php $bc = $aul['prozent'] >= 90 ? '#ef4444' : ($aul['prozent'] >= 70 ? '#eab308' : '#22c55e'); ?>
                            <small class="text-muted">Auslastung: <strong><?= $aul['prozent'] ?>%</strong></small>
                            <div class="avail-bar mt-1">
                                <div class="avail-fill" style="width:<?= $aul['prozent'] ?>%;background:<?= $bc ?>"></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$selectedEvent): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>Bitte wählen Sie ein Event aus.
    </div>
    <?php else: ?>

    <!-- Event-Info Banner -->
    <div class="card border-0 bg-dark text-white mb-4">
        <div class="card-body py-3">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5 class="mb-1 fw-bold"><?= htmlspecialchars($selectedEvent['name']) ?></h5>
                    <p class="mb-0 text-muted small"><?= htmlspecialchars($selectedEvent['beschreibung'] ?? '') ?></p>
                </div>
                <div class="col-md-4 text-md-end mt-2 mt-md-0">
                    <span class="badge bg-warning text-dark fs-6">
                        <i class="bi bi-calendar3 me-1"></i><?= formatDatum($selectedEvent['datum']) ?>
                    </span>
                    <div><small class="text-muted"><?= $aul['frei'] ?> von <?= $aul['gesamt'] ?> Plätzen frei</small></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Tische -->
        <div class="col-lg-8">

            <?php if (empty($tische)): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5 text-muted">
                    <i class="bi bi-exclamation-triangle display-4 d-block mb-3"></i>
                    <p>Für dieses Event wurden noch keine Tische konfiguriert.</p>
                    <?php if (hasRole('admin')): ?>
                    <a href="/pages/admin_events.php?action=tables&event_id=<?= $eventId ?>"
                       class="btn btn-warning">Tische konfigurieren</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>

            <div class="row g-3" id="tischGrid">
                <?php foreach ($tische as $tisch):
                    $tid        = (int)$tisch['table_id'];
                    $verfuegbar = (int)$tisch['verfuegbar'];
                    $gesamt     = (int)$tisch['gesamt'];
                    $meineAnz   = $meineTische[$tid] ?? 0;
                    $hatPlatz   = $verfuegbar > 0;
                    $prozent    = $gesamt > 0 ? round(($gesamt - $verfuegbar) / $gesamt * 100) : 100;
                    $fillColor  = $prozent >= 90 ? '#ef4444' : ($prozent >= 60 ? '#eab308' : '#22c55e');
                ?>
                <div class="col-6 col-md-4 col-xl-3">
                    <div class="card h-100 border-2 tisch-card <?= $hatPlatz ? 'hat-platz' : '' ?>"
                         id="tcard-<?= $tid ?>">
                        <div class="card-body text-center p-3">
                            <div class="fw-bold fs-5 mb-2">Tisch <?= $tisch['tischnummer'] ?></div>

                            <div class="avail-bar mb-2">
                                <div class="avail-fill"
                                     style="width:<?= $prozent ?>%;background:<?= $fillColor ?>"></div>
                            </div>

                            <small class="text-muted d-block mb-3">
                                <?php if ($hatPlatz): ?>
                                    <strong class="text-success"><?= $verfuegbar ?></strong>
                                    von <?= $gesamt ?> frei
                                <?php else: ?>
                                    <span class="text-danger fw-semibold">Ausgebucht</span>
                                <?php endif; ?>
                            </small>

                            <?php if ($meineAnz > 0): ?>
                            <div class="badge bg-primary mb-3">
                                <i class="bi bi-person-check me-1"></i>Meine <?= $meineAnz ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($hatPlatz): ?>
                            <div class="d-flex align-items-center justify-content-center gap-2">
                                <button type="button"
                                        class="btn btn-outline-secondary qty-btn"
                                        onclick="changeQty(<?= $tid ?>, -1, <?= $verfuegbar ?>)">
                                    &minus;
                                </button>
                                <span class="qty-display" id="qty-<?= $tid ?>">0</span>
                                <button type="button"
                                        class="btn btn-outline-primary qty-btn"
                                        onclick="changeQty(<?= $tid ?>, 1, <?= $verfuegbar ?>)">
                                    +
                                </button>
                            </div>
                            <?php else: ?>
                            <div class="text-muted small"><i class="bi bi-lock me-1"></i>Nicht verfügbar</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Bühne -->
            <div class="text-center mt-4">
                <div class="d-inline-block px-5 py-2 rounded fw-bold"
                     style="background:linear-gradient(135deg,#fbbf24,#f59e0b);">
                    <i class="bi bi-music-note-beamed me-2"></i>BÜHNE / TANZFLÄCHE<i class="bi bi-music-note-beamed ms-2"></i>
                </div>
            </div>

            <?php endif; ?>
        </div>

        <!-- Buchungs-Panel -->
        <div class="col-lg-4">
            <div style="position:sticky;top:80px;">
                <div class="card shadow border-0">
                    <div class="card-header bg-warning text-dark fw-bold">
                        <i class="bi bi-cart3 me-2"></i>Ihre Auswahl
                    </div>
                    <div class="card-body">
                        <div id="noSelection" class="text-center text-muted py-3">
                            <i class="bi bi-hand-index display-4 d-block mb-2"></i>
                            <small>Wählen Sie einen Tisch und die gewünschte Anzahl Plätze mit den
                            <strong>+</strong> / <strong>−</strong> Buttons aus.</small>
                        </div>
                        <div id="selectionPanel" style="display:none;">
                            <div id="cartItems" class="mb-3"></div>
                            <div class="border-top pt-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <small>Preis pro Platz:</small>
                                    <small class="fw-bold"><?= formatBetrag(TICKET_PREIS) ?></small>
                                </div>
                                <div class="d-flex justify-content-between mb-3">
                                    <small>Gesamt:</small>
                                    <strong class="text-warning fs-5" id="totalPrice">0,00 €</strong>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Zahlungsart:
                                        <span class="badge bg-secondary ms-1">
                                            <?= zahlungsartLabel($_SESSION['zahlungsart'] ?? 'bar') ?>
                                        </span>
                                        <a href="/pages/profil.php" class="ms-1 text-muted small">Ändern</a>
                                    </small>
                                </div>
                                <form id="reserveForm" method="POST" action="/api/reserve_seat.php">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="action" value="reserve_auto">
                                    <input type="hidden" name="selections" id="selectionsInput" value="[]">
                                    <button type="submit" class="btn btn-warning w-100 fw-bold" id="reserveBtn">
                                        <i class="bi bi-check2-circle me-2"></i>Jetzt reservieren
                                    </button>
                                </form>
                                <button type="button"
                                        class="btn btn-outline-secondary w-100 mt-2 btn-sm"
                                        onclick="clearAll()">
                                    <i class="bi bi-x-circle me-1"></i>Auswahl leeren
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Meine Reservierungen für dieses Event -->
                <?php if (!empty($meineTische)):
                    $stmtMeine = $pdo->prepare(
                        'SELECT t.tischnummer, COUNT(r.id) AS anzahl
                         FROM reservations r
                         JOIN seats s ON r.seat_id = s.id
                         JOIN `tables` t ON s.table_id = t.id
                         WHERE r.user_id = ? AND t.event_id = ? AND r.status != "abgerechnet"
                         GROUP BY t.id, t.tischnummer
                         ORDER BY t.tischnummer'
                    );
                    $stmtMeine->execute([$userId, $eventId]);
                    $meineList = $stmtMeine->fetchAll();
                ?>
                <div class="card mt-3 shadow border-0">
                    <div class="card-header bg-primary text-white fw-bold">
                        <i class="bi bi-ticket-perforated me-2"></i>Meine Buchungen
                    </div>
                    <div class="card-body p-2">
                        <?php foreach ($meineList as $mr): ?>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span>
                                <i class="bi bi-grid text-primary me-1"></i>
                                <strong>Tisch <?= $mr['tischnummer'] ?></strong>
                            </span>
                            <span class="badge bg-primary">
                                <?= $mr['anzahl'] ?> Platz<?= $mr['anzahl'] > 1 ? 'ä' : '' ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                        <div class="mt-2">
                            <a href="/pages/meine_reservierungen.php"
                               class="btn btn-outline-primary btn-sm w-100">
                                <i class="bi bi-list-check me-1"></i>Details & Stornierung
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
</main>

<?php
// PHP-Daten für JS
$tischNamesJson = json_encode(
    array_column($tische, 'tischnummer', 'table_id'),
    JSON_HEX_TAG | JSON_HEX_AMP
);
?>
<script>
var TICKET_PREIS = <?= json_encode((float)TICKET_PREIS) ?>;
var tischNames   = <?= $tischNamesJson ?>;
var selections   = {};   /* table_id (string) => anzahl */

function changeQty(tid, delta, maxAvail) {
    var cur  = selections[tid] || 0;
    var next = cur + delta;
    if (next < 0)       next = 0;
    if (next > maxAvail) next = maxAvail;
    if (next > 10)      next = 10;

    if (next === 0) {
        delete selections[tid];
    } else {
        selections[tid] = next;
    }

    var el = document.getElementById("qty-" + tid);
    if (el) el.textContent = next;

    var card = document.getElementById("tcard-" + tid);
    if (card) {
        if (next > 0) {
            card.classList.add("ausgewaehlt");
        } else {
            card.classList.remove("ausgewaehlt");
        }
    }
    updateCart();
}

function removeTable(tid) {
    var el = document.getElementById("qty-" + tid);
    if (el) el.textContent = "0";
    var card = document.getElementById("tcard-" + tid);
    if (card) card.classList.remove("ausgewaehlt");
    delete selections[tid];
    updateCart();
}

function clearAll() {
    var keys = Object.keys(selections);
    for (var i = 0; i < keys.length; i++) {
        var tid = keys[i];
        var el = document.getElementById("qty-" + tid);
        if (el) el.textContent = "0";
        var card = document.getElementById("tcard-" + tid);
        if (card) card.classList.remove("ausgewaehlt");
    }
    selections = {};
    updateCart();
}

function updateCart() {
    var panel = document.getElementById("selectionPanel");
    var noSel = document.getElementById("noSelection");
    var items = document.getElementById("cartItems");
    var total = document.getElementById("totalPrice");
    var input = document.getElementById("selectionsInput");
    if (!panel) return;

    var keys = Object.keys(selections);

    if (keys.length === 0) {
        panel.style.display = "none";
        noSel.style.display = "block";
        if (input) input.value = "[]";
        return;
    }

    panel.style.display = "block";
    noSel.style.display = "none";

    var totalSeats = 0;
    var selArr     = [];
    var html       = "";

    for (var i = 0; i < keys.length; i++) {
        var tid  = keys[i];
        var qty  = selections[tid];
        var name = tischNames[tid] !== undefined ? tischNames[tid] : tid;
        totalSeats += qty;
        selArr.push({ table_id: parseInt(tid), anzahl: qty });
        html +=
            '<div class="cart-item">' +
            '<span><i class="bi bi-grid me-1"></i>Tisch ' + name +
            ' &ndash; ' + qty + ' Platz' + (qty > 1 ? "\u00e4" : "") + '</span>' +
            '<button type="button" class="btn-close btn-close-white btn-sm ms-2" ' +
            'style="font-size:.5rem" onclick="removeTable(' + tid + ')"></button>' +
            '</div>';
    }

    if (items) items.innerHTML = html;
    if (total) total.textContent = (totalSeats * TICKET_PREIS).toFixed(2).replace(".", ",") + " \u20ac";
    if (input) input.value = JSON.stringify(selArr);
}

/* Formular-Submit Validierung */
var rf = document.getElementById("reserveForm");
if (rf) {
    rf.addEventListener("submit", function(e) {
        if (Object.keys(selections).length === 0) {
            e.preventDefault();
            alert("Bitte w\u00e4hlen Sie mindestens einen Tisch aus.");
            return;
        }
        var b = document.getElementById("reserveBtn");
        if (b) {
            b.disabled  = true;
            b.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Reservierung l\u00e4uft\u2026';
        }
    });
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>

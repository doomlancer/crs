<?php
/**
 * Tischplan – Sitzplatzauswahl und Reservierung
 * Sitzauswahl über native <label>+<input type="checkbox"> – kein JavaScript erforderlich.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Buchbare Events (nicht abgerechnet, nicht vergangen)
$events = $pdo->query(
    "SELECT id, datum, name, preis, status
     FROM events
     WHERE status != 'abgerechnet' AND datum >= CURDATE()
     ORDER BY datum ASC"
)->fetchAll();

$eventId = (int)($_GET['event_id'] ?? ($events[0]['id'] ?? 0));

$selectedEvent = null;
$tische        = [];
$meineRes      = []; // seat_id => ['reservation_id' => X, 'buchungsnummer' => Y]

if ($eventId) {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $selectedEvent = $stmt->fetch() ?: null;

    if ($selectedEvent) {
        // Tische + Sitzplätze in einer Query laden
        $stmt = $pdo->prepare(
            'SELECT t.id AS table_id, t.tischnummer, t.max_plaetze,
                    s.id AS seat_id, s.sitzplatznummer, s.status AS seat_status
             FROM tables t
             LEFT JOIN seats s ON s.table_id = t.id
             WHERE t.event_id = ?
             ORDER BY t.tischnummer ASC, s.sitzplatznummer ASC'
        );
        $stmt->execute([$eventId]);

        foreach ($stmt->fetchAll() as $row) {
            $tid = $row['table_id'];
            if (!isset($tische[$tid])) {
                $tische[$tid] = [
                    'table_id'    => $tid,
                    'tischnummer' => $row['tischnummer'],
                    'max_plaetze' => $row['max_plaetze'],
                    'sitze'       => [],
                    'frei'        => 0,
                ];
            }
            if ($row['seat_id']) {
                $tische[$tid]['sitze'][] = [
                    'seat_id'         => (int)$row['seat_id'],
                    'sitzplatznummer' => (int)$row['sitzplatznummer'],
                    'seat_status'     => $row['seat_status'],
                ];
                if ($row['seat_status'] === 'verfuegbar') {
                    $tische[$tid]['frei']++;
                }
            }
        }
        $tische = array_values($tische);

        // Eigene Reservierungen für dieses Event
        $stmt = $pdo->prepare(
            'SELECT r.id AS reservation_id, r.seat_id, r.buchungsnummer
             FROM reservations r
             WHERE r.user_id = ? AND r.event_id = ? AND r.status = "geplant"'
        );
        $stmt->execute([$userId, $eventId]);
        foreach ($stmt->fetchAll() as $res) {
            $meineRes[(int)$res['seat_id']] = [
                'reservation_id' => (int)$res['reservation_id'],
                'buchungsnummer' => $res['buchungsnummer'],
            ];
        }
    }
}

$ticketPreis  = (float)($selectedEvent['preis'] ?? TICKET_PREIS);
$zahlungsart  = $_SESSION['zahlungsart'] ?? 'bar';
$preisFormatiert = number_format($ticketPreis, 2, ',', '.');

$pageTitle = __('page_tischplan');
$bodyClass = 'bg-light';

$extraHead = '
<style>
/* ===== Sitzplatz-Chips: eigene Klassen, kein Bootstrap .btn ===== */
.seat-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 54px;
    height: 54px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 1rem;
    border: 2px solid transparent;
    -webkit-tap-highlight-color: transparent;
    user-select: none;
    -webkit-user-select: none;
    cursor: default;
    line-height: 1;
}
.seat-label {
    display: inline-block;
    margin: 3px;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    line-height: 1;
}
.seat-input {
    position: absolute;
    opacity: 0;
    width: 1px;
    height: 1px;
    margin: -1px;
    overflow: hidden;
    clip: rect(0,0,0,0);
    pointer-events: none;
}
/* Farben */
.chip-free  { background: #22c55e; color: #fff; border-color: #16a34a; }
.chip-taken { background: #e5e7eb; color: #9ca3af; border-color: #d1d5db; }
.chip-mine  { background: #3b82f6; color: #fff; border-color: #2563eb; }
/* CSS-only: ausgewählter Zustand via :checked */
.seat-input:checked + .seat-chip {
    background: #8b5cf6 !important;
    border-color: #7c3aed !important;
    color: #fff !important;
}
/* Tisch-Karte */
.table-card { border-left: 3px solid #eab308; }
/* Sticky Panel */
.sticky-panel { position: sticky; top: 76px; }
/* Legende */
.legend-dot {
    display: inline-block;
    width: 18px;
    height: 18px;
    border-radius: 4px;
    vertical-align: middle;
    margin-right: 4px;
}
</style>';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="py-4">
<div class="container-fluid px-3 px-md-4">

    <?= getFlash() ?>

    <!-- Seitenheader -->
    <div class="row align-items-center mb-3">
        <div class="col">
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-grid-3x3 text-warning me-2"></i><?= __('page_tischplan') ?>
            </h1>
        </div>
        <div class="col-auto">
            <a href="/pages/events.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i><?= __('page_events') ?>
            </a>
        </div>
    </div>

    <?php if (empty($events)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>Keine buchbaren Veranstaltungen verfügbar.
        <a href="/pages/events.php" class="alert-link"><?= __('page_events') ?></a>
    </div>

    <?php else: ?>

    <!-- Event-Selektor (bei mehreren Events) -->
    <?php if (count($events) > 1): ?>
    <div class="row mb-3">
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2">
                    <form method="GET" action="" class="d-flex align-items-center gap-2">
                        <label for="eventSelect" class="form-label mb-0 fw-semibold text-nowrap small">
                            <i class="bi bi-calendar3 text-warning me-1"></i>Event:
                        </label>
                        <select name="event_id" id="eventSelect" class="form-select form-select-sm"
                                onchange="this.form.submit()">
                            <?php foreach ($events as $ev): ?>
                            <option value="<?= (int)$ev['id'] ?>"
                                    <?= (int)$ev['id'] === $eventId ? 'selected' : '' ?>>
                                <?= formatDatum($ev['datum']) ?> – <?= htmlspecialchars($ev['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$selectedEvent): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i><?= __('msg_select_event') ?>
    </div>

    <?php else: ?>

    <!-- Haupt-Buchungsformular -->
    <form method="POST" action="/api/reserve_seat.php" id="bookForm">
        <?= csrfField() ?>
        <input type="hidden" name="event_id" value="<?= $eventId ?>">

        <div class="row g-3">

            <!-- Linke Spalte: Tisch-Raster -->
            <div class="col-lg-9">

                <!-- Event-Info Banner -->
                <div class="card border-0 shadow-sm mb-3 bg-dark text-white">
                    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-3">
                        <span class="fw-bold">
                            <i class="bi bi-calendar3 text-warning me-1"></i>
                            <?= htmlspecialchars($selectedEvent['name']) ?>
                        </span>
                        <span class="text-muted small">
                            <?= formatDatum($selectedEvent['datum']) ?>
                        </span>
                        <span class="ms-auto fw-bold text-warning">
                            <?= formatBetrag($ticketPreis) ?> / Platz
                        </span>
                    </div>
                </div>

                <!-- Legende -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body py-2 d-flex flex-wrap gap-3 align-items-center">
                        <small class="text-muted fw-semibold me-1">Legende:</small>
                        <span>
                            <span class="legend-dot" style="background:#22c55e;"></span>
                            <small>Frei</small>
                        </span>
                        <span>
                            <span class="legend-dot" style="background:#8b5cf6;"></span>
                            <small>Ausgewählt</small>
                        </span>
                        <span>
                            <span class="legend-dot" style="background:#3b82f6;"></span>
                            <small>Meine Buchung</small>
                        </span>
                        <span>
                            <span class="legend-dot" style="background:#e5e7eb; border:1px solid #d1d5db;"></span>
                            <small>Belegt</small>
                        </span>
                    </div>
                </div>

                <?php if (empty($tische)): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= __('msg_no_tables') ?>
                </div>
                <?php else: ?>

                <!-- Tisch-Raster -->
                <div class="row g-3">
                    <?php foreach ($tische as $tisch):
                        // Eigene Sitze an diesem Tisch sammeln
                        $eigeneSitzeHier = [];
                        foreach ($tisch['sitze'] as $sitz) {
                            if (isset($meineRes[$sitz['seat_id']])) {
                                $eigeneSitzeHier[] = array_merge($sitz, $meineRes[$sitz['seat_id']]);
                            }
                        }
                    ?>
                    <div class="col-6 col-md-4 col-xl-3">
                        <div class="card h-100 shadow-sm table-card">

                            <!-- Tisch-Header -->
                            <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center">
                                <span class="fw-bold small">
                                    <i class="bi bi-table text-warning me-1"></i>Tisch <?= (int)$tisch['tischnummer'] ?>
                                </span>
                                <span class="badge <?= $tisch['frei'] > 0 ? 'bg-success' : 'bg-danger' ?> small">
                                    <?= (int)$tisch['frei'] ?> frei
                                </span>
                            </div>

                            <!-- Sitzplatz-Chips -->
                            <div class="card-body p-2 d-flex flex-wrap" style="gap:3px;">
                                <?php foreach ($tisch['sitze'] as $sitz):
                                    $sid    = $sitz['seat_id'];
                                    $snr    = (int)$sitz['sitzplatznummer'];
                                    $isMine = isset($meineRes[$sid]);
                                    $isTaken = !$isMine && $sitz['seat_status'] !== 'verfuegbar';
                                ?>
                                <?php if ($isMine): ?>
                                    <span class="seat-chip chip-mine" title="Ihr Platz <?= $snr ?>">
                                        <?= $snr ?>
                                    </span>

                                <?php elseif ($isTaken): ?>
                                    <span class="seat-chip chip-taken" title="Platz <?= $snr ?> belegt">
                                        <?= $snr ?>
                                    </span>

                                <?php else: ?>
                                    <label class="seat-label" title="Platz <?= $snr ?> wählen">
                                        <input type="checkbox"
                                               name="seat_ids[]"
                                               value="<?= $sid ?>"
                                               class="seat-input"
                                               data-tisch="<?= (int)$tisch['tischnummer'] ?>"
                                               data-platz="<?= $snr ?>">
                                        <span class="seat-chip chip-free"><?= $snr ?></span>
                                    </label>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </div>

                            <!-- Stornierformulare für eigene Plätze (SEPARAT vom Buchungsformular) -->
                            <?php if (!empty($eigeneSitzeHier)): ?>
                            <div class="card-footer bg-light py-2 px-2">
                                <div class="small text-muted mb-1 fw-semibold">
                                    <i class="bi bi-person-check text-primary me-1"></i>Ihre Plätze:
                                </div>
                                <?php foreach ($eigeneSitzeHier as $es): ?>
                                <form method="POST" action="/api/cancel_seat.php" class="d-inline"
                                      onsubmit="return confirm('Platz <?= (int)$es['sitzplatznummer'] ?> an Tisch <?= (int)$tisch['tischnummer'] ?> wirklich stornieren?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="reservation_id" value="<?= (int)$es['reservation_id'] ?>">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm mb-1">
                                        <i class="bi bi-x-circle me-1"></i>Platz <?= (int)$es['sitzplatznummer'] ?> stornieren
                                    </button>
                                </form>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php endif; // tische ?>
            </div>

            <!-- Rechte Spalte: Sticky Buchungs-Panel -->
            <div class="col-lg-3">
                <div class="card shadow sticky-panel">
                    <div class="card-header bg-warning text-dark fw-bold py-2">
                        <i class="bi bi-cart3 me-2"></i><?= __('lbl_your_selection') ?>
                    </div>
                    <div class="card-body">

                        <!-- Preis / Platz -->
                        <div class="d-flex justify-content-between align-items-center mb-3 p-2 bg-light rounded">
                            <small class="text-muted"><?= __('lbl_price_per_seat') ?></small>
                            <span class="fw-bold"><?= formatBetrag($ticketPreis) ?></span>
                        </div>

                        <!-- Zahlungsart -->
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <small class="text-muted"><?= __('lbl_payment') ?></small>
                            <span class="badge bg-secondary">
                                <?php
                                echo match($zahlungsart) {
                                    'paypal'       => '<i class="bi bi-paypal me-1"></i>PayPal',
                                    'ueberweisung' => '<i class="bi bi-bank me-1"></i>Überweisung',
                                    default        => '<i class="bi bi-cash me-1"></i>Bar',
                                };
                                ?>
                            </span>
                        </div>

                        <!-- Hinweis (initial sichtbar, JS blendet ihn aus) -->
                        <div id="noSelHint" class="text-center text-muted py-3">
                            <i class="bi bi-hand-index-thumb display-6 d-block mb-2"></i>
                            <small><?= __('msg_click_seat') ?></small>
                        </div>

                        <!-- Auswahl-Zusammenfassung (JS-gesteuert) -->
                        <div id="selSummary" style="display:none;">
                            <div id="selList" class="mb-2"></div>
                            <div class="d-flex justify-content-between fw-bold border-top pt-2">
                                <span><?= __('lbl_total') ?></span>
                                <span id="selTotal" class="text-warning"></span>
                            </div>
                        </div>

                        <noscript>
                            <p class="small text-muted text-center mt-2">
                                <i class="bi bi-info-circle me-1"></i>
                                Wählen Sie Plätze aus und klicken Sie auf den Reservieren-Button.
                            </p>
                        </noscript>

                        <button type="submit" class="btn btn-warning w-100 fw-bold mt-3">
                            <i class="bi bi-check2-circle me-2"></i><?= __('btn_reserve_now') ?>
                        </button>

                        <!-- Eigene bestehende Buchungen -->
                        <?php if (!empty($meineRes)): ?>
                        <hr>
                        <div class="small">
                            <div class="fw-semibold text-muted mb-2">
                                <i class="bi bi-ticket-perforated text-primary me-1"></i>
                                <?= __('lbl_my_seats') ?> (<?= count($meineRes) ?>):
                            </div>
                            <?php foreach ($meineRes as $resInfo): ?>
                            <div class="mb-1">
                                <code class="small text-primary"><?= htmlspecialchars($resInfo['buchungsnummer']) ?></code>
                            </div>
                            <?php endforeach; ?>
                            <a href="/pages/meine_reservierungen.php" class="btn btn-outline-primary btn-sm w-100 mt-1">
                                <i class="bi bi-list-ul me-1"></i><?= __('page_my_bookings_title') ?>
                            </a>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>

        </div>
    </form>

    <?php endif; // selectedEvent ?>
    <?php endif; // events ?>

</div>
</main>

<?php
$extraScripts = '<script>
(function() {
    var PREIS = ' . json_encode($ticketPreis) . ';
    var form  = document.getElementById("bookForm");
    if (!form) return;

    function updatePanel() {
        var checked = form.querySelectorAll(".seat-input:checked");
        var hint    = document.getElementById("noSelHint");
        var summary = document.getElementById("selSummary");
        var list    = document.getElementById("selList");
        var total   = document.getElementById("selTotal");
        if (!hint || !summary || !list || !total) return;

        if (checked.length === 0) {
            hint.style.display    = "";
            summary.style.display = "none";
            return;
        }
        hint.style.display    = "none";
        summary.style.display = "";

        var html = "";
        checked.forEach(function(cb) {
            var t = cb.getAttribute("data-tisch");
            var p = cb.getAttribute("data-platz");
            html += "<div class=\"d-flex justify-content-between align-items-center mb-1\">" +
                    "<span class=\"badge bg-success\">T" + t + " · P" + p + "</span>" +
                    "<small class=\"text-muted\">' . $preisFormatiert . ' €</small>" +
                    "</div>";
        });
        list.innerHTML = html;
        total.textContent = (checked.length * PREIS).toFixed(2).replace(".", ",") + " €";
    }

    form.addEventListener("change", function(e) {
        if (e.target && e.target.classList.contains("seat-input")) {
            updatePanel();
        }
    });

    form.addEventListener("submit", function(e) {
        var checked = form.querySelectorAll(".seat-input:checked");
        if (checked.length === 0) {
            e.preventDefault();
            alert("Bitte wählen Sie mindestens einen Sitzplatz aus.");
        }
    });

    updatePanel();
})();
</script>';
include __DIR__ . '/../includes/footer.php';

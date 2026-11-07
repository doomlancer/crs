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
    "SELECT id, datum, name, status
     FROM events
     WHERE status != 'abgerechnet' AND datum >= CURDATE()
     ORDER BY datum ASC"
)->fetchAll();

// Ohne Auswahl das nächste buchbare (aktive) Event öffnen – nicht ein Event
// "In Planung", bei dem jede Auswahl beim Abschicken abgelehnt würde.
$ersteAktive = array_values(array_filter($events, fn($e) => $e['status'] === 'aktiv'));
$eventId = (int)($_GET['event_id'] ?? ($ersteAktive[0]['id'] ?? ($events[0]['id'] ?? 0)));

$selectedEvent = null;
$tische        = [];
$meineRes      = []; // seat_id => ['reservation_id' => X, 'buchungsnummer' => Y]

if ($eventId) {
    $stmt = $pdo->prepare('SELECT id, datum, name, beschreibung, status FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $selectedEvent = $stmt->fetch() ?: null;

    if ($selectedEvent) {
        // event_typ laden (graceful fallback wenn Migration noch nicht gelaufen)
        try {
            $stmtTyp = $pdo->prepare('SELECT event_typ FROM events WHERE id = ?');
            $stmtTyp->execute([$eventId]);
            $selectedEvent['event_typ'] = $stmtTyp->fetchColumn() ?: 'tischplan';
        } catch (PDOException $e) {
            $selectedEvent['event_typ'] = 'tischplan';
        }

        // Freie-Ticket-Events bekommen eine eigene Buchungsseite
        if ($selectedEvent['event_typ'] === 'freie_tickets') {
            include __DIR__ . '/ticket_freiverkauf.php';
            exit;
        }
    }

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

try {
    $stmtP = $pdo->prepare('SELECT COALESCE(preis, ?) FROM events WHERE id = ?');
    $stmtP->execute([(float)TICKET_PREIS, $eventId]);
    $ticketPreis = (float)($stmtP->fetchColumn() ?: TICKET_PREIS);
} catch (PDOException $e) {
    $ticketPreis = (float)TICKET_PREIS;
}
// Zahlungsart aus dem Konto (die Session kennt sie nicht – vorher stand hier
// für jeden Gast "Bar", auch bei Überweisung)
$zahlungsart  = currentIdentity()['zahlungsart'] ?? 'bar';
$istAdmin     = hasRole('admin');
$buchbar      = $selectedEvent && ($selectedEvent['status'] === 'aktiv' || $istAdmin);
$freieGesamt  = array_sum(array_column($tische, 'frei'));
$ausgebucht   = $selectedEvent && !empty($tische) && $freieGesamt === 0;
$maxProBuchung = 10;
$aufWarteliste = false;
if ($selectedEvent && $ausgebucht) {
    $stmtW = $pdo->prepare('SELECT 1 FROM waitlist WHERE user_id = ? AND event_id = ?');
    $stmtW->execute([$userId, $eventId]);
    $aufWarteliste = (bool)$stmtW->fetchColumn();
}
// Volle Tische nur kompakt am Ende auflisten – auf dem Handy waren sie sonst
// Bildschirmseiten voller grauer Plätze vor den freien.
$tischeFrei = array_values(array_filter($tische, fn($t) => $t['frei'] > 0 || !empty(array_intersect(array_column($t['sitze'], 'seat_id'), array_keys($meineRes)))));
$tischeVoll = array_values(array_filter($tische, fn($t) => !in_array($t, $tischeFrei, true)));
$preisFormatiert = number_format($ticketPreis, 2, ',', '.');

$pageTitle = __('seating.title');
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
    display: inline-flex;
    margin: 0;
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
/* Fixierte Auswahl-Leiste auf dem Handy (unter lg) */
.mobile-book-bar {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 1030;
    background: #1a1a1a; color: #fff;
    padding: 10px max(16px, env(safe-area-inset-left, 0px)) calc(10px + env(safe-area-inset-bottom, 0px));
    box-shadow: 0 -4px 16px rgba(0,0,0,.25);
}
@media (max-width: 991.98px) { main.has-book-bar { padding-bottom: 84px; } }
.seat-grid { display: flex; flex-wrap: wrap; gap: 6px; }
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

<main class="py-4<?= !empty($buchbar) ? ' has-book-bar' : '' ?>">
<div class="container-fluid px-3 px-md-4">

    <?= getFlash() ?>

    <!-- Seitenheader -->
    <div class="row align-items-center mb-3">
        <div class="col">
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-grid-3x3 text-warning me-2"></i><?= __('seating.title') ?>
            </h1>
        </div>
        <div class="col-auto">
            <a href="/pages/events.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i><?= __('nav.events') ?>
            </a>
        </div>
    </div>

    <?php if (empty($events)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i><?= __('seating.no_events') ?>
        <a href="/pages/events.php" class="alert-link"><?= __('nav.events') ?></a>
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
                            <i class="bi bi-calendar3 text-warning me-1"></i><?= __('seating.event_label') ?>
                        </label>
                        <select name="event_id" id="eventSelect" class="form-select form-select-sm"
                                data-autosubmit="location">
                            <?php foreach ($events as $ev): ?>
                            <option value="<?= (int)$ev['id'] ?>"
                                    <?= (int)$ev['id'] === $eventId ? 'selected' : '' ?>>
                                <?= formatDatum($ev['datum']) ?> – <?= htmlspecialchars($ev['name']) ?><?= $ev['status'] !== 'aktiv' ? ' (' . htmlspecialchars(__('events.presale_soon')) . ')' : '' ?>
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
        <i class="bi bi-exclamation-triangle me-2"></i><?= __('seating.select_event') ?>
    </div>

    <?php else: ?>

    <?php if (!$buchbar): ?>
    <div class="alert alert-info d-flex align-items-start gap-2">
        <i class="bi bi-hourglass-split fs-5"></i>
        <div><strong><?= htmlspecialchars($selectedEvent['name']) ?>:</strong> <?= __('seating.presale_soon_text') ?>
            <div class="mt-2"><a href="/pages/events.php" class="alert-link"><?= __('nav.events') ?></a></div></div>
    </div>
    <?php elseif ($ausgebucht): ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1"><i class="bi bi-x-circle me-1"></i><?= __('seating.sold_out_text') ?></div>
        <?php if ($aufWarteliste): ?>
        <a href="/pages/meine_reservierungen.php" class="btn btn-outline-secondary btn-sm"><?= __('events.waitlist_on') ?></a>
        <?php else: ?>
        <form method="POST" action="/api/join_waitlist.php" class="m-0">
            <?= csrfField() ?>
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <button type="submit" class="btn btn-warning btn-sm fw-semibold"><i class="bi bi-hourglass-split me-1"></i><?= __('events.waitlist_join') ?></button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($buchbar): ?>
    <!-- Haupt-Buchungsformular -->
    <form method="POST" action="/api/reserve_seat.php" id="bookForm" data-max="<?= $maxProBuchung ?>">
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
                        <span class="text-white-50 small">
                            <?= formatDatum($selectedEvent['datum']) ?>
                        </span>
                        <span class="ms-auto fw-bold text-warning">
                            <?= formatBetrag($ticketPreis) ?><?= __('seating.per_seat_suffix') ?>
                        </span>
                    </div>
                </div>

                <!-- Legende -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body py-2 d-flex flex-wrap gap-3 align-items-center">
                        <small class="text-muted fw-semibold me-1"><?= __('seating.legend_label') ?></small>
                        <span>
                            <span class="legend-dot" style="background:#22c55e;"></span>
                            <small><?= __('seating.legend_free') ?></small>
                        </span>
                        <span>
                            <span class="legend-dot" style="background:#8b5cf6;"></span>
                            <small><?= __('seating.legend_selected') ?></small>
                        </span>
                        <span>
                            <span class="legend-dot" style="background:#3b82f6;"></span>
                            <small><?= __('seating.legend_mine') ?></small>
                        </span>
                        <span>
                            <span class="legend-dot" style="background:#e5e7eb; border:1px solid #d1d5db;"></span>
                            <small><?= __('seating.legend_occupied') ?></small>
                        </span>
                    </div>
                </div>

                <?php if (empty($tische)): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= __('seating.no_tables') ?>
                </div>
                <?php else: ?>

                <!-- Tisch-Raster -->
                <div class="row g-3">
                    <?php foreach ($tischeFrei as $tisch):
                        // Eigene Sitze an diesem Tisch sammeln
                        $eigeneSitzeHier = [];
                        foreach ($tisch['sitze'] as $sitz) {
                            if (isset($meineRes[$sitz['seat_id']])) {
                                $eigeneSitzeHier[] = array_merge($sitz, $meineRes[$sitz['seat_id']]);
                            }
                        }
                    ?>
                    <div class="col-12 col-sm-6 col-xl-4">
                        <div class="card h-100 shadow-sm table-card">

                            <!-- Tisch-Header -->
                            <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center">
                                <span class="fw-bold small">
                                    <i class="bi bi-table text-warning me-1"></i><?= __('res.table') ?> <?= (int)$tisch['tischnummer'] ?>
                                </span>
                                <span class="badge <?= $tisch['frei'] > 0 ? 'bg-success' : 'bg-danger' ?> small">
                                    <?= htmlspecialchars(sprintf(__('seating.table_free'), (int)$tisch['frei'])) ?>
                                </span>
                            </div>

                            <!-- Sitzplatz-Chips -->
                            <div class="card-body p-2 seat-grid">
                                <?php foreach ($tisch['sitze'] as $sitz):
                                    $sid    = $sitz['seat_id'];
                                    $snr    = (int)$sitz['sitzplatznummer'];
                                    $isMine = isset($meineRes[$sid]);
                                    $isTaken = !$isMine && $sitz['seat_status'] !== 'verfuegbar';
                                ?>
                                <?php if ($isMine): ?>
                                    <span class="seat-chip chip-mine" title="<?= htmlspecialchars(sprintf(__('seating.your_seat_title'), $snr), ENT_QUOTES) ?>">
                                        <?= $snr ?>
                                    </span>

                                <?php elseif ($isTaken): ?>
                                    <span class="seat-chip chip-taken" title="<?= htmlspecialchars(sprintf(__('seating.seat_taken_title'), $snr), ENT_QUOTES) ?>">
                                        <?= $snr ?>
                                    </span>

                                <?php else: ?>
                                    <label class="seat-label" title="<?= htmlspecialchars(sprintf(__('seating.seat_select_title'), $snr), ENT_QUOTES) ?>">
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
                                    <i class="bi bi-person-check text-primary me-1"></i><?= __('seating.your_seats') ?>
                                </div>
                                <?php foreach ($eigeneSitzeHier as $es): ?>
                                <form method="POST" action="/api/cancel_seat.php" class="d-inline"
                                      data-confirm="<?= htmlspecialchars(sprintf(__('seating.cancel_seat'), (int)$es['sitzplatznummer']) . ' ' . sprintf(__('seating.cancel_table_suffix'), (int)$tisch['tischnummer']), ENT_QUOTES) ?>">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="reservation_id" value="<?= (int)$es['reservation_id'] ?>">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm mb-1">
                                        <i class="bi bi-x-circle me-1"></i><?= htmlspecialchars(sprintf(__('seating.cancel_seat'), (int)$es['sitzplatznummer'])) ?>
                                    </button>
                                </form>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($tischeVoll)): ?>
                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-body py-2 small text-muted">
                        <i class="bi bi-x-circle me-1"></i><?= htmlspecialchars(sprintf(__('seating.full_tables'), count($tischeVoll))) ?>:
                        <?= htmlspecialchars(implode(', ', array_map(fn($t) => __('res.table') . ' ' . (int)$t['tischnummer'], $tischeVoll))) ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php endif; // tische ?>
            </div>

            <!-- Rechte Spalte: Sticky Buchungs-Panel -->
            <div class="col-lg-3">
                <div class="card shadow sticky-panel">
                    <div class="card-header bg-warning text-dark fw-bold py-2">
                        <i class="bi bi-cart3 me-2"></i><?= __('seating.your_selection') ?>
                    </div>
                    <div class="card-body">

                        <!-- Preis / Platz -->
                        <div class="d-flex justify-content-between align-items-center mb-3 p-2 bg-light rounded">
                            <small class="text-muted"><?= __('seating.price_per_seat') ?></small>
                            <span class="fw-bold"><?= formatBetrag($ticketPreis) ?></span>
                        </div>

                        <!-- Zahlungsart -->
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <small class="text-muted"><?= __('seating.payment') ?></small>
                            <span class="badge bg-secondary">
                                <?php
                                echo match($zahlungsart) {
                                    'ueberweisung' => '<i class="bi bi-bank me-1"></i>' . htmlspecialchars(__('payment.ueberweisung')),
                                    default        => '<i class="bi bi-cash me-1"></i>' . htmlspecialchars(__('payment.bar')),
                                };
                                ?>
                            </span>
                        </div>

                        <p class="small text-muted mb-2"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars(sprintf(__('seating.limit_hint'), $maxProBuchung)) ?></p>
                        <div id="limitMsg" class="alert alert-warning py-1 px-2 small" hidden><?= htmlspecialchars(sprintf(__('seating.limit_reached'), $maxProBuchung)) ?></div>

                        <!-- Hinweis (initial sichtbar, JS blendet ihn aus) -->
                        <div id="noSelHint" class="text-center text-muted py-3">
                            <i class="bi bi-hand-index-thumb display-6 d-block mb-2"></i>
                            <small><?= __('seating.hint') ?></small>
                        </div>

                        <!-- Auswahl-Zusammenfassung (JS-gesteuert) -->
                        <div id="selSummary" style="display:none;">
                            <div id="selList" class="mb-2"></div>
                            <div class="d-flex justify-content-between fw-bold border-top pt-2">
                                <span><?= __('res.total') ?></span>
                                <span id="selTotal" class="text-warning"></span>
                            </div>
                        </div>

                        <noscript>
                            <p class="small text-muted text-center mt-2">
                                <i class="bi bi-info-circle me-1"></i>
                                <?= __('seating.noscript_hint') ?>
                            </p>
                        </noscript>

                        <button type="submit" class="btn btn-warning w-100 fw-bold mt-3">
                            <i class="bi bi-check2-circle me-2"></i><?= __('seating.reserve') ?>
                        </button>

                        <!-- Eigene bestehende Buchungen -->
                        <?php if (!empty($meineRes)): ?>
                        <hr>
                        <div class="small">
                            <div class="fw-semibold text-muted mb-2">
                                <i class="bi bi-ticket-perforated text-primary me-1"></i>
                                <?= __('seating.my_seats') ?> (<?= count($meineRes) ?>):
                            </div>
                            <?php foreach ($meineRes as $resInfo): ?>
                            <div class="mb-1">
                                <code class="small text-primary"><?= htmlspecialchars($resInfo['buchungsnummer']) ?></code>
                            </div>
                            <?php endforeach; ?>
                            <a href="/pages/meine_reservierungen.php" class="btn btn-outline-primary btn-sm w-100 mt-1">
                                <i class="bi bi-list-ul me-1"></i><?= __('res.title') ?>
                            </a>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>

        </div>
    </form>

    <!-- Fixierte Leiste auf dem Handy: Auswahl + Reservieren immer erreichbar -->
    <div class="mobile-book-bar d-lg-none" id="mobileBar" hidden>
        <div class="d-flex align-items-center gap-3">
            <div class="flex-grow-1">
                <div class="fw-bold" id="mbCount"></div>
                <div class="small text-white-50" id="mbTotal"></div>
            </div>
            <button type="submit" form="bookForm" class="btn btn-warning fw-bold px-4">
                <i class="bi bi-check2-circle me-1"></i><?= __('seating.reserve') ?>
            </button>
        </div>
    </div>
    <?php endif; // buchbar ?>

    <?php endif; // selectedEvent ?>
    <?php endif; // events ?>

</div>
</main>

<?php
$extraScripts = '<script>
(function() {
    var PREIS = ' . json_encode($ticketPreis) . ';
    var MSG_SELECT_SEAT = ' . json_encode(__('seating.js_select_seat_alert')) . ';
    var LBL_COUNT = ' . json_encode(__('seating.sticky_selected')) . ';
    var form  = document.getElementById("bookForm");
    if (!form) return;
    var MAX = parseInt(form.getAttribute("data-max") || "10", 10);

    function euro(v) { return v.toFixed(2).replace(".", ",") + " €"; }

    function updatePanel() {
        var checked = form.querySelectorAll(".seat-input:checked");
        var hint    = document.getElementById("noSelHint");
        var summary = document.getElementById("selSummary");
        var list    = document.getElementById("selList");
        var total   = document.getElementById("selTotal");
        var bar     = document.getElementById("mobileBar");

        if (bar) {
            bar.hidden = checked.length === 0;
            document.getElementById("mbCount").textContent = LBL_COUNT.replace("%s", checked.length);
            document.getElementById("mbTotal").textContent = euro(checked.length * PREIS);
        }
        if (!hint || !summary || !list || !total) return;

        if (checked.length === 0) {
            hint.style.display    = "";
            summary.style.display = "none";
            return;
        }
        hint.style.display    = "none";
        summary.style.display = "";

        list.innerHTML = "";
        checked.forEach(function(cb) {
            var row = document.createElement("div");
            row.className = "d-flex justify-content-between align-items-center mb-1";
            var badge = document.createElement("span");
            badge.className = "badge bg-success";
            badge.textContent = "T" + cb.getAttribute("data-tisch") + " · P" + cb.getAttribute("data-platz");
            var price = document.createElement("small");
            price.className = "text-muted";
            price.textContent = euro(PREIS);
            row.appendChild(badge); row.appendChild(price);
            list.appendChild(row);
        });
        total.textContent = euro(checked.length * PREIS);
    }

    form.addEventListener("change", function(e) {
        if (!e.target || !e.target.classList.contains("seat-input")) return;
        var anzahl = form.querySelectorAll(".seat-input:checked").length;
        var msg = document.getElementById("limitMsg");
        // Limit sofort durchsetzen statt erst nach dem Abschicken abzulehnen
        if (e.target.checked && anzahl > MAX) {
            e.target.checked = false;
            if (msg) { msg.hidden = false; }
        } else if (msg) {
            msg.hidden = true;
        }
        updatePanel();
    });

    form.addEventListener("submit", function(e) {
        var checked = form.querySelectorAll(".seat-input:checked");
        if (checked.length === 0) {
            e.preventDefault();
            alert(MSG_SELECT_SEAT);
        }
    });

    updatePanel();
})();
</script>';
include __DIR__ . '/../includes/footer.php';

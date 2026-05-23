<?php
/**
 * Grafischer Tischplan mit Echtzeit-Reservierung
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];

// Event auswählen
$eventId = (int)($_GET['event_id'] ?? 0);

// Verfügbare Events laden
$stmtEvents = $pdo->query(
    "SELECT id, datum, name, status FROM events
     WHERE status != 'abgerechnet' AND datum >= CURDATE()
     ORDER BY datum ASC"
);
$events = $stmtEvents->fetchAll();

// Falls kein Event gewählt, erstes nehmen
if (!$eventId && !empty($events)) {
    $eventId = (int)$events[0]['id'];
}

// Aktuelles Event laden (tischplan_bild nur wenn Spalte vorhanden)
$selectedEvent = null;
if ($eventId) {
    try {
        $stmt = $pdo->prepare('SELECT id, datum, name, beschreibung, status, tischplan_bild FROM events WHERE id = ?');
        $stmt->execute([$eventId]);
        $selectedEvent = $stmt->fetch();
    } catch (PDOException $e) {
        // Spalte tischplan_bild existiert noch nicht (Migration ausstehend)
        $stmt = $pdo->prepare('SELECT id, datum, name, beschreibung, status FROM events WHERE id = ?');
        $stmt->execute([$eventId]);
        $selectedEvent = $stmt->fetch();
        if ($selectedEvent) $selectedEvent['tischplan_bild'] = null;
    }
}

// Bereits reservierte Sitze des Benutzers für dieses Event
$meineReservierungen = [];
if ($eventId) {
    $stmt = $pdo->prepare(
        'SELECT r.seat_id FROM reservations r WHERE r.user_id = ? AND r.event_id = ? AND r.status != "abgerechnet"'
    );
    $stmt->execute([$userId, $eventId]);
    $meineReservierungen = array_column($stmt->fetchAll(), 'seat_id');
}

// Visueller Modus: Bild + Positionen vorhanden?
$visualMode = false;
if ($selectedEvent && !empty($selectedEvent['tischplan_bild'])) {
    try {
        $stmtCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM `tables` WHERE event_id = ? AND pos_x IS NOT NULL'
        );
        $stmtCheck->execute([$eventId]);
        $visualMode = $stmtCheck->fetchColumn() > 0;
    } catch (PDOException $e) {
        $visualMode = false; // Migration noch nicht eingespielt
    }
}

$pageTitle = 'Tischplan';
$extraHead = '<style>
    .tischplan-container { background: #1a1a2e; border-radius: 12px; padding: 20px; min-height: 400px; overflow-x: auto; }
    .table-row { display: flex; gap: 30px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
    .tisch-block { background: #16213e; border-radius: 10px; padding: 12px; min-width: 120px; border: 2px solid #0f3460; }
    .tisch-label { color: #94a3b8; font-size: 0.75rem; text-align: center; margin-bottom: 8px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
    .seats-grid { display: flex; flex-wrap: wrap; gap: 6px; justify-content: center; }
    .seat-btn { width: 38px; height: 38px; border-radius: 8px; border: 2px solid transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700; transition: all 0.2s; position: relative; }
    .seat-btn.verfuegbar { background: #22c55e; border-color: #16a34a; color: #fff; }
    .seat-btn.verfuegbar:hover { background: #16a34a; transform: scale(1.15); box-shadow: 0 0 12px rgba(34,197,94,0.5); }
    .seat-btn.reserviert { background: #eab308; border-color: #ca8a04; color: #000; cursor: not-allowed; }
    .seat-btn.besetzt { background: #ef4444; border-color: #dc2626; color: #fff; cursor: not-allowed; }
    .seat-btn.mein-platz { background: #3b82f6; border-color: #2563eb; color: #fff; cursor: pointer; }
    .seat-btn.mein-platz:hover { background: #2563eb; transform: scale(1.15); }
    .seat-btn.ausgewaehlt { background: #8b5cf6; border-color: #7c3aed; color: #fff; box-shadow: 0 0 15px rgba(139,92,246,0.6); transform: scale(1.1); }
    .legend-dot { width: 16px; height: 16px; border-radius: 4px; display: inline-block; }
    .reservation-panel { position: sticky; top: 80px; }
    .selected-seats-list { max-height: 200px; overflow-y: auto; }
</style>';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="py-4">
    <div class="container-fluid px-4">

        <?= getFlash() ?>

        <!-- Event-Auswahl -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <h4 class="mb-0 fw-bold">
                                    <i class="bi bi-grid-3x3 text-warning me-2"></i>Tischplan
                                </h4>
                            </div>
                            <div class="col-md-5">
                                <select class="form-select" id="eventSelector" onchange="location.href='/pages/tischplan.php?event_id='+this.value">
                                    <option value="">-- Event auswählen --</option>
                                    <?php foreach ($events as $ev): ?>
                                    <option value="<?= $ev['id'] ?>" <?= $ev['id'] == $eventId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(formatDatum($ev['datum']) . ' – ' . $ev['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($selectedEvent): ?>
                            <div class="col-md-3 text-md-end mt-2 mt-md-0">
                                <?php
                                $aul = getEventAuslastung($eventId);
                                $barColor = $aul['prozent'] >= 90 ? 'bg-danger' : ($aul['prozent'] >= 70 ? 'bg-warning' : 'bg-success');
                                ?>
                                <small class="text-muted">Auslastung: <strong><?= $aul['prozent'] ?>%</strong></small>
                                <div class="progress mt-1" style="height:6px;">
                                    <div class="progress-bar <?= $barColor ?>" style="width:<?= $aul['prozent'] ?>%"></div>
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
            <i class="bi bi-info-circle me-2"></i>Bitte wählen Sie ein Event aus, um den Tischplan anzuzeigen.
        </div>
        <?php else: ?>

        <!-- Event-Info -->
        <div class="row mb-3">
            <div class="col-12">
                <div class="card border-0 bg-dark text-white">
                    <div class="card-body py-3">
                        <div class="row">
                            <div class="col-md-8">
                                <h5 class="mb-1 fw-bold"><?= htmlspecialchars($selectedEvent['name']) ?></h5>
                                <p class="mb-0 text-muted small"><?= htmlspecialchars($selectedEvent['beschreibung'] ?? '') ?></p>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <span class="badge bg-warning text-dark fs-6">
                                    <i class="bi bi-calendar3 me-1"></i><?= formatDatum($selectedEvent['datum']) ?>
                                </span>
                                <div class="mt-1">
                                    <small class="text-muted"><?= $aul['frei'] ?> von <?= $aul['gesamt'] ?> Plätzen frei</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Tischplan -->
            <div class="col-lg-9">
                <!-- Legende -->
                <div class="d-flex gap-3 mb-3 flex-wrap">
                    <span class="d-flex align-items-center gap-2 text-sm">
                        <span class="legend-dot" style="background:#22c55e;"></span>
                        <small>Verfügbar</small>
                    </span>
                    <span class="d-flex align-items-center gap-2">
                        <span class="legend-dot" style="background:#ef4444;"></span>
                        <small>Besetzt</small>
                    </span>
                    <span class="d-flex align-items-center gap-2">
                        <span class="legend-dot" style="background:#eab308;"></span>
                        <small>Reserviert</small>
                    </span>
                    <span class="d-flex align-items-center gap-2">
                        <span class="legend-dot" style="background:#3b82f6;"></span>
                        <small>Meine Reservierung</small>
                    </span>
                    <span class="d-flex align-items-center gap-2">
                        <span class="legend-dot" style="background:#8b5cf6;"></span>
                        <small>Ausgewählt</small>
                    </span>
                </div>

                <?php
                // Alle Tische + Sitze laden (für beide Modi)
                $stmtTische = $pdo->prepare(
                    'SELECT t.id AS table_id, t.tischnummer, t.max_plaetze, t.pos_x, t.pos_y
                     FROM `tables` t WHERE t.event_id = ? ORDER BY t.tischnummer'
                );
                $stmtTische->execute([$eventId]);
                $tische = $stmtTische->fetchAll();

                $stmtSitze = $pdo->prepare(
                    'SELECT s.id, s.table_id, s.sitzplatznummer, s.status
                     FROM seats s
                     INNER JOIN `tables` t ON s.table_id = t.id
                     WHERE t.event_id = ?
                     ORDER BY t.tischnummer, s.sitzplatznummer'
                );
                $stmtSitze->execute([$eventId]);
                $alleSitze = $stmtSitze->fetchAll();

                $sitzePorTisch = [];
                foreach ($alleSitze as $sitz) {
                    $sitzePorTisch[$sitz['table_id']][] = $sitz;
                }
                ?>

                <?php if (empty($tische)): ?>
                <div class="tischplan-container">
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-exclamation-triangle display-4 d-block mb-3"></i>
                        <p>Für dieses Event wurden noch keine Tische konfiguriert.</p>
                        <?php if (hasRole('admin')): ?>
                        <a href="/pages/admin_events.php?action=tables&event_id=<?= $eventId ?>" class="btn btn-warning">Tische konfigurieren</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php elseif ($visualMode): ?>
                <!-- ══ VISUELLER MODUS: Bild mit positionierten Tischen ══ -->
                <div style="position:relative; display:inline-block; max-width:100%; width:100%;" id="tischplan">
                    <img src="/api/tischplan_image.php?event_id=<?= $eventId ?>"
                         alt="Tischplan" id="tischplanImg"
                         style="display:block; max-width:100%; height:auto; border-radius:8px;">
                    <?php foreach ($tische as $tisch):
                        if ($tisch['pos_x'] === null) continue;
                        $sitzeListe = $sitzePorTisch[$tisch['table_id']] ?? [];
                    ?>
                    <div class="visual-tisch-block"
                         style="position:absolute;
                                left:<?= $tisch['pos_x'] ?>%;
                                top:<?= $tisch['pos_y'] ?>%;
                                transform:translate(-50%,-50%);
                                z-index:10;">
                        <div class="tisch-label" style="color:#fff; background:rgba(0,0,0,0.55); border-radius:4px 4px 0 0; padding:2px 6px; font-size:0.65rem; text-align:center; font-weight:700;">
                            T<?= $tisch['tischnummer'] ?>
                        </div>
                        <div class="seats-grid" style="background:rgba(22,33,62,0.8); border-radius:0 0 6px 6px; padding:4px;">
                            <?php foreach ($sitzeListe as $sitz):
                                $meinsFlag   = in_array($sitz['id'], $meineReservierungen);
                                $statusClass = $meinsFlag ? 'mein-platz' : $sitz['status'];
                                $clickable   = ($statusClass === 'verfuegbar' || $statusClass === 'mein-platz');
                                $title       = "Tisch {$tisch['tischnummer']}, Platz {$sitz['sitzplatznummer']}";
                                if ($statusClass === 'mein-platz')       $title .= ' (Meine Reservierung)';
                                elseif ($sitz['status'] === 'reserviert') $title .= ' (Reserviert)';
                                elseif ($sitz['status'] === 'besetzt')    $title .= ' (Besetzt)';
                            ?>
                            <button class="seat-btn <?= $statusClass ?>"
                                    data-seat-id="<?= $sitz['id'] ?>"
                                    data-table-id="<?= $tisch['table_id'] ?>"
                                    data-tischnummer="<?= $tisch['tischnummer'] ?>"
                                    data-sitzplatznummer="<?= $sitz['sitzplatznummer'] ?>"
                                    data-status="<?= $sitz['status'] ?>"
                                    data-mein-platz="<?= $meinsFlag ? '1' : '0' ?>"
                                    <?= !$clickable ? 'disabled' : '' ?>
                                    title="<?= htmlspecialchars($title) ?>"
                                    onclick="<?= $clickable ? 'toggleSeat(this)' : '' ?>"
                                    type="button"
                                    style="width:28px;height:28px;font-size:0.6rem;">
                                <?= $sitz['sitzplatznummer'] ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php else: ?>
                <!-- ══ GRID-MODUS: Klassische Rasteransicht ══ -->
                <div class="tischplan-container" id="tischplan">
                    <?php
                    $tischChunks = array_chunk($tische, 4);
                    foreach ($tischChunks as $tischRow):
                    ?>
                    <div class="table-row">
                        <?php foreach ($tischRow as $tisch):
                            $sitzeListe = $sitzePorTisch[$tisch['table_id']] ?? [];
                        ?>
                        <div class="tisch-block">
                            <div class="tisch-label">
                                <i class="bi bi-grid"></i> Tisch <?= $tisch['tischnummer'] ?>
                                <br><small class="opacity-50"><?= count($sitzeListe) ?> Plätze</small>
                            </div>
                            <div class="seats-grid">
                                <?php foreach ($sitzeListe as $sitz):
                                    $meinsFlag   = in_array($sitz['id'], $meineReservierungen);
                                    $statusClass = $meinsFlag ? 'mein-platz' : $sitz['status'];
                                    $clickable   = ($statusClass === 'verfuegbar' || $statusClass === 'mein-platz');
                                    $title       = "Tisch {$tisch['tischnummer']}, Platz {$sitz['sitzplatznummer']}";
                                    if ($statusClass === 'mein-platz')       $title .= ' (Meine Reservierung)';
                                    elseif ($sitz['status'] === 'reserviert') $title .= ' (Reserviert)';
                                    elseif ($sitz['status'] === 'besetzt')    $title .= ' (Besetzt)';
                                ?>
                                <button class="seat-btn <?= $statusClass ?>"
                                        data-seat-id="<?= $sitz['id'] ?>"
                                        data-table-id="<?= $tisch['table_id'] ?>"
                                        data-tischnummer="<?= $tisch['tischnummer'] ?>"
                                        data-sitzplatznummer="<?= $sitz['sitzplatznummer'] ?>"
                                        data-status="<?= $sitz['status'] ?>"
                                        data-mein-platz="<?= $meinsFlag ? '1' : '0' ?>"
                                        <?= !$clickable ? 'disabled' : '' ?>
                                        title="<?= htmlspecialchars($title) ?>"
                                        onclick="<?= $clickable ? 'toggleSeat(this)' : '' ?>"
                                        type="button">
                                    <?= $sitz['sitzplatznummer'] ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Bühne / Dekoration -->
                <div class="text-center mt-3">
                    <div style="background: linear-gradient(135deg, #fbbf24, #f59e0b); border-radius: 8px; padding: 10px 40px; display: inline-block; color: #000; font-weight: 700; font-size: 0.85rem;">
                        <i class="bi bi-music-note-beamed me-2"></i>BÜHNE / TANZFLÄCHE<i class="bi bi-music-note-beamed ms-2"></i>
                    </div>
                </div>
            </div>

            <!-- Reservierungs-Panel -->
            <div class="col-lg-3">
                <div class="reservation-panel">
                    <div class="card shadow border-0">
                        <div class="card-header bg-warning text-dark fw-bold">
                            <i class="bi bi-cart3 me-2"></i>Reservierung
                        </div>
                        <div class="card-body">
                            <div id="noSelection" class="text-center text-muted py-3">
                                <i class="bi bi-hand-index display-4 d-block mb-2"></i>
                                <small>Klicken Sie auf einen verfügbaren Platz (grün) um ihn auszuwählen</small>
                            </div>
                            <div id="selectionPanel" style="display:none;">
                                <h6 class="fw-bold mb-3">Ausgewählte Plätze:</h6>
                                <div class="selected-seats-list mb-3" id="selectedSeatsList"></div>
                                <div class="border-top pt-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small>Preis pro Platz:</small>
                                        <small class="fw-bold"><?= formatBetrag(TICKET_PREIS) ?></small>
                                    </div>
                                    <div class="d-flex justify-content-between mb-3">
                                        <small>Gesamt:</small>
                                        <strong class="text-warning" id="totalPrice">0,00 €</strong>
                                    </div>
                                    <div class="mb-2">
                                        <small class="text-muted">Zahlungsart:</small>
                                        <span class="badge bg-secondary ms-1" id="zahlungsartBadge">
                                            <?= zahlungsartLabel($_SESSION['zahlungsart'] ?? 'bar') ?>
                                        </span>
                                        <small class="d-block text-muted mt-1">
                                            <a href="/pages/profil.php" class="text-muted">Ändern</a>
                                        </small>
                                    </div>
                                    <form id="reservationForm" method="POST" action="/api/reserve_seat.php">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                        <input type="hidden" name="seat_ids" id="seatIdsInput" value="">
                                        <button type="submit" class="btn btn-warning w-100 fw-bold" id="reserveBtn">
                                            <i class="bi bi-check2-circle me-2"></i>Jetzt reservieren
                                        </button>
                                    </form>
                                    <button class="btn btn-outline-secondary w-100 mt-2 btn-sm" onclick="clearSelection()">
                                        <i class="bi bi-x-circle me-1"></i>Auswahl leeren
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Meine Reservierungen für dieses Event -->
                    <?php if (!empty($meineReservierungen)):
                        $stmtMeine = $pdo->prepare(
                            'SELECT r.buchungsnummer, r.status, t.tischnummer, s.sitzplatznummer
                             FROM reservations r
                             JOIN seats s ON r.seat_id = s.id
                             JOIN tables t ON s.table_id = t.id
                             WHERE r.user_id = ? AND r.event_id = ?
                             ORDER BY t.tischnummer, s.sitzplatznummer'
                        );
                        $stmtMeine->execute([$userId, $eventId]);
                        $meineList = $stmtMeine->fetchAll();
                    ?>
                    <div class="card mt-3 shadow border-0">
                        <div class="card-header bg-primary text-white fw-bold">
                            <i class="bi bi-ticket-perforated me-2"></i>Meine Plätze
                        </div>
                        <div class="card-body p-2">
                            <?php foreach ($meineList as $mr): ?>
                            <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                <small>
                                    <i class="bi bi-chair text-primary"></i>
                                    Tisch <?= $mr['tischnummer'] ?>, Platz <?= $mr['sitzplatznummer'] ?>
                                </small>
                                <?= statusBadge($mr['status']) ?>
                            </div>
                            <?php endforeach; ?>
                            <div class="mt-2">
                                <a href="/pages/meine_reservierungen.php" class="btn btn-outline-primary btn-sm w-100">
                                    <i class="bi bi-list-check me-1"></i>Alle anzeigen
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
$extraScripts = <<<JS
<script>
const TICKET_PREIS = <?= TICKET_PREIS ?>;
let selectedSeats = new Map(); // seat_id => {tischnummer, sitzplatznummer}

function toggleSeat(btn) {
    const seatId = btn.dataset.seatId;
    const meinPlatz = btn.dataset.meinPlatz === '1';

    if (meinPlatz) {
        // Eigene Reservierung - stornieren?
        if (confirm('Möchten Sie diese Reservierung (Tisch ' + btn.dataset.tischnummer + ', Platz ' + btn.dataset.sitzplatznummer + ') stornieren?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/api/reserve_seat.php';
            const csrfVal  = document.querySelector('[name=csrf_token]').value;
            const eventVal = document.querySelector('[name=event_id]').value;
            form.innerHTML =
                '<input type="hidden" name="csrf_token" value="' + csrfVal + '">' +
                '<input type="hidden" name="action" value="cancel">' +
                '<input type="hidden" name="event_id" value="' + eventVal + '">' +
                '<input type="hidden" name="seat_ids" value="' + seatId + '">';
            document.body.appendChild(form);
            form.submit();
        }
        return;
    }

    if (selectedSeats.has(seatId)) {
        selectedSeats.delete(seatId);
        btn.classList.remove('ausgewaehlt');
        btn.classList.add('verfuegbar');
    } else {
        selectedSeats.set(seatId, {
            tischnummer: btn.dataset.tischnummer,
            sitzplatznummer: btn.dataset.sitzplatznummer
        });
        btn.classList.remove('verfuegbar');
        btn.classList.add('ausgewaehlt');
    }
    updatePanel();
}

function updatePanel() {
    const panel = document.getElementById('selectionPanel');
    const noSel = document.getElementById('noSelection');
    const list = document.getElementById('selectedSeatsList');
    const totalEl = document.getElementById('totalPrice');
    const input = document.getElementById('seatIdsInput');

    if (selectedSeats.size === 0) {
        panel.style.display = 'none';
        noSel.style.display = 'block';
        return;
    }

    panel.style.display = 'block';
    noSel.style.display = 'none';

    list.innerHTML = '';
    selectedSeats.forEach((data, seatId) => {
        const item = document.createElement('div');
        item.className = 'badge bg-purple text-white d-flex justify-content-between align-items-center mb-1 px-2 py-1';
        item.style.background = '#8b5cf6';
        item.innerHTML = '<span><i class="bi bi-chair me-1"></i>Tisch ' + data.tischnummer + ', Platz ' + data.sitzplatznummer + '</span>' +
            '<button type="button" class="btn-close btn-close-white btn-sm ms-2" style="font-size:0.6rem;" onclick="removeSeat(\'' + seatId + '\')"></button>';
        list.appendChild(item);
    });

    const total = selectedSeats.size * TICKET_PREIS;
    totalEl.textContent = total.toFixed(2).replace('.', ',') + ' €';
    input.value = Array.from(selectedSeats.keys()).join(',');
}

function removeSeat(seatId) {
    const btn = document.querySelector('.seat-btn[data-seat-id="' + seatId + '"]');
    if (btn) {
        btn.classList.remove('ausgewaehlt');
        btn.classList.add('verfuegbar');
    }
    selectedSeats.delete(seatId);
    updatePanel();
}

function clearSelection() {
    selectedSeats.forEach((data, seatId) => {
        const btn = document.querySelector('.seat-btn[data-seat-id="' + seatId + '"]');
        if (btn) {
            btn.classList.remove('ausgewaehlt');
            btn.classList.add('verfuegbar');
        }
    });
    selectedSeats.clear();
    updatePanel();
}

// Formular abschicken
document.getElementById('reservationForm')?.addEventListener('submit', function(e) {
    if (selectedSeats.size === 0) {
        e.preventDefault();
        alert('Bitte wählen Sie mindestens einen Sitzplatz aus.');
        return;
    }
    const btn = document.getElementById('reserveBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Reservierung läuft...';
});

// Tooltips initialisieren
document.addEventListener('DOMContentLoaded', () => {
    const tooltipEls = document.querySelectorAll('[title]');
    tooltipEls.forEach(el => {
        if (el.classList.contains('seat-btn')) {
            new bootstrap.Tooltip(el, { placement: 'top', trigger: 'hover' });
        }
    });
});
</script>
JS;

include __DIR__ . '/../includes/footer.php';
?>

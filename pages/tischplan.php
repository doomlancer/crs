<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

$eventId = (int)($_GET['event_id'] ?? 0);

$stmtEvents = $pdo->query(
    "SELECT id, datum, name, status FROM events
     WHERE status != 'abgerechnet' AND datum >= CURDATE()
     ORDER BY datum ASC"
);
$events = $stmtEvents->fetchAll();

if (!$eventId && !empty($events)) {
    $eventId = (int)$events[0]['id'];
}

$selectedEvent = null;
if ($eventId) {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $selectedEvent = $stmt->fetch();
}

$meineReservierungen = [];
$meineEingecheckt    = [];
if ($eventId) {
    $stmt = $pdo->prepare(
        'SELECT r.seat_id, r.status FROM reservations r
         WHERE r.user_id = ? AND r.event_id = ? AND r.status IN ("geplant","eingecheckt")'
    );
    $stmt->execute([$userId, $eventId]);
    foreach ($stmt->fetchAll() as $row) {
        if ($row['status'] === 'geplant') {
            $meineReservierungen[] = (int)$row['seat_id'];
        } else {
            $meineEingecheckt[] = (int)$row['seat_id'];
        }
    }
}

$aufWarteliste = false;
if ($eventId) {
    $stmtWl = $pdo->prepare(
        "SELECT id FROM waitinglist WHERE user_id = ? AND event_id = ? AND status IN ('wartend','benachrichtigt')"
    );
    $stmtWl->execute([$userId, $eventId]);
    $aufWarteliste = (bool)$stmtWl->fetch();
}

$aul = ['gesamt' => 0, 'belegt' => 0, 'frei' => 0, 'prozent' => 0];
if ($eventId) {
    $aul = getEventAuslastung($eventId);
}

$tische        = [];
$sitzePorTisch = [];
if ($selectedEvent) {
    $stmtTische = $pdo->prepare(
        'SELECT t.id AS table_id, t.tischnummer FROM tables t WHERE t.event_id = ? ORDER BY t.tischnummer'
    );
    $stmtTische->execute([$eventId]);
    $tische = $stmtTische->fetchAll();

    $stmtSitze = $pdo->prepare(
        'SELECT s.id, s.table_id, s.sitzplatznummer, s.status
         FROM seats s
         JOIN tables t ON s.table_id = t.id
         WHERE t.event_id = ?
         ORDER BY t.tischnummer, s.sitzplatznummer'
    );
    $stmtSitze->execute([$eventId]);
    foreach ($stmtSitze->fetchAll() as $sitz) {
        $sitzePorTisch[$sitz['table_id']][] = $sitz;
    }
}

$pageTitle = __('page_tischplan');
$extraHead = '<style>
/* === Checkbox-based seat selection – iOS-safe, no JavaScript required === */
.seat-check {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
    pointer-events: none;
}
.seat-label {
    cursor: pointer;
    margin: 0;
    display: inline-block;
    -webkit-tap-highlight-color: transparent;
}
/* pointer-events: none forces the tap to land on the <label>, not the <span> */
.seat-label .seat-btn {
    pointer-events: none;
    min-width: 48px;
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    font-weight: 600;
    -webkit-user-select: none;
    user-select: none;
    transition: background-color 0.12s, border-color 0.12s;
}
/* CSS-only selected state: no JS needed */
.seat-check:checked + .seat-btn {
    background-color: #8b5cf6 !important;
    border-color:     #7c3aed !important;
    color:            #fff    !important;
}
.seat-label:active .seat-btn {
    filter: brightness(0.88);
}
/* Non-interactive seat display */
.seat-static {
    min-width: 48px;
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    font-weight: 600;
}
/* Table card left accent */
.table-card { border-left: 4px solid #eab308; }
/* Sticky panel */
.panel-sticky { position: sticky; top: 76px; }
/* JS-driven summary: hidden until JS activates it */
#selectionSummary { display: none; }
</style>';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="py-4">
<div class="container-fluid px-3">
  <?= getFlash() ?>

  <!-- Event selector -->
  <div class="card shadow-sm mb-4">
    <div class="card-body py-3">
      <div class="row align-items-center g-3">
        <div class="col-auto">
          <h5 class="mb-0 fw-bold">
            <i class="bi bi-grid-3x3 text-warning me-2"></i><?= __('page_tischplan') ?>
          </h5>
        </div>
        <div class="col-md-5">
          <select class="form-select"
                  onchange="if(this.value) location.href='/pages/tischplan.php?event_id='+this.value">
            <option value="">-- Event wählen --</option>
            <?php foreach ($events as $ev): ?>
            <option value="<?= $ev['id'] ?>" <?= $ev['id'] == $eventId ? 'selected' : '' ?>>
              <?= htmlspecialchars(formatDatum($ev['datum']) . ' – ' . $ev['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($selectedEvent): ?>
        <div class="col-auto ms-auto">
          <small class="text-muted"><?= __('lbl_occupancy') ?>: <strong><?= $aul['prozent'] ?>%</strong></small>
          <div class="progress mt-1" style="height:5px;min-width:80px;">
            <div class="progress-bar <?= $aul['prozent'] >= 90 ? 'bg-danger' : ($aul['prozent'] >= 70 ? 'bg-warning' : 'bg-success') ?>"
                 style="width:<?= $aul['prozent'] ?>%"></div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (!$selectedEvent): ?>
  <div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i><?= __('msg_select_event') ?>
  </div>

  <?php elseif ($aul['frei'] <= 0 && $aul['gesamt'] > 0): ?>
  <!-- Sold out -->
  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card shadow border-0">
        <div class="card-body text-center py-5">
          <i class="bi bi-calendar-x display-3 text-warning mb-3 d-block"></i>
          <h4><?= __('msg_event_sold_out') ?></h4>
          <?php if ($aufWarteliste): ?>
          <div class="alert alert-success mt-3">
            <i class="bi bi-check-circle me-2"></i><strong><?= __('msg_on_waitinglist') ?></strong><br>
            <small><?= __('msg_waitinglist_notify') ?></small>
          </div>
          <form method="POST" action="/api/leave_waitinglist.php" class="mt-2">
            <?= csrfField() ?>
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <button class="btn btn-outline-secondary btn-sm"><?= __('btn_leave_waitinglist') ?></button>
          </form>
          <?php else: ?>
          <p class="text-muted"><?= __('msg_waitinglist_notify') ?></p>
          <form method="POST" action="/api/join_waitinglist.php" class="mt-3">
            <?= csrfField() ?>
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <button type="submit" class="btn btn-warning btn-lg fw-bold">
              <i class="bi bi-clock-history me-2"></i><?= __('btn_join_waitinglist') ?>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php else: ?>

  <!-- Main booking form – wraps both columns so checkboxes submit together -->
  <form method="POST" action="/api/reserve_seat.php" id="reservationForm">
    <?= csrfField() ?>
    <input type="hidden" name="event_id" value="<?= $eventId ?>">

    <div class="row g-4">
      <!-- LEFT: Tables (always expanded, no accordion) -->
      <div class="col-lg-8">

        <!-- Legend -->
        <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
          <span class="badge px-3 py-2" style="background:#22c55e;"><?= __('legend_available') ?></span>
          <span class="badge px-3 py-2" style="background:#ef4444;"><?= __('legend_occupied') ?></span>
          <span class="badge px-3 py-2" style="background:#eab308;color:#000;"><?= __('legend_reserved') ?></span>
          <span class="badge px-3 py-2" style="background:#3b82f6;"><?= __('legend_mine') ?></span>
          <span class="badge px-3 py-2" style="background:#8b5cf6;"><?= __('legend_selected') ?></span>
        </div>

        <?php if (empty($tische)): ?>
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle me-2"></i><?= __('msg_no_tables') ?>
        </div>
        <?php endif; ?>

        <?php foreach ($tische as $tisch):
          $sitzeListe  = $sitzePorTisch[$tisch['table_id']] ?? [];
          $freiCount   = 0;
          $meineAnzahl = 0;
          foreach ($sitzeListe as $s) {
              if ($s['status'] === 'verfuegbar'
                  && !in_array($s['id'], $meineReservierungen)
                  && !in_array($s['id'], $meineEingecheckt)) {
                  $freiCount++;
              }
              if (in_array($s['id'], $meineReservierungen)) {
                  $meineAnzahl++;
              }
          }
        ?>
        <div class="card mb-3 shadow-sm table-card">
          <div class="card-header d-flex justify-content-between align-items-center py-2">
            <span class="fw-bold">
              <i class="bi bi-grid text-warning me-1"></i>Tisch <?= $tisch['tischnummer'] ?>
            </span>
            <span class="badge <?= $freiCount > 0 ? 'bg-success' : 'bg-secondary' ?>">
              <?= $freiCount ?> <?= __('lbl_free') ?>
            </span>
          </div>
          <div class="card-body py-3">
            <div class="d-flex flex-wrap gap-2">

              <?php foreach ($sitzeListe as $sitz):
                $isGeplant   = in_array($sitz['id'], $meineReservierungen);
                $isCheckedIn = in_array($sitz['id'], $meineEingecheckt);
              ?>

              <?php if ($isGeplant): ?>
                <!-- Own seat – tap opens cancel modal -->
                <button type="button"
                        class="btn btn-primary seat-static"
                        data-bs-toggle="modal"
                        data-bs-target="#cancelModal<?= $sitz['id'] ?>"
                        title="Tisch <?= $tisch['tischnummer'] ?>, Platz <?= $sitz['sitzplatznummer'] ?> – tippen zum Stornieren">
                  <?= $sitz['sitzplatznummer'] ?>
                </button>

                <!-- Cancel modal (rendered outside form via Bootstrap portal) -->
                <div class="modal fade" id="cancelModal<?= $sitz['id'] ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered modal-sm">
                    <div class="modal-content">
                      <div class="modal-header pb-2">
                        <h6 class="modal-title">
                          <i class="bi bi-x-circle text-danger me-1"></i>Reservierung stornieren
                        </h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                      </div>
                      <div class="modal-body py-2">
                        <p class="mb-0 small">
                          Tisch <?= $tisch['tischnummer'] ?>, Platz <?= $sitz['sitzplatznummer'] ?>
                        </p>
                      </div>
                      <div class="modal-footer pt-2 gap-2">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                          <?= __('btn_close') ?>
                        </button>
                        <form method="POST" action="/api/reserve_seat.php">
                          <?= csrfField() ?>
                          <input type="hidden" name="action"   value="cancel">
                          <input type="hidden" name="event_id" value="<?= $eventId ?>">
                          <input type="hidden" name="seat_ids" value="<?= $sitz['id'] ?>">
                          <button type="submit" class="btn btn-danger btn-sm">
                            <i class="bi bi-x-circle me-1"></i><?= __('btn_cancel_booking') ?>
                          </button>
                        </form>
                      </div>
                    </div>
                  </div>
                </div>

              <?php elseif ($isCheckedIn): ?>
                <span class="btn btn-danger seat-static disabled" aria-disabled="true"
                      title="Eingecheckt">
                  <?= $sitz['sitzplatznummer'] ?>
                </span>

              <?php elseif ($sitz['status'] === 'verfuegbar'): ?>
                <!-- Available seat: label+checkbox – iOS-native, CSS-driven selection -->
                <label class="seat-label" title="Tisch <?= $tisch['tischnummer'] ?>, Platz <?= $sitz['sitzplatznummer'] ?>">
                  <input type="checkbox"
                         name="seat_ids[]"
                         value="<?= $sitz['id'] ?>"
                         class="seat-check"
                         data-tischnummer="<?= $tisch['tischnummer'] ?>"
                         data-sitzplatznummer="<?= $sitz['sitzplatznummer'] ?>"
                         aria-label="Tisch <?= $tisch['tischnummer'] ?>, Platz <?= $sitz['sitzplatznummer'] ?>">
                  <span class="btn btn-success seat-btn"><?= $sitz['sitzplatznummer'] ?></span>
                </label>

              <?php elseif ($sitz['status'] === 'reserviert'): ?>
                <span class="btn seat-static disabled" aria-disabled="true"
                      style="background:#eab308;border-color:#ca9a04;color:#000;"
                      title="Reserviert">
                  <?= $sitz['sitzplatznummer'] ?>
                </span>

              <?php else: ?>
                <span class="btn btn-danger seat-static disabled" aria-disabled="true"
                      title="Besetzt">
                  <?= $sitz['sitzplatznummer'] ?>
                </span>
              <?php endif; ?>

              <?php endforeach; ?>
            </div>

            <?php if ($freiCount === 0 && $meineAnzahl === 0): ?>
            <small class="text-muted mt-2 d-block">
              <i class="bi bi-x-circle me-1"></i><?= __('lbl_table_full') ?>
            </small>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

      </div><!-- /col-lg-8 -->

      <!-- RIGHT: Selection panel (inside form – submit button is type="submit") -->
      <div class="col-lg-4">
        <div class="card shadow border-0 panel-sticky">
          <div class="card-header bg-warning text-dark fw-bold">
            <i class="bi bi-cart3 me-2"></i><?= __('lbl_your_selection') ?>
          </div>
          <div class="card-body">

            <!-- Static info (no JS needed) -->
            <div class="mb-3">
              <div class="d-flex justify-content-between mb-1">
                <small><?= __('lbl_price_per_seat') ?></small>
                <small class="fw-bold"><?= formatBetrag((float)($selectedEvent['preis'] ?? TICKET_PREIS)) ?></small>
              </div>
              <div class="d-flex justify-content-between">
                <small><?= __('lbl_payment') ?></small>
                <span class="badge bg-secondary">
                  <?= zahlungsartLabel($_SESSION['zahlungsart'] ?? 'bar') ?>
                </span>
              </div>
              <a href="/pages/profil.php" class="d-block text-muted small mt-1">
                <?= __('btn_change') ?>
              </a>
            </div>

            <!-- JS-enhanced running total + badge list (hidden until JS activates) -->
            <div id="selectionSummary" class="border-top pt-2 mb-3">
              <div class="d-flex justify-content-between align-items-center">
                <small id="selectionCount" class="text-muted"></small>
                <strong class="text-warning" id="totalPrice"></strong>
              </div>
              <div id="selectedSeatsList" class="mt-2"></div>
            </div>

            <!-- Hint shown before any selection (hidden by JS once checkbox changes) -->
            <p class="text-muted small mb-3" id="noSelectionHint">
              <i class="bi bi-hand-index me-1"></i><?= __('msg_click_seat') ?>
            </p>

            <button type="submit" class="btn btn-warning w-100 fw-bold" id="reserveBtn">
              <i class="bi bi-check2-circle me-2"></i><?= __('btn_reserve_now') ?>
            </button>

          </div>
        </div>

        <!-- My existing reservations for this event -->
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
            <i class="bi bi-ticket-perforated me-2"></i><?= __('lbl_my_seats') ?>
          </div>
          <div class="card-body p-2">
            <?php foreach ($meineList as $mr): ?>
            <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
              <small>
                <i class="bi bi-chair text-primary me-1"></i>
                Tisch <?= $mr['tischnummer'] ?>, Platz <?= $mr['sitzplatznummer'] ?>
              </small>
              <?= statusBadge($mr['status']) ?>
            </div>
            <?php endforeach; ?>
            <div class="mt-2">
              <a href="/pages/meine_reservierungen.php" class="btn btn-outline-primary btn-sm w-100">
                <i class="bi bi-list-check me-1"></i><?= __('nav_my_bookings') ?>
              </a>
            </div>
          </div>
        </div>
        <?php endif; ?>

      </div><!-- /col-lg-4 -->
    </div><!-- /row -->
  </form>

  <?php endif; ?>
</div>
</main>

<script>
(function () {
    'use strict';

    var PREIS       = <?= json_encode((float)($selectedEvent['preis'] ?? TICKET_PREIS)) ?>;
    var summaryEl   = document.getElementById('selectionSummary');
    var countEl     = document.getElementById('selectionCount');
    var totalEl     = document.getElementById('totalPrice');
    var listEl      = document.getElementById('selectedSeatsList');
    var hintEl      = document.getElementById('noSelectionHint');
    var reserveBtn  = document.getElementById('reserveBtn');
    var form        = document.getElementById('reservationForm');

    function updatePanel() {
        var checked = document.querySelectorAll('.seat-check:checked');
        var n = checked.length;

        if (n === 0) {
            if (summaryEl) summaryEl.style.display = 'none';
            if (hintEl)    hintEl.style.display    = '';
            return;
        }

        if (hintEl)    hintEl.style.display    = 'none';
        if (summaryEl) summaryEl.style.display  = 'block';

        if (countEl) countEl.textContent = n + (n === 1 ? ' Platz ausgewählt' : ' Plätze ausgewählt');
        if (totalEl) totalEl.textContent = (n * PREIS).toFixed(2).replace('.', ',') + ' €';

        if (listEl) {
            listEl.innerHTML = '';
            checked.forEach(function (cb) {
                var b = document.createElement('span');
                b.className = 'badge me-1 mb-1 px-2 py-2';
                b.style.background = '#8b5cf6';
                b.textContent = 'T' + cb.dataset.tischnummer + ' P' + cb.dataset.sitzplatznummer;
                listEl.appendChild(b);
            });
        }
    }

    /* Single delegated listener – catches all checkboxes including any added later */
    if (form) {
        form.addEventListener('change', function (e) {
            if (e.target && e.target.classList.contains('seat-check')) {
                updatePanel();
            }
        });

        form.addEventListener('submit', function (e) {
            var checked = document.querySelectorAll('.seat-check:checked');
            if (checked.length === 0) {
                e.preventDefault();
                alert('Bitte wählen Sie mindestens einen Sitzplatz aus.');
                return;
            }
            if (reserveBtn) {
                reserveBtn.disabled = true;
                reserveBtn.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2" role="status"></span>'
                    + 'Reservierung läuft…';
            }
        });
    }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

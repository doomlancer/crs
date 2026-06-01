<?php
/**
 * Tischplan – Bootstrap Accordion-basierte Sitzplatzauswahl
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

// Aktuelles Event laden
$selectedEvent = null;
if ($eventId) {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $selectedEvent = $stmt->fetch();
}

// Bereits reservierte Sitze des Benutzers
$meineReservierungen = []; // seat_ids mit Status 'geplant' (stornierbar)
$meineEingecheckt    = []; // seat_ids mit Status 'eingecheckt' (nicht stornierbar)
if ($eventId) {
    $stmt = $pdo->prepare(
        'SELECT r.seat_id, r.status FROM reservations r WHERE r.user_id = ? AND r.event_id = ? AND r.status IN ("geplant","eingecheckt")'
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

// Wartelisten-Status prüfen
$aufWarteliste = false;
if ($eventId) {
    $stmtWl = $pdo->prepare("SELECT id FROM waitinglist WHERE user_id = ? AND event_id = ? AND status IN ('wartend','benachrichtigt')");
    $stmtWl->execute([$userId, $eventId]);
    $aufWarteliste = (bool)$stmtWl->fetch();
}

// Auslastung berechnen (vor den HTML-Branches benötigt)
$aul = ['gesamt' => 0, 'belegt' => 0, 'frei' => 0, 'prozent' => 0];
if ($eventId) {
    $aul = getEventAuslastung($eventId);
}

// Tische laden
$tische = [];
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
.btn-purple { background-color: #8b5cf6 !important; border-color: #7c3aed !important; color: #fff !important; }
.btn-purple:hover { background-color: #7c3aed !important; }
</style>';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="py-4">
<div class="container-fluid px-4">
  <?= getFlash() ?>

  <!-- Event selector card -->
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <div class="row align-items-center">
        <div class="col-md-4">
          <h4 class="mb-0 fw-bold"><i class="bi bi-grid-3x3 text-warning me-2"></i><?= __('page_tischplan') ?></h4>
        </div>
        <div class="col-md-5">
          <select class="form-select" onchange="location.href='/pages/tischplan.php?event_id='+this.value">
            <option value="">-- Event auswählen --</option>
            <?php foreach ($events as $ev): ?>
            <option value="<?= $ev['id'] ?>" <?= $ev['id'] == $eventId ? 'selected' : '' ?>>
              <?= htmlspecialchars(formatDatum($ev['datum']) . ' – ' . $ev['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($selectedEvent): ?>
        <div class="col-md-3 text-md-end">
          <small class="text-muted"><?= __('lbl_occupancy') ?>: <strong><?= $aul['prozent'] ?>%</strong></small>
          <div class="progress mt-1" style="height:6px;">
            <div class="progress-bar <?= $aul['prozent'] >= 90 ? 'bg-danger' : ($aul['prozent'] >= 70 ? 'bg-warning' : 'bg-success') ?>" style="width:<?= $aul['prozent'] ?>%"></div>
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
  <!-- Waitinglist panel (event sold out) -->
  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card shadow border-0 border-warning">
        <div class="card-header bg-dark text-warning fw-bold border-warning">
          <i class="bi bi-hourglass-split me-2"></i><?= __('msg_event_sold_out') ?>
        </div>
        <div class="card-body text-center py-4">
          <i class="bi bi-calendar-x display-3 text-warning d-block mb-3"></i>
          <p class="text-muted"><?= __('msg_event_sold_out') ?></p>
          <?php if (!$aufWarteliste): ?>
          <p class="text-muted small"><?= __('msg_waitinglist_notify') ?></p>
          <form method="POST" action="/api/join_waitinglist.php">
            <?= csrfField() ?>
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <button type="submit" class="btn btn-warning w-100 fw-bold">
              <i class="bi bi-clock-history me-2"></i><?= __('btn_join_waitinglist') ?>
            </button>
          </form>
          <?php else: ?>
          <div class="alert alert-success py-2 mb-0">
            <i class="bi bi-check-circle me-2"></i>
            <strong><?= __('msg_on_waitinglist') ?></strong><br>
            <small><?= __('msg_waitinglist_notify') ?></small>
          </div>
          <div class="mt-2">
            <a href="/pages/meine_reservierungen.php" class="btn btn-outline-secondary btn-sm w-100">
              <i class="bi bi-list-check me-1"></i><?= __('btn_manage_waitinglist') ?>
            </a>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php else: ?>

  <div class="row g-4">
    <!-- LEFT: Table accordion -->
    <div class="col-lg-8">
      <!-- Legend -->
      <div class="d-flex gap-3 mb-3 flex-wrap">
        <span class="badge" style="background:#22c55e;"><?= __('legend_available') ?></span>
        <span class="badge" style="background:#ef4444;"><?= __('legend_occupied') ?></span>
        <span class="badge" style="background:#eab308;color:#000;"><?= __('legend_reserved') ?></span>
        <span class="badge" style="background:#3b82f6;"><?= __('legend_mine') ?></span>
        <span class="badge" style="background:#8b5cf6;"><?= __('legend_selected') ?></span>
      </div>

      <!-- Accordion of tables -->
      <div class="accordion" id="tischAccordion">
        <?php foreach ($tische as $tisch):
          $sitzeListe = $sitzePorTisch[$tisch['table_id']] ?? [];
          $freiCount = 0;
          foreach ($sitzeListe as $s) {
              if ($s['status'] === 'verfuegbar' && !in_array($s['id'], $meineEingecheckt)) $freiCount++;
          }
          $meineAnzahl = count(array_filter($sitzeListe, fn($s) => in_array($s['id'], $meineReservierungen)));
        ?>
        <div class="accordion-item mb-2 border rounded">
          <h2 class="accordion-header">
            <button class="accordion-button collapsed py-2 <?= $freiCount === 0 && $meineAnzahl === 0 ? 'text-muted' : '' ?>"
                    type="button" data-bs-toggle="collapse"
                    data-bs-target="#tisch<?= $tisch['table_id'] ?>">
              <span class="fw-bold me-3">Tisch <?= $tisch['tischnummer'] ?></span>
              <span class="ms-auto d-flex gap-2 align-items-center me-3">
                <?php foreach ($sitzeListe as $s):
                  $isGeplant = in_array($s['id'], $meineReservierungen);
                  $isChecked = in_array($s['id'], $meineEingecheckt);
                  if ($isGeplant) $dot = '#3b82f6';
                  elseif ($isChecked) $dot = '#ef4444';
                  elseif ($s['status'] === 'verfuegbar') $dot = '#22c55e';
                  elseif ($s['status'] === 'reserviert') $dot = '#eab308';
                  else $dot = '#ef4444';
                ?>
                <span style="width:12px;height:12px;border-radius:50%;background:<?= $dot ?>;display:inline-block;" title="Platz <?= $s['sitzplatznummer'] ?>"></span>
                <?php endforeach; ?>
                <small class="text-muted ms-2"><?= $freiCount ?> <?= __('lbl_free') ?></small>
              </span>
            </button>
          </h2>
          <div id="tisch<?= $tisch['table_id'] ?>" class="accordion-collapse collapse">
            <div class="accordion-body py-3">
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($sitzeListe as $sitz):
                  $isGeplant = in_array($sitz['id'], $meineReservierungen);
                  $isChecked = in_array($sitz['id'], $meineEingecheckt);
                  if ($isGeplant) {
                      $cls = 'btn-primary'; $disabled = false; $meinPlatz = '1';
                  } elseif ($isChecked) {
                      $cls = 'btn-danger'; $disabled = true; $meinPlatz = '0';
                  } elseif ($sitz['status'] === 'verfuegbar') {
                      $cls = 'btn-success'; $disabled = false; $meinPlatz = '0';
                  } elseif ($sitz['status'] === 'reserviert') {
                      $cls = 'btn-warning'; $disabled = true; $meinPlatz = '0';
                  } else {
                      $cls = 'btn-danger'; $disabled = true; $meinPlatz = '0';
                  }
                ?>
                <button type="button"
                    class="btn <?= $cls ?> seat-btn"
                    style="min-width:50px;"
                    data-seat-id="<?= $sitz['id'] ?>"
                    data-tischnummer="<?= $tisch['tischnummer'] ?>"
                    data-sitzplatznummer="<?= $sitz['sitzplatznummer'] ?>"
                    data-mein-platz="<?= $meinPlatz ?>"
                    <?= $disabled ? 'disabled' : '' ?>
                    onclick="toggleSeat(this)">
                  <?= $sitz['sitzplatznummer'] ?>
                </button>
                <?php endforeach; ?>
              </div>
              <?php if ($freiCount === 0 && $meineAnzahl === 0): ?>
              <small class="text-muted mt-2 d-block"><?= __('lbl_table_full') ?></small>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if (empty($tische)): ?>
      <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i><?= __('msg_no_tables') ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: Reservation panel -->
    <div class="col-lg-4">
      <div class="card shadow border-0" style="position:sticky;top:80px;">
        <div class="card-header bg-warning text-dark fw-bold">
          <i class="bi bi-cart3 me-2"></i><?= __('lbl_your_selection') ?>
        </div>
        <div class="card-body">
          <div id="noSelection" class="text-center text-muted py-3">
            <i class="bi bi-hand-index display-4 d-block mb-2"></i>
            <small><?= __('msg_click_seat') ?></small>
          </div>
          <div id="selectionPanel" style="display:none;">
            <div id="selectedSeatsList" class="mb-3"></div>
            <div class="border-top pt-3">
              <div class="d-flex justify-content-between mb-1">
                <small><?= __('lbl_price_per_seat') ?></small>
                <small class="fw-bold"><?= formatBetrag((float)($selectedEvent['preis'] ?? TICKET_PREIS)) ?></small>
              </div>
              <div class="d-flex justify-content-between mb-3">
                <small><?= __('lbl_total') ?></small>
                <strong class="text-warning" id="totalPrice">0,00 €</strong>
              </div>
              <div class="mb-2">
                <small class="text-muted"><?= __('lbl_payment') ?>:</small>
                <span class="badge bg-secondary ms-1"><?= zahlungsartLabel($_SESSION['zahlungsart'] ?? 'bar') ?></span>
                <small class="d-block text-muted mt-1"><a href="/pages/profil.php" class="text-muted"><?= __('btn_change') ?></a></small>
              </div>
              <form id="reservationForm" method="POST" action="/api/reserve_seat.php">
                <?= csrfField() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="seat_ids" id="seatIdsInput" value="">
                <button type="submit" class="btn btn-warning w-100 fw-bold" id="reserveBtn">
                  <i class="bi bi-check2-circle me-2"></i><?= __('btn_reserve_now') ?>
                </button>
              </form>
              <button class="btn btn-outline-secondary w-100 mt-2 btn-sm" onclick="clearSelection()">
                <i class="bi bi-x-circle me-1"></i><?= __('btn_clear_selection') ?>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- My reservations for this event -->
      <?php if (!empty($meineReservierungen)):
        $stmtMeine = $pdo->prepare('SELECT r.buchungsnummer, r.status, t.tischnummer, s.sitzplatznummer FROM reservations r JOIN seats s ON r.seat_id=s.id JOIN tables t ON s.table_id=t.id WHERE r.user_id=? AND r.event_id=? ORDER BY t.tischnummer, s.sitzplatznummer');
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
            <small><i class="bi bi-chair text-primary"></i> Tisch <?= $mr['tischnummer'] ?>, Platz <?= $mr['sitzplatznummer'] ?></small>
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
    </div>
  </div>
  <?php endif; ?>
</div>
</main>

<script>
const PREIS = <?= (float)($selectedEvent['preis'] ?? TICKET_PREIS) ?>;
const selectedSeats = new Map();

function toggleSeat(btn) {
    const seatId    = btn.dataset.seatId;
    const meinPlatz = btn.dataset.meinPlatz === '1';

    if (meinPlatz) {
        if (confirm('Möchten Sie Tisch ' + btn.dataset.tischnummer + ', Platz ' + btn.dataset.sitzplatznummer + ' stornieren?')) {
            const csrfInput = document.querySelector('[name=csrf_token]');
            const eventInput = document.querySelector('[name=event_id]');
            const f = document.createElement('form');
            f.method = 'POST';
            f.action = '/api/reserve_seat.php';
            f.innerHTML = '<input type="hidden" name="csrf_token" value="' + (csrfInput ? csrfInput.value : '') + '">'
                        + '<input type="hidden" name="action" value="cancel">'
                        + '<input type="hidden" name="event_id" value="' + (eventInput ? eventInput.value : '<?= $eventId ?>') + '">'
                        + '<input type="hidden" name="seat_ids" value="' + seatId + '">';
            document.body.appendChild(f);
            f.submit();
        }
        return;
    }

    if (selectedSeats.has(seatId)) {
        selectedSeats.delete(seatId);
        btn.classList.remove('btn-purple');
        btn.classList.add('btn-success');
    } else {
        selectedSeats.set(seatId, {t: btn.dataset.tischnummer, p: btn.dataset.sitzplatznummer});
        btn.classList.remove('btn-success');
        btn.classList.add('btn-purple');
    }
    updatePanel();
}

function updatePanel() {
    const panel = document.getElementById('selectionPanel');
    const noSel = document.getElementById('noSelection');
    const list  = document.getElementById('selectedSeatsList');
    const total = document.getElementById('totalPrice');
    const input = document.getElementById('seatIdsInput');

    if (selectedSeats.size === 0) {
        panel.style.display = 'none';
        noSel.style.display = 'block';
        return;
    }
    panel.style.display = 'block';
    noSel.style.display = 'none';
    list.innerHTML = '';
    selectedSeats.forEach((d, id) => {
        const el = document.createElement('div');
        el.className = 'badge d-flex justify-content-between align-items-center mb-1 px-2 py-2';
        el.style.background = '#8b5cf6';
        el.innerHTML = '<span><i class="bi bi-chair me-1"></i>Tisch ' + d.t + ', Platz ' + d.p + '</span>'
                     + '<button type="button" class="btn-close btn-close-white btn-sm ms-2" style="font-size:0.6rem;" onclick="removeSeat(\'' + id + '\')"></button>';
        list.appendChild(el);
    });
    total.textContent = (selectedSeats.size * PREIS).toFixed(2).replace('.', ',') + ' €';
    input.value = Array.from(selectedSeats.keys()).join(',');
}

function removeSeat(seatId) {
    const btn = document.querySelector('.seat-btn[data-seat-id="' + seatId + '"]');
    if (btn) {
        btn.classList.remove('btn-purple');
        btn.classList.add('btn-success');
    }
    selectedSeats.delete(seatId);
    updatePanel();
}

function clearSelection() {
    selectedSeats.forEach((d, id) => {
        const btn = document.querySelector('.seat-btn[data-seat-id="' + id + '"]');
        if (btn) {
            btn.classList.remove('btn-purple');
            btn.classList.add('btn-success');
        }
    });
    selectedSeats.clear();
    updatePanel();
}

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
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

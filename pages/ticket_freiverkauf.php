<?php
/**
 * Ticket-Kauf für Freie-Ticket-Events (wird von tischplan.php includiert).
 * Variablen $selectedEvent, $pdo, $userId sind bereits gesetzt.
 */

// Veranstaltungsinfo mit Preis + Auslastung laden
$evId = (int)$selectedEvent['id'];
try {
    $stmtEv = $pdo->prepare('SELECT preis, max_gaeste FROM events WHERE id = ?');
    $stmtEv->execute([$evId]);
    $evExtra = $stmtEv->fetch();
    $eventPreis   = (float)($evExtra['preis']     ?: TICKET_PREIS);
    $maxGaeste    = $evExtra['max_gaeste']; // null = unbegrenzt
} catch (PDOException $e) {
    $eventPreis = (float)TICKET_PREIS;
    $maxGaeste  = null;
}

// Bisher verkaufte Tickets
$stmtVerk = $pdo->prepare(
    "SELECT COUNT(*) FROM reservations WHERE event_id = ? AND status != 'abgerechnet'"
);
$stmtVerk->execute([$evId]);
$verkauft = (int)$stmtVerk->fetchColumn();

$restTickets = $maxGaeste !== null ? max(0, (int)$maxGaeste - $verkauft) : null;
$ausverkauft = $maxGaeste !== null && $restTickets <= 0;

// Eigene Reservierungen dieses Users für dieses Event
$stmtMeine = $pdo->prepare(
    "SELECT r.buchungsnummer, r.erstellt_am, p.zahlungsart, p.status AS pay_status
     FROM reservations r
     LEFT JOIN payments p ON p.reservation_id = r.id
     WHERE r.event_id = ? AND r.user_id = ? AND r.status != 'abgerechnet'
     ORDER BY r.erstellt_am DESC"
);
$stmtMeine->execute([$evId, $userId]);
$meineTickets = $stmtMeine->fetchAll();

$pageTitle = htmlspecialchars($selectedEvent['name']);
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container py-4" style="max-width:700px;">

    <?= getFlash() ?>

    <!-- Zurück / Event-Selektor (übernommen aus tischplan.php) -->
    <?php if (count($events) > 1): ?>
    <form method="GET" class="mb-3">
        <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
            <select name="event_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($events as $ev): ?>
                <option value="<?= $ev['id'] ?>" <?= $ev['id'] == $evId ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ev['name']) ?> – <?= formatDatum($ev['datum']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php endif; ?>

    <!-- Event-Info-Karte -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h2 class="h4 fw-bold mb-1"><?= htmlspecialchars($selectedEvent['name']) ?></h2>
                    <span class="badge bg-warning text-dark me-1">
                        <i class="bi bi-calendar3 me-1"></i><?= formatDatum($selectedEvent['datum']) ?>
                    </span>
                    <span class="badge bg-success">
                        <i class="bi bi-ticket-perforated me-1"></i>Freier Einlass
                    </span>
                </div>
                <div class="text-end">
                    <div class="fs-4 fw-bold text-warning"><?= formatBetrag($eventPreis) ?></div>
                    <small class="text-muted">pro Ticket</small>
                </div>
            </div>
            <?php if (!empty($selectedEvent['beschreibung'])): ?>
            <p class="text-muted mt-3 mb-0 small"><?= nl2br(htmlspecialchars($selectedEvent['beschreibung'])) ?></p>
            <?php endif; ?>

            <?php if ($maxGaeste !== null): ?>
            <div class="mt-3">
                <?php $pct = $maxGaeste > 0 ? round(($verkauft / $maxGaeste) * 100) : 0; ?>
                <div class="d-flex justify-content-between small mb-1">
                    <span class="text-muted">Tickets verkauft</span>
                    <strong><?= $verkauft ?> / <?= $maxGaeste ?></strong>
                </div>
                <div class="progress" style="height:6px;">
                    <div class="progress-bar <?= $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success') ?>"
                         style="width:<?= $pct ?>%"></div>
                </div>
                <?php if ($ausverkauft): ?>
                <div class="alert alert-danger mt-2 mb-0 py-2 small">
                    <i class="bi bi-x-circle me-1"></i>Diese Veranstaltung ist ausverkauft.
                </div>
                <?php elseif ($restTickets !== null && $restTickets <= 20): ?>
                <div class="alert alert-warning mt-2 mb-0 py-2 small">
                    <i class="bi bi-exclamation-triangle me-1"></i>Nur noch <strong><?= $restTickets ?></strong> Ticket(s) verfügbar!
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Buchungsformular -->
    <?php if (!$ausverkauft): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header fw-semibold">
            <i class="bi bi-ticket-perforated text-warning me-2"></i>Tickets reservieren
        </div>
        <div class="card-body">
            <form method="POST" action="/api/reserve_seat.php">
                <?= csrfField() ?>
                <input type="hidden" name="action"   value="reserve_free_ticket">
                <input type="hidden" name="event_id" value="<?= $evId ?>">

                <div class="row g-3 align-items-end">
                    <div class="col-sm-4">
                        <label class="form-label fw-semibold small">Anzahl Tickets</label>
                        <div class="input-group">
                            <button type="button" class="btn btn-outline-secondary" id="btn-minus">
                                <i class="bi bi-dash"></i>
                            </button>
                            <input type="number" name="anzahl" id="anzahl-input"
                                   class="form-control text-center fw-bold"
                                   value="1" min="1"
                                   max="<?= $restTickets !== null ? min(10, $restTickets) : 10 ?>">
                            <button type="button" class="btn btn-outline-secondary" id="btn-plus">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label fw-semibold small">Gesamtpreis</label>
                        <div class="form-control-plaintext fw-bold fs-5 text-warning" id="price-display">
                            <?= formatBetrag($eventPreis) ?>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <button type="submit" class="btn btn-warning w-100 fw-bold">
                            <i class="bi bi-cart-check me-1"></i>Jetzt reservieren
                        </button>
                    </div>
                </div>

                <p class="text-muted small mt-3 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Zahlungsart: <strong><?= zahlungsartLabel($_SESSION['zahlungsart'] ?? 'bar') ?></strong>
                    (aus Ihrem Profil) · Sie erhalten eine Buchungsnummer pro Ticket.
                </p>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Meine Tickets für dieses Event -->
    <?php if (!empty($meineTickets)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold">
            <i class="bi bi-check2-circle text-success me-2"></i>Meine Tickets für diese Veranstaltung
        </div>
        <div class="card-body p-0">
            <ul class="list-group list-group-flush">
                <?php foreach ($meineTickets as $t): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <code class="text-primary fw-bold"><?= htmlspecialchars($t['buchungsnummer']) ?></code>
                        <small class="text-muted ms-2"><?= date('d.m.Y', strtotime($t['erstellt_am'])) ?></small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?= statusBadge($t['pay_status'] ?? 'offen') ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="card-footer bg-transparent">
            <a href="/pages/meine_reservierungen.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-list-ul me-1"></i>Alle Reservierungen ansehen
            </a>
        </div>
    </div>
    <?php endif; ?>

</main>

<?php
$unitPreis = number_format($eventPreis, 2, '.', '');
$extraScripts = <<<JS
<script>
(function() {
    var input   = document.getElementById('anzahl-input');
    var display = document.getElementById('price-display');
    var unitPreis = {$unitPreis};

    function formatEuro(n) {
        return n.toFixed(2).replace('.', ',') + ' €';
    }
    function update() {
        var n = Math.max(1, parseInt(input.value) || 1);
        input.value = n;
        if (display) display.textContent = formatEuro(n * unitPreis);
    }
    if (input) {
        input.addEventListener('input', update);
        var btnM = document.getElementById('btn-minus');
        var btnP = document.getElementById('btn-plus');
        if (btnM) btnM.addEventListener('click', function() { input.value = Math.max(1, (parseInt(input.value)||1) - 1); update(); });
        if (btnP) btnP.addEventListener('click', function() { input.value = Math.min(parseInt(input.max)||10, (parseInt(input.value)||1) + 1); update(); });
    }
})();
</script>
JS;
include __DIR__ . '/../includes/footer.php';
?>

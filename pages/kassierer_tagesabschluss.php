<?php
/**
 * Kassierer Tagesabschluss-Report
 * GET: ?event_id=X
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

requireRole('kassierer', 'admin');

$pdo     = getDB();
$eventId = (int)($_GET['event_id'] ?? 0);

// Events für Auswahl laden
$stmtEvents = $pdo->query(
    "SELECT id, datum, name FROM events ORDER BY datum DESC LIMIT 50"
);
$events = $stmtEvents->fetchAll();

// Wenn kein Event gewählt, erstes nehmen
if (!$eventId && !empty($events)) {
    $eventId = (int)$events[0]['id'];
}

$selectedEvent = null;
$stats         = [];
$buchungen     = [];
$stundenData   = [];

if ($eventId) {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $selectedEvent = $stmt->fetch();

    if ($selectedEvent) {
        // Alle Buchungen für dieses Event
        $stmt = $pdo->prepare(
            'SELECT r.id, r.buchungsnummer, r.status AS res_status, r.erstellt_am,
                    u.vorname, u.nachname, u.email,
                    t.tischnummer, s.sitzplatznummer,
                    p.zahlungsart, p.status AS pay_status, p.betrag
             FROM reservations r
             JOIN users  u ON r.user_id  = u.id
             JOIN seats  s ON r.seat_id  = s.id
             JOIN tables t ON s.table_id = t.id
             LEFT JOIN payments p ON p.reservation_id = r.id
             WHERE r.event_id = ?
             ORDER BY r.erstellt_am DESC'
        );
        $stmt->execute([$eventId]);
        $buchungen = $stmt->fetchAll();

        // Statistiken berechnen
        $totalBuchungen  = count($buchungen);
        $checkedIn       = count(array_filter($buchungen, fn($b) => $b['res_status'] === 'eingecheckt'));
        $geplant         = count(array_filter($buchungen, fn($b) => $b['res_status'] === 'geplant'));
        $gesamtUmsatz    = array_sum(array_column($buchungen, 'betrag'));
        $bezahlt         = array_sum(array_map(fn($b) => $b['pay_status'] === 'bezahlt' ? (float)$b['betrag'] : 0, $buchungen));
        $offen           = $gesamtUmsatz - $bezahlt;

        // Zahlungsart-Verteilung
        $zahlungsarten = [];
        foreach ($buchungen as $b) {
            $art = $b['zahlungsart'] ?? 'bar';
            if (!isset($zahlungsarten[$art])) {
                $zahlungsarten[$art] = ['anzahl' => 0, 'betrag' => 0];
            }
            $zahlungsarten[$art]['anzahl']++;
            $zahlungsarten[$art]['betrag'] += (float)$b['betrag'];
        }

        // Buchungen pro Stunde (für Chart)
        $stundenZaehler = array_fill(0, 24, 0);
        foreach ($buchungen as $b) {
            $stunde = (int)date('G', strtotime($b['erstellt_am']));
            $stundenZaehler[$stunde]++;
        }

        // Wartelisten-Einträge
        $stmtWl = $pdo->prepare(
            "SELECT COUNT(*) FROM waitinglist WHERE event_id = ? AND status IN ('wartend','benachrichtigt')"
        );
        $stmtWl->execute([$eventId]);
        $wartelisteAnzahl = (int)$stmtWl->fetchColumn();

        $stats = compact('totalBuchungen', 'checkedIn', 'geplant', 'gesamtUmsatz',
                         'bezahlt', 'offen', 'zahlungsarten', 'wartelisteAnzahl');
        $stundenData = $stundenZaehler;
    }
}

$pageTitle = 'Tagesabschluss';
$bodyClass = 'bg-light';
$extraHead = '';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="py-4">
<div class="container-fluid px-4">

    <?= getFlash() ?>

    <!-- Header -->
    <div class="row align-items-center mb-4 no-print">
        <div class="col">
            <h1 class="fw-bold mb-1">
                <i class="bi bi-clipboard-data text-warning me-2"></i>Tagesabschluss
            </h1>
            <p class="text-muted mb-0">Detaillierter Abrechnungsbericht</p>
        </div>
        <div class="col-auto d-flex gap-2">
            <a href="/pages/kassierer_guestlist.php<?= $eventId ? '?event_id=' . $eventId : '' ?>"
               class="btn btn-outline-secondary">
                <i class="bi bi-people me-1"></i>Gästeliste
            </a>
            <button onclick="window.print()" class="btn btn-warning fw-semibold">
                <i class="bi bi-printer me-2"></i>Drucken / PDF
            </button>
        </div>
    </div>

    <!-- Event-Auswahl -->
    <div class="row mb-4 no-print">
        <div class="col-md-5">
            <select class="form-select" onchange="location.href='/pages/kassierer_tagesabschluss.php?event_id='+this.value">
                <option value="">-- Event auswählen --</option>
                <?php foreach ($events as $ev): ?>
                <option value="<?= $ev['id'] ?>" <?= $ev['id'] == $eventId ? 'selected' : '' ?>>
                    <?= htmlspecialchars(formatDatum($ev['datum']) . ' – ' . $ev['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (!$selectedEvent): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>Bitte wählen Sie ein Event für den Tagesabschluss.
    </div>
    <?php else: ?>

    <!-- Print-Header (nur beim Drucken) -->
    <div class="print-only mb-4" style="display:none;">
        <h2 class="fw-bold">Tagesabschluss: <?= htmlspecialchars($selectedEvent['name']) ?></h2>
        <p>Datum: <?= formatDatum($selectedEvent['datum']) ?> | Erstellt: <?= date('d.m.Y H:i') ?> Uhr</p>
        <hr>
    </div>

    <!-- KPI-Karten -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body py-3">
                    <div class="display-6 fw-bold text-primary"><?= $stats['totalBuchungen'] ?? 0 ?></div>
                    <div class="small text-muted">Buchungen gesamt</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body py-3">
                    <div class="display-6 fw-bold text-success"><?= $stats['checkedIn'] ?? 0 ?></div>
                    <div class="small text-muted">Eingecheckt</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body py-3">
                    <div class="display-6 fw-bold text-warning"><?= formatBetrag($stats['gesamtUmsatz'] ?? 0) ?></div>
                    <div class="small text-muted">Gesamtumsatz</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body py-3">
                    <div class="display-6 fw-bold text-danger"><?= formatBetrag($stats['offen'] ?? 0) ?></div>
                    <div class="small text-muted">Offen</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Zahlungsart-Verteilung -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-dark text-white border-0 fw-semibold">
                    <i class="bi bi-credit-card me-1"></i>Zahlungsarten
                </div>
                <div class="card-body">
                    <?php foreach ($stats['zahlungsarten'] ?? [] as $art => $data): ?>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span>
                            <?php
                            $icon = match($art) {
                                'paypal'       => 'bi-paypal text-primary',
                                'ueberweisung' => 'bi-bank text-info',
                                default        => 'bi-cash text-success',
                            };
                            ?>
                            <i class="bi <?= $icon ?> me-2"></i>
                            <?= zahlungsartLabel($art) ?>
                        </span>
                        <span>
                            <span class="badge bg-secondary me-1"><?= $data['anzahl'] ?>x</span>
                            <strong><?= formatBetrag($data['betrag']) ?></strong>
                        </span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($stats['zahlungsarten'])): ?>
                    <p class="text-muted text-center py-3 mb-0">Keine Buchungen</p>
                    <?php endif; ?>
                    <?php if (($stats['wartelisteAnzahl'] ?? 0) > 0): ?>
                    <div class="mt-3 p-2 bg-light rounded">
                        <small class="text-muted">
                            <i class="bi bi-hourglass-split me-1"></i>
                            Auf Warteliste: <strong><?= $stats['wartelisteAnzahl'] ?></strong> Person(en)
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Buchungs-Zeitstrahl Chart -->
        <div class="col-md-8 no-print">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-dark text-white border-0 fw-semibold">
                    <i class="bi bi-graph-up me-1"></i>Buchungen nach Uhrzeit
                </div>
                <div class="card-body">
                    <canvas id="stundenChart" height="120"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Buchungsliste -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-dark text-white border-0 d-flex justify-content-between align-items-center">
            <span class="fw-semibold">
                <i class="bi bi-list-check me-1"></i>Alle Buchungen (<?= count($buchungen) ?>)
            </span>
            <a href="/api/export_guestlist.php?event_id=<?= $eventId ?>&format=csv"
               class="btn btn-outline-warning btn-sm no-print">
                <i class="bi bi-download me-1"></i>CSV Export
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-secondary">
                    <tr>
                        <th>Buchungsnr.</th>
                        <th>Gast</th>
                        <th>Sitz</th>
                        <th>Zahlungsart</th>
                        <th>Betrag</th>
                        <th>Buchungsstatus</th>
                        <th>Zahlungsstatus</th>
                        <th>Buchungszeit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($buchungen as $b): ?>
                    <tr class="<?= $b['res_status'] === 'eingecheckt' ? 'table-success' : '' ?>">
                        <td>
                            <a href="/pages/buchung_detail.php?buchungsnummer=<?= urlencode($b['buchungsnummer']) ?>"
                               class="text-decoration-none font-monospace small fw-bold">
                                <?= htmlspecialchars($b['buchungsnummer']) ?>
                            </a>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($b['vorname'] . ' ' . $b['nachname']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($b['email']) ?></small>
                        </td>
                        <td>
                            <small>T<?= $b['tischnummer'] ?>/P<?= $b['sitzplatznummer'] ?></small>
                        </td>
                        <td>
                            <?php
                            $icon = match($b['zahlungsart'] ?? '') {
                                'paypal'       => 'bi-paypal text-primary',
                                'ueberweisung' => 'bi-bank text-info',
                                default        => 'bi-cash text-success',
                            };
                            ?>
                            <i class="bi <?= $icon ?>"></i>
                            <small><?= zahlungsartLabel($b['zahlungsart'] ?? 'bar') ?></small>
                        </td>
                        <td class="fw-bold"><?= formatBetrag((float)($b['betrag'] ?? 0)) ?></td>
                        <td><?= statusBadge($b['res_status']) ?></td>
                        <td><?= statusBadge($b['pay_status'] ?? 'offen') ?></td>
                        <td><small class="text-muted"><?= date('H:i', strtotime($b['erstellt_am'])) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($buchungen)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Keine Buchungen vorhanden.</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($buchungen)): ?>
                <tfoot class="table-dark fw-bold">
                    <tr>
                        <td colspan="4" class="text-end">Gesamt:</td>
                        <td><?= formatBetrag($stats['gesamtUmsatz'] ?? 0) ?></td>
                        <td colspan="3">
                            <small>
                                <?= $stats['checkedIn'] ?> eingecheckt,
                                <?= $stats['geplant'] ?> ausstehend
                            </small>
                        </td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <?php endif; // selectedEvent ?>

</div>
</main>

<style>
@media print {
    .navbar, .no-print { display: none !important; }
    .print-only { display: block !important; }
    body { background: white !important; font-size: 12px; }
    .card { box-shadow: none !important; border: 1px solid #ddd !important; }
}
</style>

<?php
$stundenJson = json_encode(array_values($stundenData ?? []));
$extraScripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function() {
    var ctx = document.getElementById('stundenChart');
    if (!ctx) return;
    var data = {$stundenJson};
    var labels = [];
    for (var i = 0; i < 24; i++) {
        labels.push(i.toString().padStart(2,'0') + ':00');
    }
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Buchungen',
                data: data,
                backgroundColor: 'rgba(245,158,11,0.7)',
                borderColor: 'rgba(245,158,11,1)',
                borderWidth: 1,
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
})();
</script>
HTML;

include __DIR__ . '/../includes/footer.php';

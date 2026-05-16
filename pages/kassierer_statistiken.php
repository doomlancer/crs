<?php
/**
 * Kassierer Statistiken
 * Umsatz- und Auslastungsstatistiken mit Chart.js-Visualisierungen.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

requireRole('kassierer', 'admin');

$pdo = getDB();

// ─── Events für Selektor ──────────────────────────────────────────────────────
$events = $pdo->query(
    "SELECT id, name, datum, status
     FROM events
     ORDER BY datum DESC"
)->fetchAll();

$selectedEventId = (int)($_GET['event_id'] ?? ($events[0]['id'] ?? 0));

// Gewähltes Event laden
$currentEvent = null;
if ($selectedEventId) {
    $stmtEv = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $stmtEv->execute([$selectedEventId]);
    $currentEvent = $stmtEv->fetch();
}

// ─── Auslastung ───────────────────────────────────────────────────────────────
$auslastung = $selectedEventId ? getEventAuslastung($selectedEventId) : ['gesamt' => 0, 'belegt' => 0, 'frei' => 0, 'prozent' => 0];

// ─── KPI: Gesamtumsatz (bezahlte Zahlungen für dieses Event) ─────────────────
$gesamtUmsatz   = 0.0;
$offeneZahlungen = 0;
$reservierungen  = 0;

if ($selectedEventId) {
    $stmtKpi = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN p.status = 'bezahlt' THEN p.betrag ELSE 0 END), 0) AS gesamtumsatz,
            SUM(CASE WHEN p.status = 'offen' THEN 1 ELSE 0 END)                        AS offene_zahlungen,
            COUNT(DISTINCT r.id)                                                         AS reservierungen
         FROM reservations r
         LEFT JOIN payments p ON p.reservation_id = r.id
         WHERE r.event_id = ?"
    );
    $stmtKpi->execute([$selectedEventId]);
    $kpiRow = $stmtKpi->fetch();

    $gesamtUmsatz    = (float)($kpiRow['gesamtumsatz']     ?? 0);
    $offeneZahlungen = (int)  ($kpiRow['offene_zahlungen'] ?? 0);
    $reservierungen  = (int)  ($kpiRow['reservierungen']   ?? 0);
}

// ─── Zahlungsart-Verteilung ───────────────────────────────────────────────────
// [ zahlungsart => [ anzahl, umsatz ] ]
$zahlungsartDaten = [];

if ($selectedEventId) {
    $stmtZa = $pdo->prepare(
        "SELECT
            p.zahlungsart,
            COUNT(p.id)                                                       AS anzahl,
            COALESCE(SUM(CASE WHEN p.status = 'bezahlt' THEN p.betrag END),0) AS umsatz
         FROM payments p
         INNER JOIN reservations r ON r.id = p.reservation_id
         WHERE r.event_id = ?
         GROUP BY p.zahlungsart
         ORDER BY umsatz DESC"
    );
    $stmtZa->execute([$selectedEventId]);
    $zahlungsartDaten = $stmtZa->fetchAll();
}

// Gesamtanzahl Zahlungen für %-Berechnung
$totalZahlungen = array_sum(array_column($zahlungsartDaten, 'anzahl'));

// ─── Reservierungen über Zeit (kumulativ je Tag) ───────────────────────────────
$zeitreiheDaten = [];

if ($selectedEventId) {
    $stmtZr = $pdo->prepare(
        "SELECT
            DATE(r.erstellt_am)   AS tag,
            COUNT(r.id)           AS neu
         FROM reservations r
         WHERE r.event_id = ?
         GROUP BY DATE(r.erstellt_am)
         ORDER BY tag ASC"
    );
    $stmtZr->execute([$selectedEventId]);
    $zeitreiheRaw = $stmtZr->fetchAll();

    // Kumulativ aufsummieren
    $kumulativ = 0;
    foreach ($zeitreiheRaw as $row) {
        $kumulativ += (int)$row['neu'];
        $zeitreiheDaten[] = [
            'tag'        => date('d.m.Y', strtotime($row['tag'])),
            'kumulativ'  => $kumulativ,
            'neu'        => (int)$row['neu'],
        ];
    }
}

// ─── Chart.js Daten vorbereiten (JSON für Inline-JS) ─────────────────────────

// Farben je Zahlungsart
$zahlungsartFarben = [
    'bar'          => '#198754',   // grün
    'ueberweisung' => '#0d6efd',   // blau
    'paypal'       => '#0dcaf0',   // cyan
];

// Pie Chart: Zahlungsart-Verteilung (Anzahl)
$pieLabels  = [];
$pieData    = [];
$pieColors  = [];
foreach ($zahlungsartDaten as $row) {
    $pieLabels[] = zahlungsartLabel($row['zahlungsart']);
    $pieData[]   = (int)$row['anzahl'];
    $pieColors[] = $zahlungsartFarben[$row['zahlungsart']] ?? '#6c757d';
}

// Bar Chart: Umsatz nach Zahlungsart
$barLabels  = $pieLabels;
$barData    = array_map(fn($r) => round((float)$r['umsatz'], 2), $zahlungsartDaten);
$barColors  = $pieColors;

// Line Chart: Zeitreihe
$lineLabels      = array_column($zeitreiheDaten, 'tag');
$lineKumulativ   = array_column($zeitreiheDaten, 'kumulativ');
$lineNeu         = array_column($zeitreiheDaten, 'neu');

// JSON-kodieren (sicher für Inline-JS)
$jsonPieLabels   = json_encode($pieLabels,    JSON_UNESCAPED_UNICODE);
$jsonPieData     = json_encode($pieData);
$jsonPieColors   = json_encode($pieColors);
$jsonBarData     = json_encode($barData);
$jsonLineLabels  = json_encode($lineLabels,   JSON_UNESCAPED_UNICODE);
$jsonLineKum     = json_encode($lineKumulativ);
$jsonLineNeu     = json_encode($lineNeu);

// ─── Seite ausgeben ───────────────────────────────────────────────────────────
$pageTitle  = 'Statistiken';
$bodyClass  = 'bg-light';
$extraHead  = ''; // Chart.js ist bereits im footer.php eingebunden
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container-fluid py-4">

    <!-- Seitentitel -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-bar-chart text-warning me-2"></i>Statistiken
            </h1>
            <p class="text-muted mb-0 small">
                <?php if ($currentEvent): ?>
                    <?= htmlspecialchars($currentEvent['name']) ?>
                    &bull; <?= formatDatum($currentEvent['datum']) ?>
                    &bull; <?= statusBadge($currentEvent['status']) ?>
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
               class="btn btn-outline-primary btn-sm">
                <i class="bi bi-people me-1"></i>Gästeliste
            </a>
        </div>
    </div>

    <?= getFlash() ?>

    <!-- ── Event-Selektor ─────────────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-3">
            <form method="GET" action="" class="d-flex align-items-center gap-3 flex-wrap">
                <label for="event_id" class="form-label mb-0 fw-semibold small text-nowrap">
                    <i class="bi bi-calendar-event me-1"></i>Event auswählen:
                </label>
                <select name="event_id" id="event_id"
                        class="form-select form-select-sm"
                        style="max-width: 400px;"
                        onchange="this.form.submit()">
                    <option value="">– Event wählen –</option>
                    <?php foreach ($events as $ev): ?>
                    <option value="<?= (int)$ev['id'] ?>"
                        <?= $ev['id'] == $selectedEventId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ev['name']) ?>
                        (<?= formatDatum($ev['datum']) ?> – <?= ucfirst($ev['status']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-warning btn-sm">
                    <i class="bi bi-arrow-repeat me-1"></i>Aktualisieren
                </button>
            </form>
        </div>
    </div>

    <?php if (!$selectedEventId): ?>
    <!-- Kein Event gewählt -->
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-bar-chart-line fs-1 d-block mb-3"></i>
            <h5>Kein Event ausgewählt</h5>
            <p class="mb-0">Wählen Sie oben ein Event aus, um die Statistiken zu sehen.</p>
        </div>
    </div>
    <?php else: ?>

    <!-- ── KPI-Karten ──────────────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">

        <!-- Gesamtumsatz -->
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-muted small mb-1">Gesamtumsatz</p>
                            <h4 class="fw-bold mb-0 text-success"><?= formatBetrag($gesamtUmsatz) ?></h4>
                            <small class="text-muted">nur bezahlte Tickets</small>
                        </div>
                        <div class="bg-success bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-cash-stack fs-3 text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Auslastung -->
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-muted small mb-1">Auslastung</p>
                            <h4 class="fw-bold mb-0 text-info"><?= $auslastung['prozent'] ?> %</h4>
                            <small class="text-muted">
                                <?= $auslastung['belegt'] ?> / <?= $auslastung['gesamt'] ?> Plätze
                            </small>
                        </div>
                        <div class="bg-info bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-pie-chart fs-3 text-info"></i>
                        </div>
                    </div>
                    <div class="progress mt-2" style="height: 5px;">
                        <?php
                        $aBarColor = $auslastung['prozent'] >= 90 ? 'bg-danger'
                                   : ($auslastung['prozent'] >= 70 ? 'bg-warning' : 'bg-success');
                        ?>
                        <div class="progress-bar <?= $aBarColor ?>"
                             role="progressbar"
                             style="width: <?= $auslastung['prozent'] ?>%"
                             aria-valuenow="<?= $auslastung['prozent'] ?>"
                             aria-valuemin="0" aria-valuemax="100">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Offene Zahlungen -->
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-muted small mb-1">Offene Zahlungen</p>
                            <h4 class="fw-bold mb-0 <?= $offeneZahlungen > 0 ? 'text-warning' : 'text-muted' ?>">
                                <?= $offeneZahlungen ?>
                            </h4>
                            <small class="text-muted">noch ausstehend</small>
                        </div>
                        <div class="bg-warning bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-hourglass-split fs-3 text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reservierungen gesamt -->
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-muted small mb-1">Reservierungen</p>
                            <h4 class="fw-bold mb-0 text-primary"><?= $reservierungen ?></h4>
                            <small class="text-muted">gesamt</small>
                        </div>
                        <div class="bg-primary bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-ticket-perforated fs-3 text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /KPI-row -->

    <!-- ── Charts: Zeile 1 (Pie + Bar) ───────────────────────────────────── -->
    <div class="row g-4 mb-4">

        <!-- Pie Chart: Zahlungsart-Verteilung -->
        <div class="col-12 col-md-5 col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-pie-chart-fill me-2 text-warning"></i>Zahlungsart-Verteilung
                    </h5>
                    <small class="text-muted">Anteil nach Anzahl der Buchungen</small>
                </div>
                <div class="card-body d-flex align-items-center justify-content-center" style="min-height: 300px;">
                    <?php if (empty($zahlungsartDaten)): ?>
                    <div class="text-center text-muted">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                        Keine Zahlungsdaten vorhanden
                    </div>
                    <?php else: ?>
                    <div style="position: relative; width: 100%; max-width: 300px;">
                        <canvas id="pieChart"></canvas>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($zahlungsartDaten)): ?>
                <div class="card-footer bg-light">
                    <div class="d-flex flex-wrap gap-3 justify-content-center small">
                        <?php foreach ($zahlungsartDaten as $i => $row): ?>
                        <span>
                            <span class="badge" style="background-color: <?= htmlspecialchars($pieColors[$i] ?? '#6c757d') ?>">
                                &nbsp;
                            </span>
                            <?= htmlspecialchars(zahlungsartLabel($row['zahlungsart'])) ?>:
                            <?= $totalZahlungen > 0 ? round($row['anzahl'] / $totalZahlungen * 100) : 0 ?>%
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bar Chart: Umsatz nach Zahlungsart -->
        <div class="col-12 col-md-7 col-xl-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-bar-chart-fill me-2 text-warning"></i>Umsatz nach Zahlungsart
                    </h5>
                    <small class="text-muted">Nur bezahlte Transaktionen in Euro</small>
                </div>
                <div class="card-body" style="min-height: 300px; position: relative;">
                    <?php if (empty($zahlungsartDaten)): ?>
                    <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                        <div class="text-center">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            Keine Zahlungsdaten vorhanden
                        </div>
                    </div>
                    <?php else: ?>
                    <canvas id="barChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /Charts Zeile 1 -->

    <!-- ── Chart: Reservierungen über Zeit ───────────────────────────────── -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-graph-up me-2 text-warning"></i>Reservierungsverlauf
                    </h5>
                    <small class="text-muted">Kumulativer Buchungsverlauf seit Event-Erstellung</small>
                </div>
                <div class="card-body" style="min-height: 300px; position: relative;">
                    <?php if (empty($zeitreiheDaten)): ?>
                    <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                        <div class="text-center">
                            <i class="bi bi-graph-up fs-2 d-block mb-2"></i>
                            Noch keine Reservierungsdaten vorhanden
                        </div>
                    </div>
                    <?php else: ?>
                    <canvas id="lineChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Detailtabelle: Zahlungsart-Aufschlüsselung ────────────────────── -->
    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-table me-2 text-warning"></i>Detaillierte Aufschlüsselung
                    </h5>
                    <small class="text-muted">Zahlungsart | Anzahl | Umsatz | Anteil</small>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($zahlungsartDaten)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                        Keine Daten vorhanden
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th class="ps-3">Zahlungsart</th>
                                    <th class="text-center">Anzahl</th>
                                    <th class="text-end">Umsatz (bezahlt)</th>
                                    <th class="text-end pe-3">Anteil</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $gesamtUmsatzAlle = array_sum(array_column($zahlungsartDaten, 'umsatz'));
                            foreach ($zahlungsartDaten as $i => $row):
                                $anteilAnzahl  = $totalZahlungen > 0
                                    ? round($row['anzahl'] / $totalZahlungen * 100, 1)
                                    : 0;
                                $anteilUmsatz  = $gesamtUmsatzAlle > 0
                                    ? round($row['umsatz'] / $gesamtUmsatzAlle * 100, 1)
                                    : 0;
                                $farbe = $pieColors[$i] ?? '#6c757d';
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <span class="d-flex align-items-center gap-2">
                                        <span style="
                                            display:inline-block;
                                            width:12px; height:12px;
                                            border-radius:3px;
                                            background:<?= htmlspecialchars($farbe) ?>;
                                            flex-shrink:0;">
                                        </span>
                                        <?php
                                        $zartIcon2 = match($row['zahlungsart']) {
                                            'bar'          => 'bi-cash-coin text-success',
                                            'ueberweisung' => 'bi-bank text-primary',
                                            'paypal'       => 'bi-paypal text-info',
                                            default        => 'bi-question-circle text-muted',
                                        };
                                        ?>
                                        <i class="bi <?= $zartIcon2 ?>"></i>
                                        <strong><?= htmlspecialchars(zahlungsartLabel($row['zahlungsart'])) ?></strong>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?= (int)$row['anzahl'] ?></span>
                                    <small class="text-muted ms-1">(<?= $anteilAnzahl ?>%)</small>
                                </td>
                                <td class="text-end fw-semibold text-success">
                                    <?= formatBetrag((float)$row['umsatz']) ?>
                                </td>
                                <td class="text-end pe-3">
                                    <div class="d-flex align-items-center justify-content-end gap-2">
                                        <div class="progress flex-grow-1" style="height: 8px; max-width: 80px;">
                                            <div class="progress-bar"
                                                 role="progressbar"
                                                 style="width: <?= $anteilUmsatz ?>%; background-color: <?= htmlspecialchars($farbe) ?>;"
                                                 aria-valuenow="<?= $anteilUmsatz ?>"
                                                 aria-valuemin="0" aria-valuemax="100">
                                            </div>
                                        </div>
                                        <span class="fw-semibold" style="min-width:38px;"><?= $anteilUmsatz ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td class="ps-3">Gesamt</td>
                                    <td class="text-center"><?= $totalZahlungen ?></td>
                                    <td class="text-end text-success"><?= formatBetrag($gesamtUmsatz) ?></td>
                                    <td class="text-end pe-3">100 %</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Auslastungs-Details ─────────────────────────────────────────── -->
        <div class="col-12 col-xl-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-speedometer me-2 text-warning"></i>Auslastung
                    </h5>
                    <small class="text-muted">Platzkapazität und Belegung</small>
                </div>
                <div class="card-body">

                    <!-- Großes Donut-Display -->
                    <div class="text-center mb-4">
                        <div class="position-relative d-inline-block" style="width: 150px; height: 150px;">
                            <canvas id="auslastungDonut" width="150" height="150"></canvas>
                            <div class="position-absolute top-50 start-50 translate-middle text-center">
                                <div class="fw-bold fs-4 lh-1"><?= $auslastung['prozent'] ?>%</div>
                                <div class="text-muted" style="font-size: .75rem;">Auslastung</div>
                            </div>
                        </div>
                    </div>

                    <!-- Statistiken -->
                    <div class="row g-3 text-center">
                        <div class="col-4">
                            <div class="p-2 bg-light rounded">
                                <div class="fw-bold fs-5 text-primary"><?= $auslastung['gesamt'] ?></div>
                                <div class="small text-muted">Gesamt</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-light rounded">
                                <div class="fw-bold fs-5 text-danger"><?= $auslastung['belegt'] ?></div>
                                <div class="small text-muted">Belegt</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-light rounded">
                                <div class="fw-bold fs-5 text-success"><?= $auslastung['frei'] ?></div>
                                <div class="small text-muted">Frei</div>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <!-- Potentieller Maximalumsatz -->
                    <?php
                    $potenzialUmsatz = $auslastung['gesamt'] * TICKET_PREIS;
                    $ausschoepfung   = $potenzialUmsatz > 0
                        ? round($gesamtUmsatz / $potenzialUmsatz * 100, 1)
                        : 0;
                    ?>
                    <div class="small">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Umsatz-Ausschöpfung</span>
                            <strong><?= $ausschoepfung ?> %</strong>
                        </div>
                        <div class="progress mb-3" style="height: 8px;">
                            <div class="progress-bar bg-success"
                                 role="progressbar"
                                 style="width: <?= min($ausschoepfung, 100) ?>%">
                            </div>
                        </div>
                        <div class="d-flex justify-content-between text-muted">
                            <span>Ist-Umsatz</span>
                            <strong class="text-success"><?= formatBetrag($gesamtUmsatz) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between text-muted">
                            <span>Max. Umsatz</span>
                            <strong><?= formatBetrag($potenzialUmsatz) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between text-muted">
                            <span>Ticketpreis</span>
                            <strong><?= formatBetrag(TICKET_PREIS) ?></strong>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div><!-- /Detailtabelle + Auslastung -->

    <?php endif; // selectedEventId ?>

</main>

<?php
// ─── Chart.js Daten als JSON für das Inline-Script (Donut-Werte vorab) ───────
$donutBelegt  = (int)($auslastung['belegt']  ?? 0);
$donutFrei    = (int)($auslastung['frei']    ?? 0);
$donutProzent = (int)($auslastung['prozent'] ?? 0);

// ─── extraScripts via ob_start ────────────────────────────────────────────────
$extraScripts = '';
if ($selectedEventId) {
    ob_start();
    ?>
<script>
(function () {
    'use strict';

    // Globale Chart-Defaults
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color       = '#6c757d';

    // ── Pie Chart: Zahlungsart-Verteilung ────────────────────────────────
    var pieCtx = document.getElementById('pieChart');
    if (pieCtx) {
        var pieLabels = <?= $jsonPieLabels ?>;
        var pieData   = <?= $jsonPieData ?>;
        var pieColors = <?= $jsonPieColors ?>;

        if (pieData.length > 0) {
            new Chart(pieCtx, {
                type: 'doughnut',
                data: {
                    labels: pieLabels,
                    datasets: [{
                        data:            pieData,
                        backgroundColor: pieColors,
                        borderColor:     '#fff',
                        borderWidth:     3,
                        hoverOffset:     8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '60%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { padding: 15, usePointStyle: true }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    var total = ctx.dataset.data.reduce(function(a,b){return a+b;}, 0);
                                    var pct   = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                                    return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    // ── Bar Chart: Umsatz nach Zahlungsart ──────────────────────────────
    var barCtx = document.getElementById('barChart');
    if (barCtx) {
        var barLabels = <?= $jsonPieLabels ?>;
        var barData   = <?= $jsonBarData ?>;
        var barColors = <?= $jsonPieColors ?>;

        if (barData.length > 0) {
            new Chart(barCtx, {
                type: 'bar',
                data: {
                    labels: barLabels,
                    datasets: [{
                        label:           'Umsatz (€)',
                        data:            barData,
                        backgroundColor: barColors.map(function(c){return c + 'cc';}),
                        borderColor:     barColors,
                        borderWidth:     2,
                        borderRadius:    6,
                        borderSkipped:   false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    return ' ' + ctx.dataset.label + ': ' +
                                        new Intl.NumberFormat('de-DE', {
                                            style: 'currency', currency: 'EUR'
                                        }).format(ctx.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(val) {
                                    return new Intl.NumberFormat('de-DE', {
                                        style: 'currency', currency: 'EUR',
                                        maximumFractionDigits: 0
                                    }).format(val);
                                }
                            },
                            grid: { color: 'rgba(0,0,0,0.05)' }
                        },
                        x: { grid: { display: false } }
                    }
                }
            });
        }
    }

    // ── Line Chart: Reservierungen über Zeit ────────────────────────────
    var lineCtx = document.getElementById('lineChart');
    if (lineCtx) {
        var lineLabels = <?= $jsonLineLabels ?>;
        var lineKum    = <?= $jsonLineKum ?>;
        var lineNeu    = <?= $jsonLineNeu ?>;

        if (lineLabels.length > 0) {
            new Chart(lineCtx, {
                type: 'line',
                data: {
                    labels: lineLabels,
                    datasets: [
                        {
                            label:           'Kumulativ',
                            data:            lineKum,
                            borderColor:     '#0d6efd',
                            backgroundColor: 'rgba(13, 110, 253, 0.08)',
                            borderWidth:     2,
                            pointRadius:     4,
                            pointHoverRadius: 6,
                            fill:            true,
                            tension:         0.3,
                            yAxisID:         'y'
                        },
                        {
                            label:           'Neu je Tag',
                            data:            lineNeu,
                            borderColor:     '#ffc107',
                            backgroundColor: 'rgba(255, 193, 7, 0.15)',
                            borderWidth:     2,
                            borderDash:      [6, 3],
                            pointRadius:     4,
                            pointHoverRadius: 6,
                            fill:            false,
                            tension:         0.3,
                            yAxisID:         'y2'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { usePointStyle: true, padding: 15 }
                        }
                    },
                    scales: {
                        y: {
                            type: 'linear', position: 'left', beginAtZero: true,
                            title: { display: true, text: 'Kumulativ' },
                            ticks: { stepSize: 1, precision: 0 },
                            grid:  { color: 'rgba(0,0,0,0.05)' }
                        },
                        y2: {
                            type: 'linear', position: 'right', beginAtZero: true,
                            title: { display: true, text: 'Neu je Tag' },
                            ticks: { stepSize: 1, precision: 0 },
                            grid:  { drawOnChartArea: false }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { maxRotation: 45, minRotation: 0 }
                        }
                    }
                }
            });
        }
    }

    // ── Auslastungs-Donut ────────────────────────────────────────────────
    var donutCtx = document.getElementById('auslastungDonut');
    if (donutCtx) {
        var belegt  = <?= $donutBelegt ?>;
        var frei    = <?= $donutFrei ?>;
        var prozent = <?= $donutProzent ?>;

        var donutColor = prozent >= 90 ? '#dc3545'
                       : prozent >= 70 ? '#ffc107'
                       : '#198754';

        new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: ['Belegt', 'Frei'],
                datasets: [{
                    data:            [belegt, frei > 0 ? frei : 0],
                    backgroundColor: [donutColor, '#e9ecef'],
                    borderColor:     '#fff',
                    borderWidth:     3,
                    hoverOffset:     4
                }]
            },
            options: {
                responsive:          false,
                maintainAspectRatio: false,
                cutout:              '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce(function(a,b){return a+b;}, 0);
                                var pct   = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                                return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

})();
</script>
    <?php
    $extraScripts = ob_get_clean();
}

include __DIR__ . '/../includes/footer.php';

<?php
/**
 * Admin Statistiken
 * Erweiterte Auswertungen mit Chart.js-Diagrammen und Tabellen.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

requireRole('admin');

$pdo = getDB();

// ═══════════════════════════════════════════════════════════════════════════════
// Globale KPIs
// ═══════════════════════════════════════════════════════════════════════════════

$gesamtumsatz = (float)$pdo->query(
    "SELECT COALESCE(SUM(betrag), 0) FROM payments WHERE status='bezahlt'"
)->fetchColumn();

$gesamtReservierungen = (int)$pdo->query(
    "SELECT COUNT(*) FROM reservations"
)->fetchColumn();

$anzahlEvents = (int)$pdo->query(
    "SELECT COUNT(*) FROM events"
)->fetchColumn();

$avgProEvent = $anzahlEvents > 0
    ? round($gesamtReservierungen / $anzahlEvents, 1)
    : 0;

$openPayments = (float)$pdo->query(
    "SELECT COALESCE(SUM(betrag), 0) FROM payments WHERE status='offen'"
)->fetchColumn();

// Top-Events nach Umsatz
$topEvents = $pdo->query(
    "SELECT e.name, e.datum, e.status,
            COUNT(r.id) AS reservierungen,
            COALESCE(SUM(p.betrag), 0) AS umsatz
     FROM events e
     LEFT JOIN reservations r ON r.event_id = e.id
     LEFT JOIN payments p ON p.reservation_id = r.id AND p.status='bezahlt'
     GROUP BY e.id
     ORDER BY umsatz DESC
     LIMIT 5"
)->fetchAll();

// ═══════════════════════════════════════════════════════════════════════════════
// Chart 1: Umsatz pro Monat (letzte 12 Monate)
// ═══════════════════════════════════════════════════════════════════════════════
$revenueLabels = [];
$revenueData   = [];
for ($i = 11; $i >= 0; $i--) {
    $ts    = strtotime("-{$i} months");
    $year  = date('Y', $ts);
    $month = date('m', $ts);
    $revenueLabels[] = date('M Y', $ts);

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(p.betrag), 0)
         FROM payments p
         WHERE p.status='bezahlt'
           AND YEAR(p.erstellt_am) = ?
           AND MONTH(p.erstellt_am) = ?"
    );
    $stmt->execute([$year, $month]);
    $revenueData[] = round((float)$stmt->fetchColumn(), 2);
}

// ═══════════════════════════════════════════════════════════════════════════════
// Chart 2: Reservierungen nach Zahlungsart (Pie)
// ═══════════════════════════════════════════════════════════════════════════════
$payMethodData = $pdo->query(
    "SELECT p.zahlungsart, COUNT(*) AS anzahl
     FROM payments p
     GROUP BY p.zahlungsart
     ORDER BY anzahl DESC"
)->fetchAll();

$payLabels  = [];
$payCounts  = [];
$payColors  = ['bar' => '#198754', 'ueberweisung' => '#0d6efd', 'paypal' => '#0dcaf0'];
foreach ($payMethodData as $pm) {
    $payLabels[] = zahlungsartLabel($pm['zahlungsart']);
    $payCounts[] = (int)$pm['anzahl'];
}
$payBgColors = array_map(fn($pm) => $payColors[$pm['zahlungsart']] ?? '#6c757d', $payMethodData);

// ═══════════════════════════════════════════════════════════════════════════════
// Chart 3: Auslastung Events (Bar)
// ═══════════════════════════════════════════════════════════════════════════════
$eventAuslastung = $pdo->query(
    "SELECT e.id, e.name, e.datum,
            COUNT(DISTINCT s.id) AS sitze_gesamt,
            SUM(CASE WHEN s.status != 'verfuegbar' THEN 1 ELSE 0 END) AS sitze_belegt
     FROM events e
     LEFT JOIN tables t ON t.event_id = e.id
     LEFT JOIN seats s ON s.table_id = t.id
     GROUP BY e.id
     ORDER BY e.datum DESC
     LIMIT 10"
)->fetchAll();

$auslastungLabels = [];
$auslastungPct    = [];
$auslastungGesamt = [];
foreach ($eventAuslastung as $ev) {
    $auslastungLabels[] = mb_substr($ev['name'], 0, 25) . (mb_strlen($ev['name']) > 25 ? '…' : '');
    $pct = $ev['sitze_gesamt'] > 0
        ? round(($ev['sitze_belegt'] / $ev['sitze_gesamt']) * 100, 1)
        : 0;
    $auslastungPct[]    = $pct;
    $auslastungGesamt[] = (int)$ev['sitze_gesamt'];
}

// ═══════════════════════════════════════════════════════════════════════════════
// Tabelle: Aufschlüsselung pro Event
// ═══════════════════════════════════════════════════════════════════════════════
$eventBreakdown = $pdo->query(
    "SELECT e.id, e.name, e.datum, e.status,
            COUNT(DISTINCT r.id) AS reservierungen,
            COUNT(DISTINCT s.id) AS sitze_gesamt,
            SUM(CASE WHEN s.status != 'verfuegbar' THEN 1 ELSE 0 END) AS sitze_belegt,
            COALESCE(SUM(CASE WHEN p.status='bezahlt' THEN p.betrag ELSE 0 END), 0) AS umsatz_bezahlt,
            COALESCE(SUM(CASE WHEN p.status='offen'   THEN p.betrag ELSE 0 END), 0) AS umsatz_offen
     FROM events e
     LEFT JOIN tables t ON t.event_id = e.id
     LEFT JOIN seats s ON s.table_id = t.id
     LEFT JOIN reservations r ON r.event_id = e.id
     LEFT JOIN payments p ON p.reservation_id = r.id
     GROUP BY e.id
     ORDER BY e.datum DESC"
)->fetchAll();

// ═══════════════════════════════════════════════════════════════════════════════
// Zahlungsart-Umsatz-Aufschlüsselung
// ═══════════════════════════════════════════════════════════════════════════════
$zahlungsartBreakdown = $pdo->query(
    "SELECT u.zahlungsart,
            COUNT(r.id) AS reservierungen,
            COALESCE(SUM(p.betrag), 0) AS umsatz
     FROM users u
     JOIN reservations r ON r.user_id = u.id
     JOIN payments p ON p.reservation_id = r.id AND p.status='bezahlt'
     GROUP BY u.zahlungsart
     ORDER BY umsatz DESC"
)->fetchAll();

// ═══════════════════════════════════════════════════════════════════════════════
// Rollenbasierter Umsatz
// ═══════════════════════════════════════════════════════════════════════════════
$rollenBreakdown = $pdo->query(
    "SELECT u.rolle,
            COUNT(r.id) AS reservierungen,
            COALESCE(SUM(p.betrag), 0) AS umsatz
     FROM users u
     JOIN reservations r ON r.user_id = u.id
     JOIN payments p ON p.reservation_id = r.id AND p.status='bezahlt'
     GROUP BY u.rolle
     ORDER BY umsatz DESC"
)->fetchAll();

$pageTitle  = 'Admin – Statistiken';
$bodyClass  = 'bg-light';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container-fluid py-4">

    <!-- Seitentitel -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-graph-up-arrow text-success me-2"></i>Statistiken & Auswertungen
            </h1>
            <p class="text-muted mb-0 small">Systemweite Analysen, Umsätze und Event-Auslastungen</p>
        </div>
        <a href="/pages/admin_dashboard.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
    </div>

    <?= getFlash() ?>

    <!-- ═══ Globale KPIs ════════════════════════════════════════════════════════ -->
    <div class="row g-3 mb-4">

        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-success border-4">
                <div class="card-body">
                    <p class="text-muted small mb-1"><i class="bi bi-cash-stack me-1"></i>Gesamtumsatz</p>
                    <h3 class="fw-bold text-success mb-0"><?= formatBetrag($gesamtumsatz) ?></h3>
                    <small class="text-muted">aus bezahlten Tickets</small>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
                <div class="card-body">
                    <p class="text-muted small mb-1"><i class="bi bi-ticket-perforated me-1"></i>Reservierungen</p>
                    <h3 class="fw-bold text-primary mb-0"><?= $gesamtReservierungen ?></h3>
                    <small class="text-muted">Ø <?= $avgProEvent ?> pro Event</small>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-warning border-4">
                <div class="card-body">
                    <p class="text-muted small mb-1"><i class="bi bi-hourglass-split me-1"></i>Offene Zahlungen</p>
                    <h3 class="fw-bold text-warning mb-0"><?= formatBetrag($openPayments) ?></h3>
                    <small class="text-muted">noch nicht bezahlt</small>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-info border-4">
                <div class="card-body">
                    <p class="text-muted small mb-1"><i class="bi bi-calendar-event me-1"></i>Events gesamt</p>
                    <h3 class="fw-bold text-info mb-0"><?= $anzahlEvents ?></h3>
                    <small class="text-muted">alle Status</small>
                </div>
            </div>
        </div>

    </div>

    <!-- ═══ Charts Zeile 1 ══════════════════════════════════════════════════════ -->
    <div class="row g-4 mb-4">

        <!-- Chart: Umsatz letzte 12 Monate -->
        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-bar-chart-line me-2 text-success"></i>Umsatz (letzte 12 Monate)
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="revenueChart" style="max-height:300px;"></canvas>
                </div>
            </div>
        </div>

        <!-- Chart: Zahlungsart Pie -->
        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-pie-chart me-2 text-info"></i>Zahlungsarten
                    </h5>
                </div>
                <div class="card-body d-flex flex-column align-items-center">
                    <canvas id="paymentChart" style="max-height:260px; max-width:260px;"></canvas>
                    <div class="mt-3 d-flex gap-3 flex-wrap justify-content-center">
                        <?php foreach ($payMethodData as $i => $pm): ?>
                        <div class="d-flex align-items-center gap-1">
                            <span class="rounded-circle d-inline-block"
                                  style="width:12px;height:12px;background:<?= $payColors[$pm['zahlungsart']] ?? '#6c757d' ?>"></span>
                            <small><?= zahlungsartLabel($pm['zahlungsart']) ?>: <?= $pm['anzahl'] ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ═══ Chart: Event-Auslastung ═════════════════════════════════════════════ -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom">
            <h5 class="mb-0 fw-semibold">
                <i class="bi bi-bar-chart me-2 text-primary"></i>Event-Auslastung im Vergleich (Top 10)
            </h5>
        </div>
        <div class="card-body">
            <canvas id="auslastungChart" style="max-height:320px;"></canvas>
        </div>
    </div>

    <!-- ═══ Top-Events ══════════════════════════════════════════════════════════ -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-trophy me-2 text-warning"></i>Top Events nach Umsatz
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Event</th>
                                    <th>Datum</th>
                                    <th>Res.</th>
                                    <th>Umsatz</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($topEvents as $i => $ev): ?>
                            <tr>
                                <td>
                                    <?php if ($i === 0): ?>
                                    <i class="bi bi-trophy-fill text-warning"></i>
                                    <?php elseif ($i === 1): ?>
                                    <i class="bi bi-trophy-fill text-secondary"></i>
                                    <?php elseif ($i === 2): ?>
                                    <i class="bi bi-trophy-fill text-danger" style="opacity:.6"></i>
                                    <?php else: ?>
                                    <span class="text-muted small"><?= $i + 1 ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold small"><?= htmlspecialchars($ev['name']) ?></div>
                                </td>
                                <td><small class="text-muted"><?= formatDatum($ev['datum']) ?></small></td>
                                <td><span class="badge bg-primary bg-opacity-75"><?= (int)$ev['reservierungen'] ?></span></td>
                                <td><span class="fw-semibold text-success"><?= formatBetrag($ev['umsatz']) ?></span></td>
                                <td><?= statusBadge($ev['status']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($topEvents)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">Keine Daten</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-credit-card-2-front me-2 text-info"></i>Umsatz nach Zahlungsart & Benutzertyp
                    </h5>
                </div>
                <div class="card-body">
                    <h6 class="fw-semibold text-muted mb-3 small text-uppercase">Nach Zahlungsart der Benutzer</h6>
                    <?php if (empty($zahlungsartBreakdown)): ?>
                    <p class="text-muted small">Keine Daten vorhanden.</p>
                    <?php else: ?>
                    <?php
                    $maxZUmsatz = max(array_column($zahlungsartBreakdown, 'umsatz') ?: [1]);
                    foreach ($zahlungsartBreakdown as $z):
                        $pct = $maxZUmsatz > 0 ? round(($z['umsatz'] / $maxZUmsatz) * 100) : 0;
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small fw-semibold"><?= zahlungsartLabel($z['zahlungsart']) ?></span>
                            <span class="small">
                                <strong class="text-success"><?= formatBetrag($z['umsatz']) ?></strong>
                                <span class="text-muted ms-1">(<?= $z['reservierungen'] ?> Res.)</span>
                            </span>
                        </div>
                        <div class="progress" style="height:8px;">
                            <div class="progress-bar bg-info" style="width:<?= $pct ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <hr>

                    <h6 class="fw-semibold text-muted mb-3 small text-uppercase">Nach Benutzerrolle</h6>
                    <?php if (empty($rollenBreakdown)): ?>
                    <p class="text-muted small">Keine Daten vorhanden.</p>
                    <?php else: ?>
                    <?php
                    $maxRUmsatz = max(array_column($rollenBreakdown, 'umsatz') ?: [1]);
                    $rolleColors = ['admin' => 'bg-danger', 'kassierer' => 'bg-warning', 'user' => 'bg-primary'];
                    foreach ($rollenBreakdown as $r):
                        $pct = $maxRUmsatz > 0 ? round(($r['umsatz'] / $maxRUmsatz) * 100) : 0;
                        $rolleLabel = match($r['rolle']) { 'admin'=>'Admin','kassierer'=>'Kassierer', default=>'Benutzer' };
                        $barColor = $rolleColors[$r['rolle']] ?? 'bg-secondary';
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small fw-semibold"><?= $rolleLabel ?></span>
                            <span class="small">
                                <strong class="text-success"><?= formatBetrag($r['umsatz']) ?></strong>
                                <span class="text-muted ms-1">(<?= $r['reservierungen'] ?> Res.)</span>
                            </span>
                        </div>
                        <div class="progress" style="height:8px;">
                            <div class="progress-bar <?= $barColor ?>" style="width:<?= $pct ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Event-Aufschlüsselungs-Tabelle ══════════════════════════════════════ -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom d-flex align-items-center">
            <h5 class="mb-0 fw-semibold">
                <i class="bi bi-table me-2 text-secondary"></i>Detailansicht pro Event
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Event</th>
                            <th>Datum</th>
                            <th>Sitze</th>
                            <th>Belegt</th>
                            <th>Auslastung</th>
                            <th>Reservierungen</th>
                            <th>Umsatz (bez.)</th>
                            <th>Offen</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($eventBreakdown)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Keine Events vorhanden.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($eventBreakdown as $ev):
                        $pct = $ev['sitze_gesamt'] > 0
                            ? round(($ev['sitze_belegt'] / $ev['sitze_gesamt']) * 100) : 0;
                        $barCls = $pct >= 90 ? 'bg-danger' : ($pct >= 60 ? 'bg-warning' : 'bg-success');
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold small"><?= htmlspecialchars($ev['name']) ?></div>
                        </td>
                        <td><small><?= formatDatum($ev['datum']) ?></small></td>
                        <td><?= (int)$ev['sitze_gesamt'] ?></td>
                        <td><?= (int)$ev['sitze_belegt'] ?></td>
                        <td style="min-width:140px;">
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height:6px;">
                                    <div class="progress-bar <?= $barCls ?>"
                                         style="width:<?= $pct ?>%"></div>
                                </div>
                                <small class="text-nowrap fw-semibold"><?= $pct ?>%</small>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-primary bg-opacity-75"><?= (int)$ev['reservierungen'] ?></span>
                        </td>
                        <td><span class="fw-semibold text-success"><?= formatBetrag($ev['umsatz_bezahlt']) ?></span></td>
                        <td>
                            <?php if ($ev['umsatz_offen'] > 0): ?>
                            <span class="text-warning fw-semibold"><?= formatBetrag($ev['umsatz_offen']) ?></span>
                            <?php else: ?>
                            <span class="text-muted small">–</span>
                            <?php endif; ?>
                        </td>
                        <td><?= statusBadge($ev['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($eventBreakdown)):
                        $sumRes    = array_sum(array_column($eventBreakdown, 'reservierungen'));
                        $sumBez    = array_sum(array_column($eventBreakdown, 'umsatz_bezahlt'));
                        $sumOffen  = array_sum(array_column($eventBreakdown, 'umsatz_offen'));
                        $sumSitze  = array_sum(array_column($eventBreakdown, 'sitze_gesamt'));
                        $sumBelegt = array_sum(array_column($eventBreakdown, 'sitze_belegt'));
                        $totalPct  = $sumSitze > 0 ? round(($sumBelegt / $sumSitze) * 100) : 0;
                    ?>
                    <tfoot class="table-dark">
                        <tr>
                            <th colspan="2">Gesamt</th>
                            <th><?= $sumSitze ?></th>
                            <th><?= $sumBelegt ?></th>
                            <th><?= $totalPct ?>%</th>
                            <th><?= $sumRes ?></th>
                            <th class="text-success"><?= formatBetrag($sumBez) ?></th>
                            <th class="text-warning"><?= formatBetrag($sumOffen) ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

</main>

<?php
$extraScripts = '<script>
(function() {

    // Chart 1: Umsatz letzte 12 Monate
    const revenueCtx = document.getElementById("revenueChart");
    if (revenueCtx) {
        new Chart(revenueCtx, {
            type: "bar",
            data: {
                labels: ' . json_encode($revenueLabels) . ',
                datasets: [{
                    label: "Umsatz (€)",
                    data: ' . json_encode($revenueData) . ',
                    backgroundColor: "rgba(25,135,84,0.7)",
                    borderColor: "rgba(25,135,84,1)",
                    borderWidth: 1,
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => "€ " + ctx.parsed.y.toFixed(2).replace(".", ",")
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: v => "€" + v } }
                }
            }
        });
    }

    // Chart 2: Zahlungsart Pie
    const payCtx = document.getElementById("paymentChart");
    if (payCtx) {
        new Chart(payCtx, {
            type: "doughnut",
            data: {
                labels: ' . json_encode($payLabels) . ',
                datasets: [{
                    data: ' . json_encode($payCounts) . ',
                    backgroundColor: ' . json_encode(array_values($payBgColors)) . ',
                    borderWidth: 2,
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ctx.label + ": " + ctx.parsed + " Reservierungen"
                        }
                    }
                },
                cutout: "60%"
            }
        });
    }

    // Chart 3: Auslastung
    const auslCtx = document.getElementById("auslastungChart");
    if (auslCtx) {
        const labels = ' . json_encode($auslastungLabels) . ';
        const pcts   = ' . json_encode($auslastungPct) . ';
        const bgColors = pcts.map(p => p >= 90 ? "rgba(220,53,69,0.75)" :
                                       p >= 60 ? "rgba(255,193,7,0.75)" :
                                                 "rgba(25,135,84,0.75)");
        new Chart(auslCtx, {
            type: "bar",
            data: {
                labels: labels,
                datasets: [{
                    label: "Auslastung %",
                    data: pcts,
                    backgroundColor: bgColors,
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                indexAxis: "y",
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: { label: ctx => ctx.parsed.x + "% belegt" }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { callback: v => v + "%" }
                    }
                }
            }
        });
    }

})();
</script>';
include __DIR__ . '/../includes/footer.php';
?>

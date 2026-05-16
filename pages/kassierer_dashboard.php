<?php
/**
 * Kassierer Dashboard
 * Übersicht über KPIs, heutige Reservierungen und schnelle Check-in-Funktionen.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

session_start();
requireRole('kassierer', 'admin');

$pdo    = getDB();
$today  = date('Y-m-d');
$errors = [];

// ─── POST-Handler: Schnell-Check-in ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'checkin') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiger Sicherheitstoken.');
    } else {
        $resId = (int)($_POST['reservation_id'] ?? 0);
        if ($resId > 0) {
            try {
                $pdo->beginTransaction();

                // Reservierung laden
                $stmtRes = $pdo->prepare(
                    'SELECT r.id, r.seat_id, r.status, r.buchungsnummer,
                            u.vorname, u.nachname
                     FROM reservations r
                     INNER JOIN users u ON u.id = r.user_id
                     WHERE r.id = ?'
                );
                $stmtRes->execute([$resId]);
                $res = $stmtRes->fetch();

                if (!$res) {
                    throw new RuntimeException('Reservierung nicht gefunden.');
                }
                if ($res['status'] === 'eingecheckt') {
                    throw new RuntimeException('Gast ist bereits eingecheckt.');
                }

                // Reservierungsstatus setzen
                $pdo->prepare('UPDATE reservations SET status = ? WHERE id = ?')
                    ->execute(['eingecheckt', $resId]);

                // Sitzplatz auf besetzt setzen
                $pdo->prepare('UPDATE seats SET status = ? WHERE id = ?')
                    ->execute(['besetzt', $res['seat_id']]);

                $pdo->commit();

                logAudit(
                    'CHECK_IN',
                    'reservations',
                    $resId,
                    json_encode([
                        'buchungsnummer' => $res['buchungsnummer'],
                        'gast'           => $res['vorname'] . ' ' . $res['nachname'],
                    ])
                );
                setFlash('success', 'Check-in für ' . htmlspecialchars($res['vorname'] . ' ' . $res['nachname']) . ' erfolgreich.');
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('error', 'Fehler beim Check-in: ' . htmlspecialchars($e->getMessage()));
            }
        }
    }

    // Nach POST zurück zur selben Seite (PRG-Pattern)
    $eid = (int)($_POST['event_id'] ?? 0);
    redirect('/pages/kassierer_dashboard.php' . ($eid ? '?event_id=' . $eid : ''));
}

// ─── Event-Selektor ──────────────────────────────────────────────────────────
$events = $pdo->query(
    "SELECT id, name, datum, status
     FROM events
     WHERE status IN ('aktiv','planung')
     ORDER BY datum ASC"
)->fetchAll();

$selectedEventId = (int)($_GET['event_id'] ?? ($events[0]['id'] ?? 0));

// Aktuell gewähltes Event
$currentEvent = null;
if ($selectedEventId) {
    $stmtEv = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $stmtEv->execute([$selectedEventId]);
    $currentEvent = $stmtEv->fetch();
}

// ─── KPI 1: Umsatz heute ─────────────────────────────────────────────────────
$stmtUmsatz = $pdo->prepare(
    "SELECT COALESCE(SUM(p.betrag), 0) AS umsatz
     FROM payments p
     WHERE p.status = 'bezahlt'
       AND DATE(p.erstellt_am) = ?"
);
$stmtUmsatz->execute([$today]);
$umsatzHeute = (float)$stmtUmsatz->fetchColumn();

// ─── KPI 2: Offene Zahlungen ─────────────────────────────────────────────────
$offeneZahlungen = (int)$pdo->query(
    "SELECT COUNT(*) FROM payments WHERE status = 'offen'"
)->fetchColumn();

// ─── KPI 3: Check-ins heute ──────────────────────────────────────────────────
$stmtCI = $pdo->prepare(
    "SELECT COUNT(*) FROM reservations
     WHERE status = 'eingecheckt'
       AND DATE(erstellt_am) = ?"
);
$stmtCI->execute([$today]);
// Check-ins werden anhand des Audit-Logs gezählt (genauer)
$stmtCIAudit = $pdo->prepare(
    "SELECT COUNT(*) FROM audit_log
     WHERE aktion = 'CHECK_IN'
       AND DATE(zeitstempel) = ?"
);
$stmtCIAudit->execute([$today]);
$checkInsHeute = (int)$stmtCIAudit->fetchColumn();

// ─── KPI 4: Auslastung nächste Events ────────────────────────────────────────
$auslastungData = [];
foreach ($events as $ev) {
    $auslastungData[$ev['id']] = getEventAuslastung((int)$ev['id']);
}

// Durchschnittliche Auslastung über alle aktiven Events
$avgAuslastung = 0;
if (!empty($auslastungData)) {
    $sumProzent = array_sum(array_column($auslastungData, 'prozent'));
    $avgAuslastung = round($sumProzent / count($auslastungData));
}

// ─── Reservierungen des gewählten Events ─────────────────────────────────────
$reservierungen = [];
if ($selectedEventId) {
    $stmtR = $pdo->prepare(
        "SELECT
            r.id,
            r.buchungsnummer,
            r.status        AS res_status,
            r.preis,
            r.erstellt_am,
            u.vorname,
            u.nachname,
            u.email,
            e.name          AS event_name,
            e.datum         AS event_datum,
            t.tischnummer,
            s.sitzplatznummer,
            p.status        AS zahl_status,
            p.zahlungsart,
            p.betrag,
            p.id            AS payment_id
         FROM reservations r
         INNER JOIN users       u ON u.id = r.user_id
         INNER JOIN events      e ON e.id = r.event_id
         INNER JOIN seats       s ON s.id = r.seat_id
         INNER JOIN tables      t ON t.id = s.table_id
         LEFT  JOIN payments    p ON p.reservation_id = r.id
         WHERE r.event_id = ?
         ORDER BY r.erstellt_am DESC"
    );
    $stmtR->execute([$selectedEventId]);
    $reservierungen = $stmtR->fetchAll();
}

// ─── Letzte 10 Audit-Log-Einträge ────────────────────────────────────────────
$auditLog = $pdo->query(
    "SELECT a.*, u.vorname, u.nachname
     FROM audit_log a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.zeitstempel DESC
     LIMIT 10"
)->fetchAll();

// ─── Seiten-Ausgabe ──────────────────────────────────────────────────────────
$pageTitle = 'Kassierer Dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container-fluid py-4">

    <!-- Seitentitel -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-speedometer2 text-warning me-2"></i>Kassierer Dashboard
            </h1>
            <p class="text-muted mb-0 small">Übersicht &amp; Schnell-Aktionen – <?= date('d.m.Y') ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="/pages/kassierer_guestlist.php<?= $selectedEventId ? '?event_id=' . $selectedEventId : '' ?>"
               class="btn btn-outline-primary btn-sm">
                <i class="bi bi-people me-1"></i>Gästeliste
            </a>
            <a href="/pages/kassierer_statistiken.php<?= $selectedEventId ? '?event_id=' . $selectedEventId : '' ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-bar-chart me-1"></i>Statistiken
            </a>
        </div>
    </div>

    <?= getFlash() ?>

    <!-- ── KPI-Karten ─────────────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">

        <!-- Umsatz heute -->
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-muted small mb-1">Umsatz heute</p>
                            <h4 class="fw-bold mb-0 text-success"><?= formatBetrag($umsatzHeute) ?></h4>
                        </div>
                        <div class="bg-success bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-cash-stack fs-4 text-success"></i>
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
                        </div>
                        <div class="bg-warning bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-hourglass-split fs-4 text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Check-ins heute -->
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-muted small mb-1">Check-ins heute</p>
                            <h4 class="fw-bold mb-0 text-primary"><?= $checkInsHeute ?></h4>
                        </div>
                        <div class="bg-primary bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-person-check fs-4 text-primary"></i>
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
                            <p class="text-muted small mb-1">Ø Auslastung Events</p>
                            <h4 class="fw-bold mb-0 text-info"><?= $avgAuslastung ?> %</h4>
                        </div>
                        <div class="bg-info bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-pie-chart fs-4 text-info"></i>
                        </div>
                    </div>
                    <div class="progress mt-2" style="height:4px;">
                        <div class="progress-bar bg-info"
                             role="progressbar"
                             style="width:<?= $avgAuslastung ?>%"
                             aria-valuenow="<?= $avgAuslastung ?>"
                             aria-valuemin="0"
                             aria-valuemax="100">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Auslastung je Event (Kurzübersicht) ────────────────────────────── -->
    <?php if (!empty($events)): ?>
    <div class="row g-3 mb-4">
        <?php foreach ($events as $ev): ?>
        <?php $aul = $auslastungData[$ev['id']] ?? ['prozent' => 0, 'belegt' => 0, 'gesamt' => 0]; ?>
        <div class="col-12 col-sm-6 col-xl-4">
            <div class="card border-0 shadow-sm <?= $ev['id'] == $selectedEventId ? 'border border-warning border-2' : '' ?>">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="fw-semibold small text-truncate me-2">
                            <?= htmlspecialchars($ev['name']) ?>
                        </span>
                        <small class="text-muted text-nowrap"><?= formatDatum($ev['datum']) ?></small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height:8px;">
                            <?php
                            $barColor = $aul['prozent'] >= 90 ? 'bg-danger'
                                      : ($aul['prozent'] >= 70 ? 'bg-warning' : 'bg-success');
                            ?>
                            <div class="progress-bar <?= $barColor ?>"
                                 role="progressbar"
                                 style="width:<?= $aul['prozent'] ?>%"
                                 aria-valuenow="<?= $aul['prozent'] ?>"
                                 aria-valuemin="0"
                                 aria-valuemax="100">
                            </div>
                        </div>
                        <small class="fw-bold text-nowrap"><?= $aul['prozent'] ?> %</small>
                        <small class="text-muted text-nowrap">(<?= $aul['belegt'] ?>/<?= $aul['gesamt'] ?>)</small>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Event-Selektor + Reservierungen ───────────────────────────────── -->
    <div class="row g-4">
        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <h5 class="mb-0 fw-semibold">
                            <i class="bi bi-ticket-perforated me-2 text-warning"></i>Reservierungen
                        </h5>
                        <!-- Event-Selektor -->
                        <form method="GET" action="" class="d-flex align-items-center gap-2">
                            <label for="event_id" class="form-label mb-0 small fw-semibold text-nowrap">Event:</label>
                            <select name="event_id" id="event_id"
                                    class="form-select form-select-sm"
                                    style="min-width:200px;"
                                    onchange="this.form.submit()">
                                <?php foreach ($events as $ev): ?>
                                <option value="<?= (int)$ev['id'] ?>"
                                    <?= $ev['id'] == $selectedEventId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ev['name']) ?> (<?= formatDatum($ev['datum']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </div>

                <div class="card-body p-0">
                    <?php if (empty($reservierungen)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        Keine Reservierungen für dieses Event gefunden.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Buchungsnr.</th>
                                    <th>Gast</th>
                                    <th>Sitz</th>
                                    <th>Zahlung</th>
                                    <th>Status</th>
                                    <th class="text-end pe-3">Aktion</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($reservierungen as $r): ?>
                            <tr class="<?= $r['res_status'] === 'eingecheckt' ? 'table-success' : '' ?>">
                                <td class="ps-3">
                                    <span class="font-monospace fw-semibold text-nowrap">
                                        <?= htmlspecialchars($r['buchungsnummer']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-semibold">
                                        <?= htmlspecialchars($r['vorname'] . ' ' . $r['nachname']) ?>
                                    </span>
                                    <br>
                                    <small class="text-muted"><?= htmlspecialchars($r['email']) ?></small>
                                </td>
                                <td class="text-nowrap">
                                    <i class="bi bi-grid me-1 text-muted"></i>
                                    T<?= (int)$r['tischnummer'] ?> / P<?= (int)$r['sitzplatznummer'] ?>
                                </td>
                                <td class="text-nowrap">
                                    <?php if ($r['zahl_status']): ?>
                                        <?= statusBadge($r['zahl_status']) ?>
                                        <br>
                                        <small class="text-muted"><?= zahlungsartLabel($r['zahlungsart'] ?? '') ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">–</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= statusBadge($r['res_status']) ?></td>
                                <td class="text-end pe-3">
                                    <?php if ($r['res_status'] !== 'eingecheckt'): ?>
                                    <form method="POST" action="" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action"         value="checkin">
                                        <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
                                        <input type="hidden" name="event_id"       value="<?= $selectedEventId ?>">
                                        <button type="submit"
                                                class="btn btn-sm btn-success"
                                                title="Check-in durchführen"
                                                onclick="return confirm('Check-in für <?= htmlspecialchars(addslashes($r['vorname'] . ' ' . $r['nachname'])) ?> bestätigen?')">
                                            <i class="bi bi-person-check-fill me-1"></i>Check-in
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <span class="text-success fw-semibold small">
                                        <i class="bi bi-check-circle-fill me-1"></i>Erledigt
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Zusammenfassung -->
                    <?php
                    $totalRes    = count($reservierungen);
                    $eingecheckt = count(array_filter($reservierungen, fn($r) => $r['res_status'] === 'eingecheckt'));
                    $gezahlt     = count(array_filter($reservierungen, fn($r) => $r['zahl_status'] === 'bezahlt'));
                    ?>
                    <div class="card-footer bg-light border-top">
                        <div class="d-flex flex-wrap gap-3 small text-muted">
                            <span><i class="bi bi-people me-1"></i><strong><?= $totalRes ?></strong> Reservierungen</span>
                            <span><i class="bi bi-person-check me-1 text-success"></i><strong><?= $eingecheckt ?></strong> eingecheckt</span>
                            <span><i class="bi bi-check-circle me-1 text-success"></i><strong><?= $gezahlt ?></strong> bezahlt</span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Letzte Aktivitäten (Audit-Log) ─────────────────────────────── -->
        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-clock-history me-2 text-secondary"></i>Letzte Aktivitäten
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($auditLog)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-journal-x fs-2 d-block mb-2"></i>
                        Noch keine Einträge vorhanden.
                    </div>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($auditLog as $log): ?>
                        <?php
                        $iconMap = [
                            'CHECK_IN'  => ['bi-person-check-fill', 'text-success'],
                            'BEZAHLT'   => ['bi-cash',              'text-success'],
                            'STORNIERT' => ['bi-x-circle',          'text-danger'],
                            'LOGIN'     => ['bi-box-arrow-in-right','text-primary'],
                            'LOGOUT'    => ['bi-box-arrow-right',   'text-secondary'],
                        ];
                        $ak = strtoupper($log['aktion']);
                        [$icon, $iconClass] = $iconMap[$ak] ?? ['bi-activity', 'text-muted'];
                        ?>
                        <li class="list-group-item px-3 py-2 border-0 border-bottom">
                            <div class="d-flex align-items-start gap-2">
                                <i class="bi <?= $icon ?> <?= $iconClass ?> mt-1 flex-shrink-0"></i>
                                <div class="overflow-hidden">
                                    <div class="fw-semibold small text-truncate">
                                        <?= htmlspecialchars($log['aktion']) ?>
                                    </div>
                                    <div class="text-muted" style="font-size:.75rem;">
                                        <?php if ($log['vorname']): ?>
                                            <?= htmlspecialchars($log['vorname'] . ' ' . $log['nachname']) ?> &bull;
                                        <?php endif; ?>
                                        <?= htmlspecialchars(
                                            (new DateTime($log['zeitstempel']))->format('d.m.Y H:i')
                                        ) ?>
                                    </div>
                                    <?php if ($log['aenderung']): ?>
                                    <?php $aenderungData = json_decode($log['aenderung'], true); ?>
                                    <?php if (is_array($aenderungData) && isset($aenderungData['buchungsnummer'])): ?>
                                    <small class="font-monospace text-muted">
                                        <?= htmlspecialchars($aenderungData['buchungsnummer']) ?>
                                    </small>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div><!-- /row -->

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

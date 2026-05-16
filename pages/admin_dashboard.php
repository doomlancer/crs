<?php
/**
 * Admin Dashboard
 * Übersicht mit KPIs, Aktivitätslog, Schnelllinks und Chart.js-Diagramm.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

requireRole('admin');

$pdo = getDB();

// ── KPIs ──────────────────────────────────────────────────────────────────────

// Gesamtanzahl Benutzer
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

// Aktive Events
$activeEvents = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE status = 'aktiv'")->fetchColumn();

// Gesamtreservierungen
$totalReservations = (int)$pdo->query("SELECT COUNT(*) FROM reservations")->fetchColumn();

// Gesamtumsatz (bezahlte Zahlungen)
$totalRevenue = (float)$pdo->query(
    "SELECT COALESCE(SUM(betrag), 0) FROM payments WHERE status = 'bezahlt'"
)->fetchColumn();

// ── Letzte 10 Audit-Log-Einträge ──────────────────────────────────────────────
$recentActivity = $pdo->query(
    "SELECT a.id, a.aktion, a.tabelle, a.datensatz_id, a.aenderung, a.ip_adresse, a.zeitstempel,
            u.vorname, u.nachname
     FROM audit_log a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.zeitstempel DESC
     LIMIT 10"
)->fetchAll();

// ── Chart-Daten: Registrierungen pro Monat (letzte 6 Monate) ─────────────────
$chartLabels = [];
$chartData   = [];
for ($i = 5; $i >= 0; $i--) {
    $ts    = strtotime("-{$i} months");
    $year  = date('Y', $ts);
    $month = date('m', $ts);
    $chartLabels[] = date('M Y', $ts);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM users
         WHERE YEAR(erstellt_am) = ? AND MONTH(erstellt_am) = ?"
    );
    $stmt->execute([$year, $month]);
    $chartData[] = (int)$stmt->fetchColumn();
}

// ── System-Status ──────────────────────────────────────────────────────────────
$dbStatus = 'OK';
try {
    $pdo->query("SELECT 1");
} catch (PDOException $e) {
    $dbStatus = 'Fehler';
}

$pageTitle  = 'Admin Dashboard';
$bodyClass  = 'bg-light';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container-fluid py-4">

    <!-- Seitentitel -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-speedometer2 text-danger me-2"></i>Admin Dashboard
            </h1>
            <p class="text-muted mb-0 small">Systemübersicht – <?= date('d.m.Y H:i') ?> Uhr</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="/pages/admin_events.php" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-calendar-plus me-1"></i>Events
            </a>
            <a href="/pages/admin_users.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-people-fill me-1"></i>Benutzer
            </a>
            <a href="/pages/admin_statistiken.php" class="btn btn-sm btn-outline-success">
                <i class="bi bi-graph-up me-1"></i>Statistiken
            </a>
            <a href="/pages/admin_auditlog.php" class="btn btn-sm btn-outline-warning">
                <i class="bi bi-shield-check me-1"></i>Audit-Log
            </a>
        </div>
    </div>

    <?= getFlash() ?>

    <!-- ── KPI-Karten ─────────────────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">

        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-muted small mb-1">Benutzer gesamt</p>
                            <h3 class="fw-bold mb-0 text-primary"><?= $totalUsers ?></h3>
                        </div>
                        <div class="bg-primary bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-people-fill fs-4 text-primary"></i>
                        </div>
                    </div>
                    <div class="mt-2">
                        <a href="/pages/admin_users.php" class="small text-decoration-none text-primary">
                            <i class="bi bi-arrow-right me-1"></i>Verwalten
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-muted small mb-1">Aktive Events</p>
                            <h3 class="fw-bold mb-0 text-success"><?= $activeEvents ?></h3>
                        </div>
                        <div class="bg-success bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-calendar-event fs-4 text-success"></i>
                        </div>
                    </div>
                    <div class="mt-2">
                        <a href="/pages/admin_events.php" class="small text-decoration-none text-success">
                            <i class="bi bi-arrow-right me-1"></i>Verwalten
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-muted small mb-1">Reservierungen</p>
                            <h3 class="fw-bold mb-0 text-warning"><?= $totalReservations ?></h3>
                        </div>
                        <div class="bg-warning bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-ticket-perforated fs-4 text-warning"></i>
                        </div>
                    </div>
                    <div class="mt-2">
                        <a href="/pages/admin_statistiken.php" class="small text-decoration-none text-warning">
                            <i class="bi bi-arrow-right me-1"></i>Statistiken
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-muted small mb-1">Gesamtumsatz</p>
                            <h3 class="fw-bold mb-0 text-danger"><?= formatBetrag($totalRevenue) ?></h3>
                        </div>
                        <div class="bg-danger bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-cash-stack fs-4 text-danger"></i>
                        </div>
                    </div>
                    <div class="mt-2">
                        <a href="/pages/admin_statistiken.php" class="small text-decoration-none text-danger">
                            <i class="bi bi-arrow-right me-1"></i>Details
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Hauptbereich: Chart + Aktivität ─────────────────────────────────────── -->
    <div class="row g-4 mb-4">

        <!-- Diagramm: Registrierungen pro Monat -->
        <div class="col-12 col-xl-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-bar-chart-line me-2 text-primary"></i>Registrierungen (letzte 6 Monate)
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="registrierungenChart" style="max-height: 280px;"></canvas>
                </div>
            </div>
        </div>

        <!-- Letzte Aktivitäten -->
        <div class="col-12 col-xl-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-clock-history me-2 text-secondary"></i>Letzte Aktivitäten
                    </h5>
                    <a href="/pages/admin_auditlog.php" class="btn btn-sm btn-outline-secondary">
                        Alle <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body p-0" style="max-height:320px; overflow-y:auto;">
                    <?php if (empty($recentActivity)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-journal-x fs-1 d-block mb-2"></i>
                        Keine Einträge vorhanden.
                    </div>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($recentActivity as $log):
                            $ak = strtoupper($log['aktion']);
                            $badgeClass = match(true) {
                                str_contains($ak, 'LOGIN')   => 'bg-success',
                                str_contains($ak, 'DELETE')  => 'bg-danger',
                                str_contains($ak, 'UPDATE')  => 'bg-warning text-dark',
                                str_contains($ak, 'CREATE')  => 'bg-primary',
                                str_contains($ak, 'CHECK')   => 'bg-info',
                                default                      => 'bg-secondary',
                            };
                        ?>
                        <li class="list-group-item px-3 py-2 border-0 border-bottom">
                            <div class="d-flex align-items-start gap-2">
                                <span class="badge <?= $badgeClass ?> mt-1 flex-shrink-0" style="font-size:.65rem;">
                                    <?= htmlspecialchars(substr($ak, 0, 8)) ?>
                                </span>
                                <div class="overflow-hidden flex-grow-1 min-width-0">
                                    <div class="small fw-semibold text-truncate">
                                        <?= htmlspecialchars($log['aktion']) ?>
                                        <span class="text-muted fw-normal">/ <?= htmlspecialchars($log['tabelle']) ?></span>
                                    </div>
                                    <div class="text-muted" style="font-size:.75rem;">
                                        <?php if ($log['vorname']): ?>
                                            <i class="bi bi-person me-1"></i><?= htmlspecialchars($log['vorname'] . ' ' . $log['nachname']) ?> &bull;
                                        <?php else: ?>
                                            <i class="bi bi-person-slash me-1"></i>System &bull;
                                        <?php endif; ?>
                                        <?= htmlspecialchars(date('d.m.Y H:i', strtotime($log['zeitstempel']))) ?>
                                        <?php if ($log['ip_adresse']): ?>
                                        &bull; <span class="font-monospace"><?= htmlspecialchars($log['ip_adresse']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Schnelllinks + Systemstatus ─────────────────────────────────────────── -->
    <div class="row g-4">

        <!-- Schnelllinks -->
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-lightning-charge me-2 text-warning"></i>Schnellzugriff
                    </h5>
                </div>
                <div class="card-body d-grid gap-2">
                    <a href="/pages/admin_events.php?action=create" class="btn btn-outline-primary text-start">
                        <i class="bi bi-calendar-plus me-2"></i>Neues Event erstellen
                    </a>
                    <a href="/pages/admin_users.php?action=create" class="btn btn-outline-secondary text-start">
                        <i class="bi bi-person-plus me-2"></i>Neuen Benutzer anlegen
                    </a>
                    <a href="/pages/admin_events.php#manual-reservation" class="btn btn-outline-success text-start">
                        <i class="bi bi-ticket-perforated me-2"></i>Manuelle Reservierung
                    </a>
                    <a href="/pages/admin_statistiken.php" class="btn btn-outline-info text-start">
                        <i class="bi bi-graph-up-arrow me-2"></i>Statistiken anzeigen
                    </a>
                    <a href="/pages/admin_auditlog.php" class="btn btn-outline-warning text-start">
                        <i class="bi bi-shield-check me-2"></i>Audit-Log einsehen
                    </a>
                    <a href="/pages/kassierer_dashboard.php" class="btn btn-outline-dark text-start">
                        <i class="bi bi-cash-register me-2"></i>Kassierer-Bereich
                    </a>
                </div>
            </div>
        </div>

        <!-- Systemstatus -->
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-hdd-stack me-2 text-info"></i>Systemstatus
                    </h5>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="text-muted small"><i class="bi bi-database me-2"></i>Datenbank</span>
                            <span class="badge <?= $dbStatus === 'OK' ? 'bg-success' : 'bg-danger' ?>">
                                <?= htmlspecialchars($dbStatus) ?>
                            </span>
                        </li>
                        <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="text-muted small"><i class="bi bi-calendar3 me-2"></i>Datum</span>
                            <span class="small fw-semibold"><?= date('d.m.Y') ?></span>
                        </li>
                        <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="text-muted small"><i class="bi bi-clock me-2"></i>Uhrzeit</span>
                            <span class="small fw-semibold"><?= date('H:i:s') ?></span>
                        </li>
                        <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="text-muted small"><i class="bi bi-code-slash me-2"></i>PHP-Version</span>
                            <span class="small fw-semibold font-monospace"><?= PHP_VERSION ?></span>
                        </li>
                        <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="text-muted small"><i class="bi bi-shield-lock me-2"></i>Session</span>
                            <span class="badge bg-success">Aktiv</span>
                        </li>
                        <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="text-muted small"><i class="bi bi-tag me-2"></i>Ticket-Preis</span>
                            <span class="small fw-semibold"><?= formatBetrag(TICKET_PREIS) ?></span>
                        </li>
                        <li class="d-flex justify-content-between align-items-center py-2">
                            <span class="text-muted small"><i class="bi bi-server me-2"></i>Anwendung</span>
                            <span class="small fw-semibold"><?= htmlspecialchars(APP_NAME) ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Admin-Bereich Übersicht -->
        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold">
                        <i class="bi bi-grid me-2 text-danger"></i>Admin-Bereiche
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-6">
                            <a href="/pages/admin_events.php"
                               class="card text-center text-decoration-none border-0 bg-primary bg-opacity-10 h-100 p-3 rounded-3 d-block">
                                <i class="bi bi-calendar-event fs-2 text-primary d-block mb-1"></i>
                                <span class="small fw-semibold text-primary">Events</span>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="/pages/admin_users.php"
                               class="card text-center text-decoration-none border-0 bg-secondary bg-opacity-10 h-100 p-3 rounded-3 d-block">
                                <i class="bi bi-people-fill fs-2 text-secondary d-block mb-1"></i>
                                <span class="small fw-semibold text-secondary">Benutzer</span>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="/pages/admin_statistiken.php"
                               class="card text-center text-decoration-none border-0 bg-success bg-opacity-10 h-100 p-3 rounded-3 d-block">
                                <i class="bi bi-graph-up fs-2 text-success d-block mb-1"></i>
                                <span class="small fw-semibold text-success">Statistiken</span>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="/pages/admin_auditlog.php"
                               class="card text-center text-decoration-none border-0 bg-warning bg-opacity-10 h-100 p-3 rounded-3 d-block">
                                <i class="bi bi-shield-check fs-2 text-warning d-block mb-1"></i>
                                <span class="small fw-semibold text-warning">Audit-Log</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /row -->
</main>

<?php
$extraScripts = '<script>
(function() {
    const labels = ' . json_encode($chartLabels) . ';
    const data   = ' . json_encode($chartData) . ';
    const ctx    = document.getElementById("registrierungenChart");
    if (!ctx) return;
    new Chart(ctx, {
        type: "bar",
        data: {
            labels: labels,
            datasets: [{
                label: "Neue Registrierungen",
                data: data,
                backgroundColor: "rgba(13,110,253,0.7)",
                borderColor: "rgba(13,110,253,1)",
                borderWidth: 1,
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ctx.parsed.y + " Registrierung(en)" } }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            }
        }
    });
})();
</script>';
include __DIR__ . '/../includes/footer.php';
?>

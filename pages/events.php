<?php
/**
 * Veranstaltungsübersicht - öffentlich zugänglich
 * Zeigt alle Events mit Auslastung und Reservierungsoptionen
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$pdo = getDB();

// Alle Events laden, geordnet nach Datum aufsteigend
// Auslastung direkt via JOIN berechnen für Performance
$stmt = $pdo->prepare(
    "SELECT
        e.id,
        e.datum,
        e.name,
        e.beschreibung,
        e.max_gaeste,
        e.status,
        e.erstellt_am,
        COUNT(s.id)                                                         AS gesamt_sitze,
        SUM(CASE WHEN s.status != 'verfuegbar' THEN 1 ELSE 0 END)          AS belegte_sitze
     FROM events e
     LEFT JOIN tables t  ON t.event_id  = e.id
     LEFT JOIN seats  s  ON s.table_id  = t.id
     GROUP BY e.id
     ORDER BY e.datum ASC"
);
$stmt->execute();
$events = $stmt->fetchAll();

$pageTitle = 'Veranstaltungen';
$bodyClass = 'bg-light';
$extraHead = '';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="py-5">
    <div class="container">

        <!-- Seitenheader -->
        <div class="row align-items-center mb-4">
            <div class="col">
                <h1 class="fw-bold mb-1">
                    <i class="bi bi-calendar-event text-warning me-2"></i>Veranstaltungen
                </h1>
                <p class="text-muted mb-0">
                    Alle verfügbaren Veranstaltungen auf einen Blick.
                    <?php if (!isLoggedIn()): ?>
                        <a href="/pages/register.php" class="text-warning fw-semibold">Registrieren Sie sich</a>,
                        um Plätze zu reservieren.
                    <?php endif; ?>
                </p>
            </div>
            <?php if (isLoggedIn()): ?>
            <div class="col-auto">
                <a href="/pages/meine_reservierungen.php" class="btn btn-outline-warning">
                    <i class="bi bi-ticket-perforated me-1"></i>Meine Reservierungen
                </a>
            </div>
            <?php endif; ?>
        </div>

        <?= getFlash() ?>

        <?php if (empty($events)): ?>
        <!-- Keine Events vorhanden -->
        <div class="card border-0 shadow-sm text-center py-5">
            <div class="card-body">
                <i class="bi bi-calendar-x display-3 text-muted mb-3 d-block"></i>
                <h4 class="text-muted">Keine Veranstaltungen geplant</h4>
                <p class="text-muted mb-0">
                    Aktuell sind keine Veranstaltungen eingetragen. Schauen Sie später wieder vorbei!
                </p>
            </div>
        </div>

        <?php else: ?>

        <!-- Statistik-Zeile -->
        <div class="row g-3 mb-4">
            <?php
            $totalEvents  = count($events);
            $activeEvents = array_filter($events, fn($e) => $e['status'] === 'aktiv');
            $totalSeats   = array_sum(array_column($events, 'gesamt_sitze'));
            $takenSeats   = array_sum(array_column($events, 'belegte_sitze'));
            $freeSeats    = $totalSeats - $takenSeats;
            ?>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center h-100">
                    <div class="card-body py-3">
                        <div class="display-6 fw-bold text-warning"><?= $totalEvents ?></div>
                        <div class="small text-muted">Veranstaltungen</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center h-100">
                    <div class="card-body py-3">
                        <div class="display-6 fw-bold text-success"><?= count($activeEvents) ?></div>
                        <div class="small text-muted">Aktiv</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center h-100">
                    <div class="card-body py-3">
                        <div class="display-6 fw-bold text-primary"><?= $totalSeats ?></div>
                        <div class="small text-muted">Plätze gesamt</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center h-100">
                    <div class="card-body py-3">
                        <div class="display-6 fw-bold text-info"><?= $freeSeats ?></div>
                        <div class="small text-muted">Freie Plätze</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Event-Cards -->
        <div class="row g-4">
            <?php foreach ($events as $event):
                $gesamt  = (int)($event['gesamt_sitze']  ?? 0);
                $belegt  = (int)($event['belegte_sitze'] ?? 0);
                $frei    = $gesamt - $belegt;
                $prozent = $gesamt > 0 ? round(($belegt / $gesamt) * 100) : 0;

                // Farbe der Fortschrittsanzeige je nach Auslastung
                if ($prozent >= 90) {
                    $barColor   = 'bg-danger';
                    $badgeColor = 'danger';
                    $auslastungText = 'Fast ausgebucht';
                } elseif ($prozent >= 70) {
                    $barColor   = 'bg-warning';
                    $badgeColor = 'warning';
                    $auslastungText = 'Stark gebucht';
                } else {
                    $barColor   = 'bg-success';
                    $badgeColor = 'success';
                    $auslastungText = 'Verfügbar';
                }

                // Ist das Event in der Vergangenheit?
                $isPast    = strtotime($event['datum']) < mktime(0, 0, 0);
                $isVoll    = $frei <= 0 && $gesamt > 0;
                $canBook   = !$isPast && !$isVoll && $event['status'] === 'aktiv';
            ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100 <?= $isPast ? 'opacity-75' : '' ?>">

                    <!-- Card-Header mit Datum und Status -->
                    <div class="card-header bg-dark text-white border-0 d-flex justify-content-between align-items-center py-3">
                        <span class="fw-semibold">
                            <i class="bi bi-calendar3 text-warning me-1"></i>
                            <?= formatDatum($event['datum']) ?>
                        </span>
                        <?= statusBadge($event['status']) ?>
                    </div>

                    <div class="card-body d-flex flex-column">

                        <!-- Eventtitel -->
                        <h5 class="card-title fw-bold mb-2">
                            <?= htmlspecialchars($event['name']) ?>
                        </h5>

                        <!-- Beschreibung (auf 150 Zeichen gekürzt) -->
                        <?php if (!empty($event['beschreibung'])): ?>
                        <p class="card-text text-muted small flex-grow-1">
                            <?php
                            $beschreibung = $event['beschreibung'];
                            if (mb_strlen($beschreibung) > 150) {
                                echo htmlspecialchars(mb_substr($beschreibung, 0, 150)) . '&hellip;';
                            } else {
                                echo htmlspecialchars($beschreibung);
                            }
                            ?>
                        </p>
                        <?php else: ?>
                        <p class="card-text text-muted small flex-grow-1 fst-italic">Keine Beschreibung vorhanden.</p>
                        <?php endif; ?>

                        <!-- Auslastungs-Anzeige -->
                        <div class="mt-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small fw-semibold text-muted">
                                    <i class="bi bi-people me-1"></i>Auslastung
                                </span>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="small text-muted"><?= $prozent ?>%</span>
                                    <span class="badge bg-<?= $badgeColor ?> <?= $badgeColor === 'warning' ? 'text-dark' : '' ?> small">
                                        <?= $auslastungText ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Fortschrittsbalken -->
                            <div class="progress mb-2" style="height: 10px; border-radius: 6px;" role="progressbar"
                                 aria-valuenow="<?= $prozent ?>" aria-valuemin="0" aria-valuemax="100"
                                 aria-label="Auslastung <?= $prozent ?>%">
                                <div class="progress-bar <?= $barColor ?> rounded-pill"
                                     style="width: <?= $prozent ?>%; transition: width 0.6s ease;">
                                </div>
                            </div>

                            <!-- Platz-Info -->
                            <div class="d-flex justify-content-between">
                                <small class="text-muted">
                                    <i class="bi bi-check-circle text-success me-1"></i><?= $frei ?> frei
                                </small>
                                <small class="text-muted">
                                    <i class="bi bi-x-circle text-danger me-1"></i><?= $belegt ?> belegt
                                </small>
                                <small class="text-muted">
                                    <i class="bi bi-grid me-1"></i><?= $gesamt ?> gesamt
                                </small>
                            </div>
                        </div>

                        <!-- Ticket-Preis -->
                        <div class="mt-3 p-2 bg-light rounded d-flex justify-content-between align-items-center">
                            <small class="text-muted"><i class="bi bi-ticket me-1"></i>Ticketpreis</small>
                            <span class="fw-bold text-dark"><?= formatBetrag(TICKET_PREIS) ?></span>
                        </div>

                    </div>

                    <!-- Card-Footer: Aktionsbutton -->
                    <div class="card-footer bg-transparent border-0 pb-3 px-3">
                        <?php if ($isPast): ?>
                            <button class="btn btn-outline-secondary w-100" disabled>
                                <i class="bi bi-clock-history me-1"></i>Veranstaltung beendet
                            </button>

                        <?php elseif ($isVoll): ?>
                            <button class="btn btn-outline-danger w-100" disabled>
                                <i class="bi bi-x-circle me-1"></i>Ausgebucht
                            </button>

                        <?php elseif ($event['status'] !== 'aktiv'): ?>
                            <button class="btn btn-outline-secondary w-100" disabled>
                                <i class="bi bi-hourglass me-1"></i>Noch nicht buchbar
                            </button>

                        <?php elseif (isLoggedIn()): ?>
                            <a href="/pages/tischplan.php?event_id=<?= (int)$event['id'] ?>"
                               class="btn btn-warning w-100 fw-semibold">
                                <i class="bi bi-grid-3x3 me-1"></i>Reservieren
                            </a>

                        <?php else: ?>
                            <a href="/pages/login.php" class="btn btn-outline-warning w-100 fw-semibold">
                                <i class="bi bi-box-arrow-in-right me-1"></i>Anmelden zum Reservieren
                            </a>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Legende -->
        <div class="mt-4 p-3 bg-white rounded shadow-sm">
            <div class="row g-2 align-items-center">
                <div class="col-auto">
                    <small class="text-muted fw-semibold">
                        <i class="bi bi-info-circle me-1"></i>Auslastung:
                    </small>
                </div>
                <div class="col-auto">
                    <span class="badge bg-success">unter 70% – Verfügbar</span>
                </div>
                <div class="col-auto">
                    <span class="badge bg-warning text-dark">70–89% – Stark gebucht</span>
                </div>
                <div class="col-auto">
                    <span class="badge bg-danger">ab 90% – Fast ausgebucht</span>
                </div>
            </div>
        </div>

        <?php endif; // events ?>

    </div>
</main>

<?php
$extraScripts = '';
include __DIR__ . '/../includes/footer.php';

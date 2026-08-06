<?php
/**
 * Kassierer Gästeliste
 * Gästeliste mit Check-in, Bezahlt-Markierung, Filter und Export.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

requireRole('kassierer', 'admin');

$pdo    = getDB();
$errors = [];

// ─── POST-Handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiger Sicherheitstoken. Aktion abgebrochen.');
    } else {
        $action        = sanitize($_POST['action'] ?? '');
        $reservationId = (int)($_POST['reservation_id'] ?? 0);
        $redirectEventId = (int)($_POST['event_id'] ?? 0);

        if ($action === 'bulk_checkin') {
            // Nutzt die zentrale Check-in-Funktion (functions.php) – gleiche
            // Prüfungen und gleicher Audit-Eintrag wie Scanner und Dashboard.
            $ids = array_values(array_filter(array_map('intval', $_POST['reservation_ids'] ?? [])));
            $eid = (int)($_POST['event_id'] ?? 0);
            $ok = 0; $skip = 0;
            foreach ($ids as $rid) {
                $r = checkinReservation($rid, $eid > 0 ? $eid : null);
                if ($r['ok']) { $ok++; } else { $skip++; }
            }
            $msg = $ok . ' Gast/Gäste eingecheckt.';
            if ($skip > 0) $msg .= ' ' . $skip . ' übersprungen (bereits eingecheckt oder ungültig).';
            setFlash($ok > 0 ? 'success' : 'warning', $msg);
            redirect('/pages/kassierer_guestlist.php' . ($eid ? '?event_id=' . $eid : ''));
        }

        if ($reservationId > 0 && in_array($action, ['checkin', 'bezahlt'], true)) {
            try {
                // Reservierung + Zahlung laden
                $stmtRes = $pdo->prepare(
                    'SELECT r.id, r.seat_id, r.status AS res_status, r.buchungsnummer,
                            u.vorname, u.nachname,
                            p.id AS payment_id, p.status AS zahl_status, p.zahlungsart
                     FROM reservations r
                     INNER JOIN users u ON u.id = r.user_id
                     LEFT  JOIN payments p ON p.reservation_id = r.id
                     WHERE r.id = ?'
                );
                $stmtRes->execute([$reservationId]);
                $res = $stmtRes->fetch();

                if (!$res) {
                    throw new RuntimeException('Reservierung nicht gefunden.');
                }

                $gastName = $res['vorname'] . ' ' . $res['nachname'];

                if ($action === 'checkin') {
                    // Zentrale Check-in-Funktion (setzt auch Zeitstempel und
                    // behandelt Freitickets ohne Sitzplatz korrekt).
                    $r = checkinReservation($reservationId, $redirectEventId > 0 ? $redirectEventId : null);
                    if (!$r['ok']) {
                        throw new RuntimeException($r['message']);
                    }
                    setFlash('success', 'Check-in für ' . htmlspecialchars($gastName) . ' erfolgreich.');

                } elseif ($action === 'bezahlt') {
                    if (!$res['payment_id']) {
                        throw new RuntimeException('Kein Zahlungsdatensatz gefunden.');
                    }
                    if ($res['zahl_status'] === 'bezahlt') {
                        throw new RuntimeException('Zahlung ist bereits als bezahlt markiert.');
                    }
                    // Nur Überweisung/offene Zahlungen können manuell als bezahlt markiert werden
                    if (!in_array($res['zahlungsart'], ['ueberweisung', 'bar'], true)) {
                        throw new RuntimeException('Nur Bar- und Überweisungszahlungen können manuell bestätigt werden.');
                    }

                    $pdo->prepare('UPDATE payments SET status = ? WHERE id = ?')
                        ->execute(['bezahlt', $res['payment_id']]);

                    logAudit(
                        'BEZAHLT',
                        'payments',
                        $res['payment_id'],
                        json_encode([
                            'buchungsnummer' => $res['buchungsnummer'],
                            'gast'           => $gastName,
                            'zahlungsart'    => $res['zahlungsart'],
                        ])
                    );
                    setFlash('success', 'Zahlung für ' . htmlspecialchars($gastName) . ' als bezahlt markiert.');
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                setFlash('error', 'Fehler: ' . htmlspecialchars($e->getMessage()));
            }
        }
    }

    // PRG-Pattern: nach POST zurückleiten
    $eid = (int)($_POST['event_id'] ?? 0);
    $qs  = http_build_query(array_filter([
        'event_id' => $eid ?: null,
        'suche'    => $_POST['suche']    ?? null,
        'status'   => $_POST['status_filter'] ?? null,
    ]));
    redirect('/pages/kassierer_guestlist.php' . ($qs ? '?' . $qs : ''));
}

// ─── Events für Selektor ──────────────────────────────────────────────────────
$events = $pdo->query(
    "SELECT id, name, datum, status
     FROM events
     ORDER BY datum DESC"
)->fetchAll();

$selectedEventId = (int)($_GET['event_id'] ?? ($events[0]['id'] ?? 0));

// Aktuell gewähltes Event laden
$currentEvent = null;
if ($selectedEventId) {
    $stmtEv = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $stmtEv->execute([$selectedEventId]);
    $currentEvent = $stmtEv->fetch();
}

// ─── Filter-Parameter ─────────────────────────────────────────────────────────
$suche        = trim($_GET['suche'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

// ─── Gästeliste laden ─────────────────────────────────────────────────────────
$gaeste = [];
if ($selectedEventId) {
    $sql = "SELECT
                r.id,
                r.buchungsnummer,
                r.status        AS res_status,
                r.preis,
                r.erstellt_am,
                u.vorname,
                u.nachname,
                u.email,
                u.zahlungsart   AS user_zahlungsart,
                t.tischnummer,
                s.sitzplatznummer,
                p.id            AS payment_id,
                p.status        AS zahl_status,
                p.zahlungsart,
                p.betrag
            FROM reservations r
            INNER JOIN users    u ON u.id = r.user_id
            LEFT  JOIN seats    s ON s.id = r.seat_id
            LEFT  JOIN tables   t ON t.id = s.table_id
            LEFT  JOIN payments p ON p.reservation_id = r.id
            WHERE r.event_id = :event_id";

    $params = ['event_id' => $selectedEventId];

    // Suche nach Name oder Buchungsnummer
    if ($suche !== '') {
        // Mit PDO::ATTR_EMULATE_PREPARES=false (config.php) darf derselbe
        // benannte Platzhalter nicht mehrfach im Query stehen – bislang
        // crashte jede Sucheingabe hier mit "Invalid parameter number".
        $sql .= " AND (u.vorname LIKE :suche1
                    OR u.nachname LIKE :suche2
                    OR CONCAT(u.vorname, ' ', u.nachname) LIKE :suche3
                    OR r.buchungsnummer LIKE :suche4
                    OR u.email LIKE :suche5)";
        $sucheNeedle = '%' . $suche . '%';
        $params['suche1'] = $sucheNeedle;
        $params['suche2'] = $sucheNeedle;
        $params['suche3'] = $sucheNeedle;
        $params['suche4'] = $sucheNeedle;
        $params['suche5'] = $sucheNeedle;
    }

    // Status-Filter
    if ($statusFilter !== '') {
        if ($statusFilter === 'offen') {
            $sql .= " AND p.status = 'offen'";
        } elseif ($statusFilter === 'bezahlt') {
            $sql .= " AND p.status = 'bezahlt'";
        } elseif ($statusFilter === 'eingecheckt') {
            $sql .= " AND r.status = 'eingecheckt'";
        } elseif ($statusFilter === 'geplant') {
            $sql .= " AND r.status = 'geplant'";
        }
    }

    // Freiticket-Gäste (kein Tisch) ans Ende sortieren, statt durch NULL-first
    // mitten in die Tischplan-Gäste zu geraten.
    $sql .= " ORDER BY (t.tischnummer IS NULL) ASC, t.tischnummer ASC, s.sitzplatznummer ASC, r.erstellt_am ASC";

    $stmtG = $pdo->prepare($sql);
    $stmtG->execute($params);
    $gaeste = $stmtG->fetchAll();
}

// ─── Summen berechnen ─────────────────────────────────────────────────────────
$totalGaeste    = count($gaeste);
$eingechecktAnz = count(array_filter($gaeste, fn($g) => $g['res_status'] === 'eingecheckt'));
$gesamtUmsatz   = array_sum(array_map(
    fn($g) => $g['zahl_status'] === 'bezahlt' ? (float)($g['betrag'] ?? 0) : 0,
    $gaeste
));
$offenAnz       = count(array_filter($gaeste, fn($g) => $g['zahl_status'] === 'offen'));

// ─── Seite ausgeben ───────────────────────────────────────────────────────────
$pageTitle = 'Gästeliste';
$bodyClass = 'bg-light';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container-fluid py-4">

    <!-- Seitentitel -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-people text-warning me-2"></i>Gästeliste
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
            <a href="/pages/kassierer_statistiken.php<?= $selectedEventId ? '?event_id=' . $selectedEventId : '' ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-bar-chart me-1"></i>Statistiken
            </a>
        </div>
    </div>

    <?= getFlash() ?>

    <!-- ── Filter & Event-Selektor ────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-end">

                <!-- Event-Auswahl -->
                <div class="col-12 col-md-4 col-lg-3">
                    <label for="event_id" class="form-label small fw-semibold">
                        <i class="bi bi-calendar-event me-1"></i>Event
                    </label>
                    <select name="event_id" id="event_id"
                            class="form-select form-select-sm"
                            onchange="this.form.submit()">
                        <option value="">– Event wählen –</option>
                        <?php foreach ($events as $ev): ?>
                        <option value="<?= (int)$ev['id'] ?>"
                            <?= $ev['id'] == $selectedEventId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ev['name']) ?> (<?= formatDatum($ev['datum']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Suche -->
                <div class="col-12 col-md-4 col-lg-4">
                    <label for="suche" class="form-label small fw-semibold">
                        <i class="bi bi-search me-1"></i>Suche
                    </label>
                    <input type="text"
                           name="suche"
                           id="suche"
                           class="form-control form-control-sm"
                           placeholder="Name, E-Mail oder Buchungsnr."
                           value="<?= htmlspecialchars($suche) ?>">
                </div>

                <!-- Status-Filter -->
                <div class="col-12 col-md-3 col-lg-3">
                    <label for="status" class="form-label small fw-semibold">
                        <i class="bi bi-funnel me-1"></i>Status
                    </label>
                    <select name="status" id="status" class="form-select form-select-sm">
                        <option value="">Alle</option>
                        <option value="geplant"     <?= $statusFilter === 'geplant'     ? 'selected' : '' ?>>Geplant</option>
                        <option value="eingecheckt" <?= $statusFilter === 'eingecheckt' ? 'selected' : '' ?>>Eingecheckt</option>
                        <option value="offen"       <?= $statusFilter === 'offen'       ? 'selected' : '' ?>>Zahlung offen</option>
                        <option value="bezahlt"     <?= $statusFilter === 'bezahlt'     ? 'selected' : '' ?>>Bezahlt</option>
                    </select>
                </div>

                <!-- Buttons -->
                <div class="col-12 col-md-1 col-lg-2 d-flex gap-2">
                    <button type="submit" class="btn btn-warning btn-sm">
                        <i class="bi bi-search me-1"></i>Suchen
                    </button>
                    <?php if ($suche !== '' || $statusFilter !== ''): ?>
                    <a href="/pages/kassierer_guestlist.php?event_id=<?= $selectedEventId ?>"
                       class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle"></i>
                    </a>
                    <?php endif; ?>
                </div>

            </form>
        </div>
    </div>

    <!-- ── Gästetabelle ────────────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0 fw-semibold">
                <i class="bi bi-list-ul me-2 text-warning"></i>Gäste
                <span class="badge bg-secondary ms-1"><?= $totalGaeste ?></span>
            </h5>
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <?php if ($selectedEventId): ?>
                <form method="POST" id="bulkForm" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"   value="bulk_checkin">
                    <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                    <button type="submit" id="bulkBtn" disabled class="btn btn-success btn-sm">
                        <i class="bi bi-check-all me-1"></i>Ausgewählte einchecken (<span id="selCount">0</span>)
                    </button>
                </form>
                <span class="text-muted small align-self-center">Export:</span>
                <a href="/api/export_guestlist.php?event_id=<?= $selectedEventId ?>&format=csv"
                   class="btn btn-outline-success btn-sm">
                    <i class="bi bi-filetype-csv me-1"></i>CSV
                </a>
                <a href="/api/export_guestlist.php?event_id=<?= $selectedEventId ?>&format=pdf"
                   class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-filetype-pdf me-1"></i>PDF
                </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-body p-0">
            <?php if (!$selectedEventId): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
                Bitte wählen Sie oben ein Event aus.
            </div>
            <?php elseif (empty($gaeste)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                <?= ($suche !== '' || $statusFilter !== '')
                    ? 'Keine Ergebnisse für diese Filterkriterien.'
                    : 'Noch keine Reservierungen für dieses Event.' ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3">
                                <input type="checkbox" id="selectAll" class="form-check-input" title="Alle auswählen">
                            </th>
                            <th>Buchungsnr.</th>
                            <th>Name</th>
                            <th>Sitz</th>
                            <th>Zahlungsart</th>
                            <th>Zahlung</th>
                            <th>Status</th>
                            <th class="text-end pe-3">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($gaeste as $i => $g): ?>
                    <tr class="<?= $g['res_status'] === 'eingecheckt' ? 'table-success' : '' ?>">

                        <!-- Checkbox für Bulk-Checkin -->
                        <td class="ps-3">
                            <input type="checkbox"
                                   name="reservation_ids[]"
                                   value="<?= (int)$g['id'] ?>"
                                   class="form-check-input row-check"
                                   form="bulkForm"
                                   <?= $g['res_status'] === 'eingecheckt' ? 'disabled' : '' ?>>
                        </td>

                        <!-- Buchungsnummer -->
                        <td>
                            <span class="font-monospace fw-semibold text-nowrap">
                                <?= htmlspecialchars($g['buchungsnummer']) ?>
                            </span>
                        </td>

                        <!-- Name + E-Mail -->
                        <td>
                            <div class="fw-semibold">
                                <?= htmlspecialchars($g['vorname'] . ' ' . $g['nachname']) ?>
                            </div>
                            <small class="text-muted"><?= htmlspecialchars($g['email']) ?></small>
                        </td>

                        <!-- Sitz: Tisch X Platz Y (oder Freiticket) -->
                        <td class="text-nowrap">
                            <?php if ($g['sitzplatznummer']): ?>
                            <i class="bi bi-grid me-1 text-muted"></i>
                            Tisch <strong><?= (int)$g['tischnummer'] ?></strong>
                            Platz <strong><?= (int)$g['sitzplatznummer'] ?></strong>
                            <?php else: ?>
                            <span class="badge bg-success">
                                <i class="bi bi-ticket-perforated me-1"></i>Freiticket
                            </span>
                            <?php endif; ?>
                        </td>

                        <!-- Zahlungsart -->
                        <td class="text-nowrap">
                            <?php
                            $zart = $g['zahlungsart'] ?? $g['user_zahlungsart'] ?? '';
                            $zartIcon = match($zart) {
                                'bar'          => 'bi-cash-coin text-success',
                                'ueberweisung' => 'bi-bank text-primary',
                                'paypal'       => 'bi-paypal text-info',
                                default        => 'bi-question-circle text-muted',
                            };
                            ?>
                            <i class="bi <?= $zartIcon ?> me-1"></i>
                            <?= htmlspecialchars(zahlungsartLabel($zart)) ?>
                        </td>

                        <!-- Zahlungsstatus -->
                        <td class="text-nowrap">
                            <?php if ($g['zahl_status']): ?>
                                <?= statusBadge($g['zahl_status']) ?>
                                <?php if ($g['betrag']): ?>
                                <br><small class="text-muted"><?= formatBetrag((float)$g['betrag']) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>

                        <!-- Check-in-Status -->
                        <td><?= statusBadge($g['res_status']) ?></td>

                        <!-- Aktionen -->
                        <td class="text-end pe-3 text-nowrap">

                            <!-- Check-in Button -->
                            <?php if ($g['res_status'] !== 'eingecheckt'): ?>
                            <form method="POST" action="" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"         value="checkin">
                                <input type="hidden" name="reservation_id" value="<?= (int)$g['id'] ?>">
                                <input type="hidden" name="event_id"       value="<?= $selectedEventId ?>">
                                <input type="hidden" name="suche"          value="<?= htmlspecialchars($suche) ?>">
                                <input type="hidden" name="status_filter"  value="<?= htmlspecialchars($statusFilter) ?>">
                                <button type="submit"
                                        class="btn btn-sm btn-success"
                                        title="Check-in durchführen"
                                        onclick="return confirm('Check-in für <?= htmlspecialchars(addslashes($g['vorname'] . ' ' . $g['nachname'])) ?> bestätigen?')">
                                    <i class="bi bi-person-check-fill"></i>
                                    <span class="d-none d-lg-inline ms-1">Check-in</span>
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="text-success fw-semibold small">
                                <i class="bi bi-check-circle-fill me-1"></i>Eingecheckt
                            </span>
                            <?php endif; ?>

                            <!-- Bezahlt-Button: nur für bar/ueberweisung mit offener Zahlung -->
                            <?php
                            $zartActual = $g['zahlungsart'] ?? $g['user_zahlungsart'] ?? '';
                            $canMarkPaid = $g['payment_id']
                                && $g['zahl_status'] === 'offen'
                                && in_array($zartActual, ['bar', 'ueberweisung'], true);
                            ?>
                            <?php if ($canMarkPaid): ?>
                            <form method="POST" action="" class="d-inline ms-1">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"         value="bezahlt">
                                <input type="hidden" name="reservation_id" value="<?= (int)$g['id'] ?>">
                                <input type="hidden" name="event_id"       value="<?= $selectedEventId ?>">
                                <input type="hidden" name="suche"          value="<?= htmlspecialchars($suche) ?>">
                                <input type="hidden" name="status_filter"  value="<?= htmlspecialchars($statusFilter) ?>">
                                <button type="submit"
                                        class="btn btn-sm btn-outline-primary"
                                        title="Als bezahlt markieren"
                                        onclick="return confirm('Zahlung für <?= htmlspecialchars(addslashes($g['vorname'] . ' ' . $g['nachname'])) ?> als bezahlt markieren?')">
                                    <i class="bi bi-cash-coin"></i>
                                    <span class="d-none d-lg-inline ms-1">Bezahlt</span>
                                </button>
                            </form>
                            <?php elseif ($g['zahl_status'] === 'bezahlt'): ?>
                            <span class="text-success small ms-1">
                                <i class="bi bi-check2-circle me-1"></i>Bezahlt
                            </span>
                            <?php endif; ?>

                            <!-- QR anzeigen (verlorenes Ticket erneut zeigen) -->
                            <button type="button" class="btn btn-sm btn-outline-secondary ms-1"
                                    data-reservation-id="<?= (int)$g['id'] ?>"
                                    title="QR-Code anzeigen">
                                <i class="bi bi-qr-code"></i>
                            </button>

                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Summen-Footer ──────────────────────────────────────────────── -->
        <?php if ($selectedEventId && !empty($gaeste)): ?>
        <div class="card-footer bg-light border-top">
            <div class="row g-3 align-items-center">
                <div class="col-12 col-md-8">
                    <div class="d-flex flex-wrap gap-4 small">
                        <span>
                            <i class="bi bi-people text-primary me-1"></i>
                            <strong><?= $totalGaeste ?></strong> Gäste gesamt
                        </span>
                        <span>
                            <i class="bi bi-person-check text-success me-1"></i>
                            <strong><?= $eingechecktAnz ?></strong> eingecheckt
                            (<?= $totalGaeste > 0 ? round($eingechecktAnz / $totalGaeste * 100) : 0 ?>%)
                        </span>
                        <span>
                            <i class="bi bi-hourglass text-warning me-1"></i>
                            <strong><?= $offenAnz ?></strong> Zahlungen offen
                        </span>
                    </div>
                </div>
                <div class="col-12 col-md-4 text-md-end">
                    <span class="fw-bold text-success">
                        <i class="bi bi-cash-stack me-1"></i>
                        Umsatz (bezahlt): <?= formatBetrag($gesamtUmsatz) ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /card -->

</main>

<!-- ══ Ticket-QR-Modal (verlorenes Ticket erneut anzeigen) ══════════════════ -->
<div class="modal fade" id="ticketModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="tm-gast">&nbsp;</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="tm-qr" class="mb-3 d-flex justify-content-center"></div>
                <div id="tm-buchungsnr" class="font-monospace fw-bold fs-5 mb-2"></div>
                <div id="tm-details" class="text-muted small"></div>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<'HTML'
<script>
(function() {
    var selectAll = document.getElementById('selectAll');
    var bulkBtn   = document.getElementById('bulkBtn');
    var selCount  = document.getElementById('selCount');
    if (!selectAll) return;

    function updateBulk() {
        var checked = document.querySelectorAll('.row-check:not([disabled]):checked').length;
        selCount.textContent = checked;
        bulkBtn.disabled = checked === 0;
    }

    selectAll.addEventListener('change', function() {
        document.querySelectorAll('.row-check:not([disabled])').forEach(function(cb) {
            cb.checked = selectAll.checked;
        });
        updateBulk();
    });

    document.querySelectorAll('.row-check').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var allEnabled  = document.querySelectorAll('.row-check:not([disabled])');
            var allChecked  = document.querySelectorAll('.row-check:not([disabled]):checked');
            selectAll.indeterminate = allChecked.length > 0 && allChecked.length < allEnabled.length;
            selectAll.checked = allChecked.length === allEnabled.length && allEnabled.length > 0;
            updateBulk();
        });
    });
})();

// QR-Code-Anzeige für ein einzelnes Ticket (z.B. bei verlorenem Ticket)
(function() {
    var modalEl = document.getElementById('ticketModal');
    if (!modalEl || !window.bootstrap) return;

    document.querySelectorAll('[data-reservation-id]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            document.getElementById('tm-qr').innerHTML =
                '<div class="spinner-border text-warning" role="status"></div>';
            document.getElementById('tm-gast').textContent = 'Lade …';
            document.getElementById('tm-buchungsnr').textContent = '';
            document.getElementById('tm-details').textContent = '';
            modal.show();

            fetch('/api/ticket_qr.php?reservation_id=' + encodeURIComponent(btn.dataset.reservationId), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res || !res.success) {
                    document.getElementById('tm-gast').textContent = 'Nicht gefunden';
                    document.getElementById('tm-details').textContent = (res && res.message) || 'Fehler.';
                    return;
                }
                var d = res.data;
                document.getElementById('tm-gast').textContent = d.gast;
                document.getElementById('tm-qr').innerHTML = d.qr_html;
                document.getElementById('tm-buchungsnr').textContent = d.buchungsnummer;
                var zahlHinweis = d.zahl_status !== 'bezahlt' ? ' · Zahlung offen' : '';
                document.getElementById('tm-details').textContent =
                    d.event_name + ' · ' + d.event_datum + ' · ' + d.platz + zahlHinweis;
            })
            .catch(function() {
                document.getElementById('tm-gast').textContent = 'Netzwerkfehler';
            });
        });
    });
})();
</script>
HTML;
include __DIR__ . '/../includes/footer.php';

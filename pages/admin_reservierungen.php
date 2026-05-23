<?php
/**
 * Admin Reservierungsverwaltung
 * Alle Reservierungen einsehen, stornieren und Zahlungsstatus verwalten.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');

$pdo    = getDB();
$myId   = (int)$_SESSION['user_id'];
$errors = [];

// ═══════════════════════════════════════════════════════════════════════════════
// POST-Handler
// ═══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiges CSRF-Token.');
        redirect('/pages/admin_reservierungen.php');
    }

    $postAction = $_POST['post_action'] ?? '';

    // ── Reservierung stornieren ──────────────────────────────────────────────
    if ($postAction === 'cancel_reservation') {
        $reservationId = (int)($_POST['reservation_id'] ?? 0);

        if ($reservationId > 0) {
            $stmt = $pdo->prepare(
                'SELECT r.*, u.email, u.vorname, e.name AS event_name
                 FROM reservations r
                 JOIN users u ON r.user_id = u.id
                 JOIN events e ON r.event_id = e.id
                 WHERE r.id = ? AND r.status = "geplant"'
            );
            $stmt->execute([$reservationId]);
            $res = $stmt->fetch();

            if ($res) {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE reservations SET status = "abgerechnet" WHERE id = ?')
                        ->execute([$reservationId]);
                    $pdo->prepare('UPDATE seats SET status = "verfuegbar" WHERE id = ?')
                        ->execute([$res['seat_id']]);
                    $pdo->prepare('UPDATE payments SET status = "storniert" WHERE reservation_id = ?')
                        ->execute([$reservationId]);
                    logAudit('STORNIERUNG', 'reservations', $reservationId,
                        "Admin-Stornierung durch User #{$myId}: {$res['buchungsnummer']}");
                    $pdo->commit();
                    setFlash('success', "Reservierung {$res['buchungsnummer']} wurde storniert.");
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    error_log('Admin-Stornierung Fehler: ' . $e->getMessage());
                    setFlash('error', 'Fehler beim Stornieren. Bitte erneut versuchen.');
                }
            } else {
                setFlash('error', 'Reservierung nicht gefunden oder bereits storniert.');
            }
        }
        redirect('/pages/admin_reservierungen.php?' . http_build_query(array_filter([
            'event_id'  => $_POST['filter_event_id'] ?? '',
            'status'    => $_POST['filter_status'] ?? '',
            'search'    => $_POST['filter_search'] ?? '',
        ])));
    }

    // ── Zahlungsstatus ändern ────────────────────────────────────────────────
    if ($postAction === 'update_payment') {
        $reservationId = (int)($_POST['reservation_id'] ?? 0);
        $newStatus     = in_array($_POST['payment_status'] ?? '', ['offen', 'bezahlt', 'storniert'])
                         ? $_POST['payment_status'] : null;

        if ($reservationId > 0 && $newStatus) {
            $pdo->prepare('UPDATE payments SET status = ? WHERE reservation_id = ?')
                ->execute([$newStatus, $reservationId]);
            logAudit('ZAHLUNG', 'payments', $reservationId,
                "Admin setzt Zahlungsstatus auf: {$newStatus}");
            setFlash('success', 'Zahlungsstatus aktualisiert.');
        }
        redirect('/pages/admin_reservierungen.php?' . http_build_query(array_filter([
            'event_id'  => $_POST['filter_event_id'] ?? '',
            'status'    => $_POST['filter_status'] ?? '',
            'search'    => $_POST['filter_search'] ?? '',
        ])));
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// Filter
// ═══════════════════════════════════════════════════════════════════════════════
$filterEvent  = (int)($_GET['event_id'] ?? 0);
$filterStatus = in_array($_GET['status'] ?? '', ['', 'geplant', 'eingecheckt', 'abgerechnet'])
                ? ($_GET['status'] ?? '') : '';
$search       = sanitize($_GET['search'] ?? '');

// Events für Filter-Dropdown laden
$alleEvents = $pdo->query("SELECT id, datum, name FROM events ORDER BY datum DESC")->fetchAll();

// Reservierungen laden
$where  = [];
$params = [];

if ($filterEvent > 0) {
    $where[]  = 'r.event_id = ?';
    $params[] = $filterEvent;
}
if ($filterStatus !== '') {
    $where[]  = 'r.status = ?';
    $params[] = $filterStatus;
}
if ($search !== '') {
    $where[]  = '(u.vorname LIKE ? OR u.nachname LIKE ? OR u.email LIKE ? OR r.buchungsnummer LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    "SELECT r.id, r.buchungsnummer, r.status, r.preis, r.erstellt_am,
            u.id AS user_id, u.vorname, u.nachname, u.email,
            e.id AS event_id, e.name AS event_name, e.datum AS event_datum,
            t.tischnummer, s.sitzplatznummer,
            p.status AS payment_status, p.zahlungsart, p.id AS payment_id
     FROM reservations r
     JOIN users u ON r.user_id = u.id
     JOIN events e ON r.event_id = e.id
     JOIN seats s ON r.seat_id = s.id
     JOIN tables t ON s.table_id = t.id
     LEFT JOIN payments p ON p.reservation_id = r.id
     {$whereClause}
     ORDER BY r.erstellt_am DESC"
);
$stmt->execute($params);
$reservierungen = $stmt->fetchAll();

// Statistik-Zähler
$gesamt      = count($reservierungen);
$geplant     = count(array_filter($reservierungen, fn($r) => $r['status'] === 'geplant'));
$eingecheckt = count(array_filter($reservierungen, fn($r) => $r['status'] === 'eingecheckt'));
$storniert   = count(array_filter($reservierungen, fn($r) => $r['status'] === 'abgerechnet'));
$bezahlt     = count(array_filter($reservierungen, fn($r) => $r['payment_status'] === 'bezahlt'));
$umsatz      = array_sum(array_column(array_filter($reservierungen, fn($r) => $r['payment_status'] === 'bezahlt'), 'preis'));

$pageTitle = 'Admin – Reservierungen';
$bodyClass = 'bg-light';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container-fluid py-4">

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-ticket-perforated-fill text-secondary me-2"></i>Reservierungen
            </h1>
            <p class="text-muted mb-0 small">Alle Buchungen einsehen, stornieren und Zahlungen verwalten</p>
        </div>
        <a href="/pages/admin_dashboard.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
    </div>

    <?= getFlash() ?>

    <!-- Statistik-Kacheln -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <div class="fs-4 fw-bold"><?= $gesamt ?></div>
                    <small class="text-muted">Gesamt</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center border-top border-warning border-3">
                <div class="card-body py-3">
                    <div class="fs-4 fw-bold text-warning"><?= $geplant ?></div>
                    <small class="text-muted">Geplant</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center border-top border-success border-3">
                <div class="card-body py-3">
                    <div class="fs-4 fw-bold text-success"><?= $eingecheckt ?></div>
                    <small class="text-muted">Eingecheckt</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center border-top border-secondary border-3">
                <div class="card-body py-3">
                    <div class="fs-4 fw-bold text-secondary"><?= $storniert ?></div>
                    <small class="text-muted">Storniert</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center border-top border-primary border-3">
                <div class="card-body py-3">
                    <div class="fs-4 fw-bold text-primary"><?= $bezahlt ?></div>
                    <small class="text-muted">Bezahlt</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center border-top border-info border-3">
                <div class="card-body py-3">
                    <div class="fs-4 fw-bold text-info"><?= formatBetrag($umsatz) ?></div>
                    <small class="text-muted">Umsatz</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold small mb-1">Event</label>
                    <select name="event_id" class="form-select form-select-sm">
                        <option value="">Alle Events</option>
                        <?php foreach ($alleEvents as $ev): ?>
                        <option value="<?= $ev['id'] ?>" <?= $filterEvent === (int)$ev['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars(formatDatum($ev['datum']) . ' – ' . $ev['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold small mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Alle</option>
                        <option value="geplant"     <?= $filterStatus === 'geplant'     ? 'selected' : '' ?>>Geplant</option>
                        <option value="eingecheckt" <?= $filterStatus === 'eingecheckt' ? 'selected' : '' ?>>Eingecheckt</option>
                        <option value="abgerechnet" <?= $filterStatus === 'abgerechnet' ? 'selected' : '' ?>>Storniert/Abgerechnet</option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold small mb-1">Suche</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control"
                               placeholder="Name, E-Mail oder Buchungsnummer..."
                               value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-6 col-md-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-funnel"></i>
                    </button>
                </div>
                <div class="col-6 col-md-1">
                    <a href="/pages/admin_reservierungen.php" class="btn btn-sm btn-outline-secondary w-100">
                        <i class="bi bi-x"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabelle -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between">
            <h5 class="mb-0 fw-semibold">
                <i class="bi bi-list-ul me-2 text-secondary"></i>Buchungen
                <span class="badge bg-secondary ms-1"><?= $gesamt ?></span>
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($reservierungen)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-ticket-perforated fs-1 d-block mb-2"></i>
                Keine Reservierungen gefunden.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Buchungs-Nr.</th>
                            <th>Gast</th>
                            <th>Event</th>
                            <th>Platz</th>
                            <th>Status</th>
                            <th>Zahlung</th>
                            <th>Preis</th>
                            <th>Datum</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($reservierungen as $r): ?>
                    <tr>
                        <td>
                            <code class="text-dark fw-bold"><?= htmlspecialchars($r['buchungsnummer']) ?></code>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($r['vorname'] . ' ' . $r['nachname']) ?></div>
                            <a href="mailto:<?= htmlspecialchars($r['email']) ?>"
                               class="text-muted text-decoration-none" style="font-size:0.8rem;">
                                <?= htmlspecialchars($r['email']) ?>
                            </a>
                        </td>
                        <td>
                            <div><?= htmlspecialchars($r['event_name']) ?></div>
                            <small class="text-muted"><?= formatDatum($r['event_datum']) ?></small>
                        </td>
                        <td>
                            <span class="badge bg-dark">
                                T<?= $r['tischnummer'] ?> / P<?= $r['sitzplatznummer'] ?>
                            </span>
                        </td>
                        <td><?= statusBadge($r['status']) ?></td>
                        <td>
                            <?php
                            $payClass = match($r['payment_status'] ?? '') {
                                'bezahlt'   => 'bg-success',
                                'storniert' => 'bg-secondary',
                                default     => 'bg-warning text-dark',
                            };
                            $payLabel = match($r['payment_status'] ?? '') {
                                'bezahlt'   => 'Bezahlt',
                                'storniert' => 'Storniert',
                                default     => 'Offen',
                            };
                            ?>
                            <span class="badge <?= $payClass ?>"><?= $payLabel ?></span>
                            <?php if ($r['zahlungsart']): ?>
                            <br><small class="text-muted"><?= zahlungsartLabel($r['zahlungsart']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= formatBetrag($r['preis']) ?></td>
                        <td>
                            <small class="text-muted"><?= formatDatum($r['erstellt_am']) ?></small>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <?php if ($r['status'] === 'geplant'): ?>
                                <!-- Stornieren -->
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="post_action" value="cancel_reservation">
                                    <input type="hidden" name="reservation_id" value="<?= $r['id'] ?>">
                                    <input type="hidden" name="filter_event_id" value="<?= $filterEvent ?>">
                                    <input type="hidden" name="filter_status" value="<?= htmlspecialchars($filterStatus) ?>">
                                    <input type="hidden" name="filter_search" value="<?= htmlspecialchars($search) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            title="Reservierung stornieren"
                                            onclick="return confirm('Reservierung <?= htmlspecialchars($r['buchungsnummer']) ?> stornieren?');">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                </form>
                                <?php endif; ?>

                                <!-- Zahlungsstatus ändern -->
                                <?php if ($r['payment_status'] !== 'storniert'): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        title="Zahlungsstatus ändern"
                                        data-bs-toggle="modal"
                                        data-bs-target="#paymentModal"
                                        data-reservation-id="<?= $r['id'] ?>"
                                        data-buchungsnummer="<?= htmlspecialchars($r['buchungsnummer']) ?>"
                                        data-payment-status="<?= htmlspecialchars($r['payment_status'] ?? 'offen') ?>"
                                        data-filter-event="<?= $filterEvent ?>"
                                        data-filter-status="<?= htmlspecialchars($filterStatus) ?>"
                                        data-filter-search="<?= htmlspecialchars($search) ?>">
                                    <i class="bi bi-credit-card"></i>
                                </button>
                                <?php endif; ?>

                                <!-- Benutzer direkt aufrufen -->
                                <a href="/pages/admin_users.php?action=edit&user_id=<?= $r['user_id'] ?>"
                                   class="btn btn-sm btn-outline-warning" title="Benutzer bearbeiten">
                                    <i class="bi bi-person-gear"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</main>

<!-- Modal: Zahlungsstatus ändern -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold">
                    <i class="bi bi-credit-card me-2"></i>Zahlung
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="post_action" value="update_payment">
                <input type="hidden" name="reservation_id" id="modalReservationId">
                <input type="hidden" name="filter_event_id" id="modalFilterEvent">
                <input type="hidden" name="filter_status" id="modalFilterStatus">
                <input type="hidden" name="filter_search" id="modalFilterSearch">
                <div class="modal-body">
                    <p class="small text-muted mb-3">Buchung: <strong id="modalBuchungsnummer"></strong></p>
                    <label class="form-label fw-semibold small">Zahlungsstatus</label>
                    <select name="payment_status" id="modalPaymentStatus" class="form-select">
                        <option value="offen">Offen</option>
                        <option value="bezahlt">Bezahlt</option>
                        <option value="storniert">Storniert</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary btn-sm">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraScripts = '<script>
document.getElementById("paymentModal").addEventListener("show.bs.modal", function(e) {
    const btn = e.relatedTarget;
    document.getElementById("modalReservationId").value  = btn.dataset.reservationId;
    document.getElementById("modalBuchungsnummer").textContent = btn.dataset.buchungsnummer;
    document.getElementById("modalPaymentStatus").value  = btn.dataset.paymentStatus;
    document.getElementById("modalFilterEvent").value    = btn.dataset.filterEvent;
    document.getElementById("modalFilterStatus").value   = btn.dataset.filterStatus;
    document.getElementById("modalFilterSearch").value   = btn.dataset.filterSearch;
});
</script>';
include __DIR__ . '/../includes/footer.php';
?>

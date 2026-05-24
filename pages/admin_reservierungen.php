<?php
/**
 * Admin – Reservierungsverwaltung
 * Einsehen · Erstellen · Stornieren · Löschen · Bezahlt-Toggle
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');

$pdo   = getDB();
$myId  = (int)$_SESSION['user_id'];

// ═══════════════════════════════════════════════════════════════════
// POST-Handler
// ═══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiges CSRF-Token.');
        redirect('/pages/admin_reservierungen.php');
    }

    $postAction = $_POST['post_action'] ?? '';

    // ── Neue Reservierung erstellen ─────────────────────────────────
    if ($postAction === 'create_reservation') {
        $newUserId  = (int)($_POST['new_user_id']  ?? 0);
        $newTableId = (int)($_POST['new_table_id'] ?? 0);
        $newAnzahl  = max(1, min(20, (int)($_POST['new_anzahl'] ?? 1)));

        if (!$newUserId || !$newTableId) {
            setFlash('error', 'Bitte Benutzer und Tisch auswählen.');
            redirect('/pages/admin_reservierungen.php');
        }

        // Tisch + Event ermitteln
        $stmtTbl = $pdo->prepare(
            'SELECT t.id, t.event_id, e.status AS event_status
             FROM `tables` t JOIN events e ON t.event_id = e.id
             WHERE t.id = ?'
        );
        $stmtTbl->execute([$newTableId]);
        $tbl = $stmtTbl->fetch();

        if (!$tbl || $tbl['event_status'] === 'abgerechnet') {
            setFlash('error', 'Ungültiger Tisch oder Event bereits abgerechnet.');
            redirect('/pages/admin_reservierungen.php');
        }
        $newEventId = (int)$tbl['event_id'];

        $pdo->beginTransaction();
        try {
            // Verfügbare Sitze sperren
            $stmtSeats = $pdo->prepare(
                'SELECT id FROM seats WHERE table_id = ? AND status = "verfuegbar" LIMIT ? FOR UPDATE'
            );
            $stmtSeats->execute([$newTableId, $newAnzahl]);
            $availSeats = $stmtSeats->fetchAll(PDO::FETCH_COLUMN);

            if (count($availSeats) < $newAnzahl) {
                $pdo->rollBack();
                setFlash('error', 'Nicht genug freie Plätze an diesem Tisch (' .
                    count($availSeats) . ' verfügbar).');
                redirect('/pages/admin_reservierungen.php');
            }

            // Zahlungsart des Benutzers
            $stmtU = $pdo->prepare('SELECT zahlungsart FROM users WHERE id = ?');
            $stmtU->execute([$newUserId]);
            $zahlungsart = $stmtU->fetchColumn() ?: 'bar';

            $stmtRes  = $pdo->prepare(
                'INSERT INTO reservations (user_id, event_id, seat_id, buchungsnummer, preis)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmtPay  = $pdo->prepare(
                'INSERT INTO payments (reservation_id, zahlungsart, betrag, status)
                 VALUES (?, ?, ?, "offen")'
            );
            $stmtSeat = $pdo->prepare("UPDATE seats SET status = 'reserviert' WHERE id = ?");

            foreach ($availSeats as $seatId) {
                $bn = generateBuchungsnummer();
                $stmtRes->execute([$newUserId, $newEventId, $seatId, $bn, TICKET_PREIS]);
                $rid = (int)$pdo->lastInsertId();
                $stmtPay->execute([$rid, $zahlungsart, TICKET_PREIS]);
                $stmtSeat->execute([$seatId]);
                logAudit('ADMIN_RESERVIERUNG', 'reservations', $rid,
                    "Admin #{$myId} erstellt Reservierung: {$bn}");
            }

            $pdo->commit();
            setFlash('success', $newAnzahl . ' Platz/Plätze erfolgreich reserviert.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Admin-Neue-Reservierung: ' . $e->getMessage());
            setFlash('error', 'Fehler beim Erstellen der Reservierung.');
        }
        redirect('/pages/admin_reservierungen.php');
    }

    // ── "Bezahlt" umschalten ────────────────────────────────────────
    if ($postAction === 'toggle_bezahlt') {
        $reservationId = (int)($_POST['reservation_id'] ?? 0);
        if ($reservationId > 0) {
            $stmt = $pdo->prepare('SELECT status FROM payments WHERE reservation_id = ?');
            $stmt->execute([$reservationId]);
            $cur = $stmt->fetchColumn();
            $new = ($cur === 'bezahlt') ? 'offen' : 'bezahlt';
            $pdo->prepare('UPDATE payments SET status = ? WHERE reservation_id = ?')
                ->execute([$new, $reservationId]);
            logAudit('ZAHLUNG', 'payments', $reservationId,
                "Admin #{$myId} setzt Bezahlt-Status: {$new}");
            setFlash('success', 'Zahlungsstatus aktualisiert.');
        }
        redirect('/pages/admin_reservierungen.php?' . http_build_query(array_filter([
            'event_id' => $_POST['filter_event_id'] ?? '',
            'status'   => $_POST['filter_status']   ?? '',
            'search'   => $_POST['filter_search']   ?? '',
        ])));
    }

    // ── Reservierung stornieren (Status → abgerechnet) ──────────────
    if ($postAction === 'cancel_reservation') {
        $reservationId = (int)($_POST['reservation_id'] ?? 0);
        if ($reservationId > 0) {
            $stmt = $pdo->prepare(
                'SELECT r.seat_id, r.buchungsnummer FROM reservations r
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
                        "Admin #{$myId} storniert: {$res['buchungsnummer']}");
                    $pdo->commit();
                    setFlash('success', "Reservierung {$res['buchungsnummer']} storniert.");
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    setFlash('error', 'Fehler beim Stornieren.');
                }
            } else {
                setFlash('error', 'Reservierung nicht gefunden oder bereits storniert.');
            }
        }
        redirect('/pages/admin_reservierungen.php?' . http_build_query(array_filter([
            'event_id' => $_POST['filter_event_id'] ?? '',
            'status'   => $_POST['filter_status']   ?? '',
            'search'   => $_POST['filter_search']   ?? '',
        ])));
    }

    // ── Reservierung dauerhaft löschen ──────────────────────────────
    if ($postAction === 'delete_reservation') {
        $reservationId = (int)($_POST['reservation_id'] ?? 0);
        if ($reservationId > 0) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'SELECT seat_id, buchungsnummer FROM reservations WHERE id = ?'
                );
                $stmt->execute([$reservationId]);
                $res = $stmt->fetch();
                if ($res) {
                    $pdo->prepare('DELETE FROM payments WHERE reservation_id = ?')
                        ->execute([$reservationId]);
                    $pdo->prepare('DELETE FROM reservations WHERE id = ?')
                        ->execute([$reservationId]);
                    $pdo->prepare("UPDATE seats SET status = 'verfuegbar' WHERE id = ?")
                        ->execute([$res['seat_id']]);
                    logAudit('DELETE', 'reservations', $reservationId,
                        "Admin #{$myId} löscht: {$res['buchungsnummer']}");
                }
                $pdo->commit();
                setFlash('success', 'Reservierung endgültig gelöscht.');
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('Admin-Delete-Reservierung: ' . $e->getMessage());
                setFlash('error', 'Fehler beim Löschen.');
            }
        }
        redirect('/pages/admin_reservierungen.php?' . http_build_query(array_filter([
            'event_id' => $_POST['filter_event_id'] ?? '',
            'status'   => $_POST['filter_status']   ?? '',
            'search'   => $_POST['filter_search']   ?? '',
        ])));
    }
}

// ═══════════════════════════════════════════════════════════════════
// Filter
// ═══════════════════════════════════════════════════════════════════
$filterEvent  = (int)($_GET['event_id'] ?? 0);
$filterStatus = in_array($_GET['status'] ?? '', ['', 'geplant', 'eingecheckt', 'abgerechnet'])
                ? ($_GET['status'] ?? '') : '';
$search       = sanitize($_GET['search'] ?? '');

// Alle Events für Filter + Neue-Reservierung-Modal
$alleEvents = $pdo->query(
    "SELECT id, datum, name FROM events ORDER BY datum DESC"
)->fetchAll();

// Alle Benutzer für Neue-Reservierung-Modal
$alleUsers = $pdo->query(
    "SELECT id, vorname, nachname, email FROM users WHERE rolle = 'user' ORDER BY nachname, vorname"
)->fetchAll();

// Alle Tische (mit Event-Info) für Neue-Reservierung-Modal
$alleTische = $pdo->query(
    "SELECT t.id, t.tischnummer, t.event_id, e.name AS event_name, e.datum AS event_datum,
            SUM(CASE WHEN s.status = 'verfuegbar' THEN 1 ELSE 0 END) AS frei
     FROM `tables` t
     JOIN events e ON t.event_id = e.id
     LEFT JOIN seats s ON s.table_id = t.id
     WHERE e.status != 'abgerechnet'
     GROUP BY t.id, t.tischnummer, t.event_id, e.name, e.datum
     ORDER BY e.datum DESC, t.tischnummer"
)->fetchAll();

// Reservierungen laden
$where  = [];
$params = [];

if ($filterEvent > 0) { $where[] = 'r.event_id = ?';  $params[] = $filterEvent; }
if ($filterStatus !== '') { $where[] = 'r.status = ?'; $params[] = $filterStatus; }
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
            t.tischnummer,
            p.status AS payment_status, p.zahlungsart
     FROM reservations r
     JOIN users u  ON r.user_id  = u.id
     JOIN events e ON r.event_id = e.id
     JOIN seats  s ON r.seat_id  = s.id
     JOIN tables t ON s.table_id = t.id
     LEFT JOIN payments p ON p.reservation_id = r.id
     {$whereClause}
     ORDER BY r.erstellt_am DESC"
);
$stmt->execute($params);
$reservierungen = $stmt->fetchAll();

// Statistik
$gesamt      = count($reservierungen);
$geplant     = count(array_filter($reservierungen, fn($r) => $r['status'] === 'geplant'));
$eingecheckt = count(array_filter($reservierungen, fn($r) => $r['status'] === 'eingecheckt'));
$storniert   = count(array_filter($reservierungen, fn($r) => $r['status'] === 'abgerechnet'));
$bezahlt     = count(array_filter($reservierungen, fn($r) => $r['payment_status'] === 'bezahlt'));
$umsatz      = array_sum(array_column(
    array_filter($reservierungen, fn($r) => $r['payment_status'] === 'bezahlt'), 'preis'
));

$pageTitle = 'Admin – Reservierungen';
$bodyClass = 'bg-light';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-ticket-perforated-fill text-secondary me-2"></i>Reservierungen
            </h1>
            <p class="text-muted mb-0 small">Einsehen · Erstellen · Stornieren · Löschen</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-warning"
                    data-bs-toggle="modal" data-bs-target="#neueResModal">
                <i class="bi bi-plus-circle me-1"></i>Neue Reservierung
            </button>
            <a href="/pages/admin_dashboard.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
        </div>
    </div>

    <?= getFlash() ?>

    <!-- Statistik-Kacheln -->
    <div class="row g-3 mb-4">
        <?php foreach ([
            [$gesamt,      'Gesamt',       ''],
            [$geplant,     'Geplant',      'warning'],
            [$eingecheckt, 'Eingecheckt',  'success'],
            [$storniert,   'Storniert',    'secondary'],
            [$bezahlt,     'Bezahlt',      'primary'],
            [formatBetrag($umsatz), 'Umsatz', 'info'],
        ] as [$val, $label, $color]): ?>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center <?= $color ? "border-top border-{$color} border-3" : '' ?>">
                <div class="card-body py-3">
                    <div class="fs-4 fw-bold <?= $color ? "text-{$color}" : '' ?>"><?= $val ?></div>
                    <small class="text-muted"><?= $label ?></small>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
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
                        <option value="<?= $ev['id'] ?>"
                                <?= $filterEvent === (int)$ev['id'] ? 'selected' : '' ?>>
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
                        <option value="abgerechnet" <?= $filterStatus === 'abgerechnet' ? 'selected' : '' ?>>Storniert</option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold small mb-1">Suche</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control"
                               placeholder="Name, E-Mail oder Buchungsnummer…"
                               value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-6 col-md-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-funnel"></i>
                    </button>
                </div>
                <div class="col-6 col-md-1">
                    <a href="/pages/admin_reservierungen.php"
                       class="btn btn-sm btn-outline-secondary w-100">
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
                            <th>Tisch</th>
                            <th>Status</th>
                            <th class="text-center">
                                <i class="bi bi-check-circle text-success me-1"></i>Bezahlt
                            </th>
                            <th>Preis</th>
                            <th>Datum</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($reservierungen as $r):
                        $istBezahlt = ($r['payment_status'] === 'bezahlt');
                    ?>
                    <tr>
                        <td>
                            <code class="text-dark fw-bold"><?= htmlspecialchars($r['buchungsnummer']) ?></code>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($r['vorname'] . ' ' . $r['nachname']) ?></div>
                            <a href="mailto:<?= htmlspecialchars($r['email']) ?>"
                               class="text-muted text-decoration-none" style="font-size:.8rem">
                                <?= htmlspecialchars($r['email']) ?>
                            </a>
                        </td>
                        <td>
                            <div><?= htmlspecialchars($r['event_name']) ?></div>
                            <small class="text-muted"><?= formatDatum($r['event_datum']) ?></small>
                        </td>
                        <td>
                            <span class="badge bg-dark">
                                Tisch <?= $r['tischnummer'] ?>
                            </span>
                        </td>
                        <td><?= statusBadge($r['status']) ?></td>

                        <!-- Bezahlt-Toggle (nur Admin sichtbar) -->
                        <td class="text-center">
                            <?php if ($r['payment_status'] !== 'storniert'): ?>
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="post_action"     value="toggle_bezahlt">
                                <input type="hidden" name="reservation_id"  value="<?= $r['id'] ?>">
                                <input type="hidden" name="filter_event_id" value="<?= $filterEvent ?>">
                                <input type="hidden" name="filter_status"   value="<?= htmlspecialchars($filterStatus) ?>">
                                <input type="hidden" name="filter_search"   value="<?= htmlspecialchars($search) ?>">
                                <button type="submit"
                                        class="btn btn-sm <?= $istBezahlt ? 'btn-success' : 'btn-outline-secondary' ?>"
                                        title="<?= $istBezahlt ? 'Als unbezahlt markieren' : 'Als bezahlt markieren' ?>"
                                        style="min-width:2.2rem;">
                                    <i class="bi <?= $istBezahlt ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="badge bg-secondary">Storniert</span>
                            <?php endif; ?>
                        </td>

                        <td><?= formatBetrag($r['preis']) ?></td>
                        <td>
                            <small class="text-muted"><?= formatDatum($r['erstellt_am']) ?></small>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <!-- Stornieren (nur wenn noch geplant) -->
                                <?php if ($r['status'] === 'geplant'): ?>
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="post_action"     value="cancel_reservation">
                                    <input type="hidden" name="reservation_id"  value="<?= $r['id'] ?>">
                                    <input type="hidden" name="filter_event_id" value="<?= $filterEvent ?>">
                                    <input type="hidden" name="filter_status"   value="<?= htmlspecialchars($filterStatus) ?>">
                                    <input type="hidden" name="filter_search"   value="<?= htmlspecialchars($search) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning"
                                            title="Stornieren"
                                            onclick="return confirm('Reservierung stornieren?');">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                </form>
                                <?php endif; ?>

                                <!-- Dauerhaft löschen -->
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="post_action"     value="delete_reservation">
                                    <input type="hidden" name="reservation_id"  value="<?= $r['id'] ?>">
                                    <input type="hidden" name="filter_event_id" value="<?= $filterEvent ?>">
                                    <input type="hidden" name="filter_status"   value="<?= htmlspecialchars($filterStatus) ?>">
                                    <input type="hidden" name="filter_search"   value="<?= htmlspecialchars($search) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            title="Endgültig löschen"
                                            onclick="return confirm('Reservierung &laquo;<?= htmlspecialchars($r['buchungsnummer']) ?>&raquo; endgültig löschen?\nDieser Vorgang kann nicht rückgängig gemacht werden!');">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </form>

                                <!-- Benutzer bearbeiten -->
                                <a href="/pages/admin_users.php?action=edit&user_id=<?= $r['user_id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Benutzer">
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

<!-- Modal: Neue Reservierung -->
<div class="modal fade" id="neueResModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-plus-circle me-2"></i>Neue Reservierung
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="post_action" value="create_reservation">
                <div class="modal-body">

                    <!-- Benutzer -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Gast (Benutzer)</label>
                        <select name="new_user_id" class="form-select" required>
                            <option value="">-- Benutzer auswählen --</option>
                            <?php foreach ($alleUsers as $u): ?>
                            <option value="<?= $u['id'] ?>">
                                <?= htmlspecialchars($u['vorname'] . ' ' . $u['nachname'] . ' (' . $u['email'] . ')') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Event + Tisch -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Event filtern</label>
                        <select class="form-select form-select-sm" id="filterEventModal"
                                onchange="filterTische(this.value)">
                            <option value="">Alle Events anzeigen</option>
                            <?php foreach ($alleEvents as $ev): ?>
                            <option value="<?= $ev['id'] ?>">
                                <?= htmlspecialchars(formatDatum($ev['datum']) . ' – ' . $ev['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tisch</label>
                        <select name="new_table_id" id="tischSelect" class="form-select" required>
                            <option value="">-- Tisch auswählen --</option>
                            <?php foreach ($alleTische as $t): ?>
                            <option value="<?= $t['id'] ?>"
                                    data-event="<?= $t['event_id'] ?>"
                                    data-frei="<?= (int)$t['frei'] ?>">
                                <?= htmlspecialchars(
                                    formatDatum($t['event_datum']) . ' ' . $t['event_name'] .
                                    ' – Tisch ' . $t['tischnummer'] .
                                    ' (' . (int)$t['frei'] . ' frei)'
                                ) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Anzahl -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Anzahl Plätze</label>
                        <input type="number" name="new_anzahl" id="neueAnzahl"
                               class="form-control" min="1" max="20" value="1" required>
                        <small class="text-muted" id="freiHinweis"></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary"
                            data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-warning fw-bold">
                        <i class="bi bi-check2-circle me-1"></i>Reservieren
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function filterTische(eventId) {
    var sel = document.getElementById("tischSelect");
    var opts = sel.querySelectorAll("option");
    for (var i = 0; i < opts.length; i++) {
        var opt = opts[i];
        if (!opt.value) continue;  /* Leer-Option immer zeigen */
        if (!eventId || opt.getAttribute("data-event") === eventId) {
            opt.style.display = "";
        } else {
            opt.style.display = "none";
        }
    }
    sel.value = "";
    document.getElementById("freiHinweis").textContent = "";
}

document.getElementById("tischSelect").addEventListener("change", function() {
    var opt = this.options[this.selectedIndex];
    var frei = parseInt(opt.getAttribute("data-frei") || "0");
    var anzEl = document.getElementById("neueAnzahl");
    var hint  = document.getElementById("freiHinweis");
    if (this.value && frei > 0) {
        anzEl.max = frei;
        if (parseInt(anzEl.value) > frei) anzEl.value = frei;
        hint.textContent = frei + " Platz/Plätze an diesem Tisch frei";
        hint.className = "text-success small";
    } else if (this.value && frei === 0) {
        hint.textContent = "Dieser Tisch ist ausgebucht!";
        hint.className = "text-danger small";
        anzEl.max = 0;
    } else {
        hint.textContent = "";
        anzEl.max = 20;
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

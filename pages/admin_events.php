<?php
/**
 * Admin Event-Management
 * Vollständiges CRUD für Events, Tische, Sitzplätze und manuelle Reservierungen.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

requireRole('admin');

$pdo    = getDB();
$action = $_GET['action'] ?? '';
$errors = [];

// ═══════════════════════════════════════════════════════════════════════════════
// POST-Handler
// ═══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiges CSRF-Token. Bitte erneut versuchen.');
        redirect('/pages/admin_events.php');
    }

    $postAction = $_POST['post_action'] ?? '';

    // ── Event erstellen ──────────────────────────────────────────────────────
    if ($postAction === 'create_event') {
        $datum        = sanitize($_POST['datum'] ?? '');
        $name         = sanitize($_POST['name'] ?? '');
        $beschreibung = trim($_POST['beschreibung'] ?? '');
        $max_gaeste   = (int)($_POST['max_gaeste'] ?? 0);
        $preis        = round((float)str_replace(',', '.', $_POST['preis'] ?? '0'), 2);
        $status       = in_array($_POST['status'] ?? '', ['planung','aktiv','abgerechnet'])
                        ? $_POST['status'] : 'planung';

        if (empty($datum))    $errors[] = 'Datum ist erforderlich.';
        if (empty($name))     $errors[] = 'Name ist erforderlich.';
        if ($max_gaeste < 1)  $errors[] = 'Max. Gäste muss mindestens 1 sein.';
        if ($preis <= 0)      $errors[] = 'Ticket-Preis muss größer als 0 sein.';

        if (empty($errors)) {
            $stmt = $pdo->prepare(
                'INSERT INTO events (datum, name, beschreibung, max_gaeste, preis, status) VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([$datum, $name, $beschreibung, $max_gaeste, $preis, $status]);
            $newId = (int)$pdo->lastInsertId();
            logAudit('CREATE', 'events', $newId, json_encode(compact('datum','name','status','max_gaeste','preis')));
            setFlash('success', 'Event "' . htmlspecialchars($name) . '" wurde erfolgreich erstellt.');
            redirect('/pages/admin_events.php');
        }
    }

    // ── Event bearbeiten ─────────────────────────────────────────────────────
    if ($postAction === 'edit_event') {
        $id           = (int)($_POST['event_id'] ?? 0);
        $datum        = sanitize($_POST['datum'] ?? '');
        $name         = sanitize($_POST['name'] ?? '');
        $beschreibung = trim($_POST['beschreibung'] ?? '');
        $max_gaeste   = (int)($_POST['max_gaeste'] ?? 0);
        $preis        = round((float)str_replace(',', '.', $_POST['preis'] ?? '0'), 2);
        $status       = in_array($_POST['status'] ?? '', ['planung','aktiv','abgerechnet'])
                        ? $_POST['status'] : 'planung';

        if (empty($datum))    $errors[] = 'Datum ist erforderlich.';
        if (empty($name))     $errors[] = 'Name ist erforderlich.';
        if ($max_gaeste < 1)  $errors[] = 'Max. Gäste muss mindestens 1 sein.';
        if ($preis <= 0)      $errors[] = 'Ticket-Preis muss größer als 0 sein.';

        if (empty($errors) && $id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE events SET datum=?, name=?, beschreibung=?, max_gaeste=?, preis=?, status=? WHERE id=?'
            );
            $stmt->execute([$datum, $name, $beschreibung, $max_gaeste, $preis, $status, $id]);
            logAudit('UPDATE', 'events', $id, json_encode(compact('datum','name','status','max_gaeste','preis')));
            setFlash('success', 'Event wurde erfolgreich aktualisiert.');
            redirect('/pages/admin_events.php');
        }
    }

    // ── Event löschen ────────────────────────────────────────────────────────
    if ($postAction === 'delete_event') {
        $id = (int)($_POST['event_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT name FROM events WHERE id=?');
            $stmt->execute([$id]);
            $ev = $stmt->fetch();
            if ($ev) {
                $pdo->prepare('DELETE FROM events WHERE id=?')->execute([$id]);
                logAudit('DELETE', 'events', $id, json_encode(['name' => $ev['name']]));
                setFlash('success', 'Event "' . htmlspecialchars($ev['name']) . '" wurde gelöscht.');
            }
        }
        redirect('/pages/admin_events.php');
    }

    // ── Event als abgerechnet markieren ──────────────────────────────────────
    if ($postAction === 'mark_abgerechnet') {
        $id = (int)($_POST['event_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE events SET status='abgerechnet' WHERE id=?")->execute([$id]);
            $pdo->prepare(
                "UPDATE reservations SET status='abgerechnet' WHERE event_id=? AND status='eingecheckt'"
            )->execute([$id]);
            logAudit('UPDATE', 'events', $id, 'Status gesetzt auf: abgerechnet');
            setFlash('success', 'Event wurde als abgerechnet markiert.');
        }
        redirect('/pages/admin_events.php');
    }

    // ── Tisch hinzufügen ─────────────────────────────────────────────────────
    if ($postAction === 'add_table') {
        $event_id    = (int)($_POST['event_id'] ?? 0);
        $tischnummer = (int)($_POST['tischnummer'] ?? 0);
        $max_plaetze = (int)($_POST['max_plaetze'] ?? 0);

        if ($tischnummer < 1) $errors[] = 'Tischnummer muss mindestens 1 sein.';
        if ($max_plaetze < 1) $errors[] = 'Anzahl Plätze muss mindestens 1 sein.';

        if (empty($errors) && $event_id > 0) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO tables (event_id, tischnummer, max_plaetze) VALUES (?,?,?)'
                );
                $stmt->execute([$event_id, $tischnummer, $max_plaetze]);
                $tableId = (int)$pdo->lastInsertId();

                // Sitzplätze automatisch generieren
                $seatStmt = $pdo->prepare(
                    'INSERT INTO seats (table_id, sitzplatznummer) VALUES (?,?)'
                );
                for ($s = 1; $s <= $max_plaetze; $s++) {
                    $seatStmt->execute([$tableId, $s]);
                }
                logAudit('CREATE', 'tables', $tableId,
                    json_encode(['event_id'=>$event_id,'tischnummer'=>$tischnummer,'max_plaetze'=>$max_plaetze]));
                setFlash('success', "Tisch {$tischnummer} mit {$max_plaetze} Plätzen wurde hinzugefügt.");
            } catch (PDOException $e) {
                setFlash('error', 'Tischnummer existiert bereits für dieses Event.');
            }
        } else {
            setFlash('error', implode(' ', $errors));
        }
        redirect('/pages/admin_events.php?action=tables&event_id=' . $event_id);
    }

    // ── Tische per CSV hochladen ─────────────────────────────────────────────
    if ($postAction === 'bulk_tables') {
        $event_id = (int)($_POST['event_id'] ?? 0);
        $csv      = trim($_POST['csv_data'] ?? '');
        $lines    = array_filter(explode("\n", $csv));
        $added    = 0;
        $skipped  = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $parts = explode(',', $line);
            if (count($parts) < 2) { $skipped++; continue; }
            $tischnummer = (int)trim($parts[0]);
            $max_plaetze = (int)trim($parts[1]);
            if ($tischnummer < 1 || $max_plaetze < 1) { $skipped++; continue; }

            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO tables (event_id, tischnummer, max_plaetze) VALUES (?,?,?)'
                );
                $stmt->execute([$event_id, $tischnummer, $max_plaetze]);
                $tableId = (int)$pdo->lastInsertId();
                $seatStmt = $pdo->prepare(
                    'INSERT INTO seats (table_id, sitzplatznummer) VALUES (?,?)'
                );
                for ($s = 1; $s <= $max_plaetze; $s++) {
                    $seatStmt->execute([$tableId, $s]);
                }
                $added++;
            } catch (PDOException $e) {
                $skipped++;
            }
        }
        if ($added > 0) {
            logAudit('CREATE', 'tables', $event_id, "CSV-Import: {$added} Tische hinzugefügt");
        }
        setFlash('success', "{$added} Tisch/Tische importiert, {$skipped} übersprungen.");
        redirect('/pages/admin_events.php?action=tables&event_id=' . $event_id);
    }

    // ── Tisch löschen ────────────────────────────────────────────────────────
    if ($postAction === 'delete_table') {
        $table_id = (int)($_POST['table_id'] ?? 0);
        $event_id = (int)($_POST['event_id'] ?? 0);
        if ($table_id > 0) {
            $pdo->prepare('DELETE FROM tables WHERE id=?')->execute([$table_id]);
            logAudit('DELETE', 'tables', $table_id, json_encode(['event_id'=>$event_id]));
            setFlash('success', 'Tisch wurde gelöscht.');
        }
        redirect('/pages/admin_events.php?action=tables&event_id=' . $event_id);
    }

    // ── Manuelle Reservierung erstellen ──────────────────────────────────────
    if ($postAction === 'manual_reservation') {
        $event_id    = (int)($_POST['event_id'] ?? 0);
        $user_id     = (int)($_POST['user_id'] ?? 0);
        $seat_id     = (int)($_POST['seat_id'] ?? 0);
        $zahlungsart = in_array($_POST['zahlungsart'] ?? '', ['bar','ueberweisung','paypal'])
                       ? $_POST['zahlungsart'] : 'bar';
        $pay_status  = in_array($_POST['pay_status'] ?? '', ['offen','bezahlt'])
                       ? $_POST['pay_status'] : 'offen';

        if ($event_id < 1) $errors[] = 'Event auswählen.';
        if ($user_id < 1)  $errors[] = 'Benutzer auswählen.';
        if ($seat_id < 1)  $errors[] = 'Sitzplatz auswählen.';

        if (empty($errors)) {
            // Sitzplatz verfügbar?
            $seatChk = $pdo->prepare("SELECT status FROM seats WHERE id=?");
            $seatChk->execute([$seat_id]);
            $seatRow = $seatChk->fetch();
            if (!$seatRow || $seatRow['status'] !== 'verfuegbar') {
                $errors[] = 'Dieser Sitzplatz ist nicht verfügbar.';
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                // Event-Preis laden
                $evPreisRow = $pdo->prepare('SELECT preis FROM events WHERE id = ?');
                $evPreisRow->execute([$event_id]);
                $eventPreis = (float)($evPreisRow->fetchColumn() ?: TICKET_PREIS);

                $buchungsnummer = generateBuchungsnummer();
                $stmt = $pdo->prepare(
                    'INSERT INTO reservations (user_id, event_id, seat_id, buchungsnummer, status, preis)
                     VALUES (?,?,?,?,\'geplant\',?)'
                );
                $stmt->execute([$user_id, $event_id, $seat_id, $buchungsnummer, $eventPreis]);
                $resId = (int)$pdo->lastInsertId();

                // Sitzplatz als reserviert markieren
                $pdo->prepare("UPDATE seats SET status='reserviert' WHERE id=?")->execute([$seat_id]);

                // Zahlung erstellen
                $pdo->prepare(
                    'INSERT INTO payments (reservation_id, zahlungsart, status, betrag) VALUES (?,?,?,?)'
                )->execute([$resId, $zahlungsart, $pay_status, $eventPreis]);

                $pdo->commit();
                logAudit('CREATE', 'reservations', $resId,
                    json_encode(compact('buchungsnummer','event_id','user_id','seat_id')));
                setFlash('success', "Reservierung {$buchungsnummer} erfolgreich erstellt.");
            } catch (PDOException $e) {
                $pdo->rollBack();
                setFlash('error', 'Fehler beim Erstellen der Reservierung: ' . $e->getMessage());
            }
        } else {
            setFlash('error', implode(' ', $errors));
        }
        redirect('/pages/admin_events.php#manual-reservation');
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// Daten laden
// ═══════════════════════════════════════════════════════════════════════════════

// Alle Events mit Sitzplatz-Statistik
$events = $pdo->query(
    "SELECT e.*,
            COUNT(DISTINCT t.id) AS tische_anzahl,
            COUNT(DISTINCT s.id) AS sitze_gesamt,
            SUM(CASE WHEN s.status != 'verfuegbar' THEN 1 ELSE 0 END) AS sitze_belegt
     FROM events e
     LEFT JOIN tables t ON t.event_id = e.id
     LEFT JOIN seats s ON s.table_id = t.id
     GROUP BY e.id
     ORDER BY e.datum DESC"
)->fetchAll();

// Editier-Modus: Event laden
$editEvent = null;
if ($action === 'edit' && isset($_GET['event_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id=?');
    $stmt->execute([(int)$_GET['event_id']]);
    $editEvent = $stmt->fetch();
}

// Tisch-Management-Modus
$manageEvent  = null;
$eventTables  = [];
if ($action === 'tables' && isset($_GET['event_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id=?');
    $stmt->execute([(int)$_GET['event_id']]);
    $manageEvent = $stmt->fetch();
    if ($manageEvent) {
        $tStmt = $pdo->prepare(
            "SELECT t.*, COUNT(s.id) AS sitze_gesamt,
                    SUM(CASE WHEN s.status='verfuegbar' THEN 1 ELSE 0 END) AS sitze_frei
             FROM tables t
             LEFT JOIN seats s ON s.table_id = t.id
             WHERE t.event_id=?
             GROUP BY t.id
             ORDER BY t.tischnummer"
        );
        $tStmt->execute([$manageEvent['id']]);
        $eventTables = $tStmt->fetchAll();
    }
}

// Für manuelle Reservierung: Benutzer & Events
$allUsers  = $pdo->query(
    "SELECT id, vorname, nachname, email FROM users WHERE aktiv=1 ORDER BY nachname, vorname"
)->fetchAll();
$activeEvents4Res = $pdo->query(
    "SELECT id, name, datum FROM events WHERE status='aktiv' ORDER BY datum"
)->fetchAll();

$pageTitle = 'Admin – Event-Management';
$bodyClass = 'bg-light';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container-fluid py-4">

    <!-- Seitentitel -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-calendar-plus text-primary me-2"></i>Event-Management
            </h1>
            <p class="text-muted mb-0 small">Events erstellen, bearbeiten und Tische verwalten</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="/pages/admin_dashboard.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createEventModal">
                <i class="bi bi-plus-circle me-1"></i>Neues Event
            </button>
        </div>
    </div>

    <?= getFlash() ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible">
        <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?></ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ═══ Tisch-Management (wenn aktiv) ══════════════════════════════════════ -->
    <?php if ($manageEvent): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex align-items-center justify-content-between">
            <h5 class="mb-0 fw-semibold">
                <i class="bi bi-grid-3x3 me-2"></i>
                Tische verwalten: <?= htmlspecialchars($manageEvent['name']) ?>
                <span class="badge bg-white text-primary ms-2"><?= formatDatum($manageEvent['datum']) ?></span>
            </h5>
            <a href="/pages/admin_events.php" class="btn btn-sm btn-light">
                <i class="bi bi-x me-1"></i>Schließen
            </a>
        </div>
        <div class="card-body">
            <div class="row g-4">

                <!-- Einzelnen Tisch hinzufügen -->
                <div class="col-12 col-lg-4">
                    <h6 class="fw-semibold text-primary mb-3">
                        <i class="bi bi-plus-circle me-1"></i>Tisch hinzufügen
                    </h6>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="post_action" value="add_table">
                        <input type="hidden" name="event_id" value="<?= $manageEvent['id'] ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Tischnummer</label>
                            <input type="number" name="tischnummer" class="form-control" min="1" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Anzahl Plätze</label>
                            <input type="number" name="max_plaetze" class="form-control" min="1" max="20" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-plus me-1"></i>Tisch & Sitzplätze anlegen
                        </button>
                    </form>

                    <hr>

                    <!-- CSV-Massenimport -->
                    <h6 class="fw-semibold text-secondary mb-3">
                        <i class="bi bi-upload me-1"></i>Massen-Import via CSV
                    </h6>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="post_action" value="bulk_tables">
                        <input type="hidden" name="event_id" value="<?= $manageEvent['id'] ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">
                                CSV-Daten <span class="text-muted">(tischnummer,max_plaetze je Zeile)</span>
                            </label>
                            <textarea name="csv_data" class="form-control font-monospace" rows="6"
                                      placeholder="1,6&#10;2,6&#10;3,8&#10;4,4"></textarea>
                        </div>
                        <button type="submit" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-table me-1"></i>CSV importieren
                        </button>
                    </form>
                </div>

                <!-- Tabellenliste -->
                <div class="col-12 col-lg-8">
                    <h6 class="fw-semibold mb-3">
                        <i class="bi bi-list-ul me-1"></i>Vorhandene Tische
                        <span class="badge bg-secondary ms-1"><?= count($eventTables) ?></span>
                    </h6>
                    <?php if (empty($eventTables)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>Noch keine Tische angelegt.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Tisch-Nr.</th>
                                    <th>Plätze gesamt</th>
                                    <th>Plätze frei</th>
                                    <th>Auslastung</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($eventTables as $t):
                                $belegt  = $t['sitze_gesamt'] - $t['sitze_frei'];
                                $pct     = $t['sitze_gesamt'] > 0
                                           ? round(($belegt / $t['sitze_gesamt']) * 100) : 0;
                                $barClass = $pct >= 90 ? 'bg-danger' : ($pct >= 60 ? 'bg-warning' : 'bg-success');
                            ?>
                            <tr>
                                <td><strong>Tisch <?= (int)$t['tischnummer'] ?></strong></td>
                                <td><?= (int)$t['sitze_gesamt'] ?></td>
                                <td><?= (int)$t['sitze_frei'] ?></td>
                                <td style="min-width:120px;">
                                    <div class="progress" style="height:8px;">
                                        <div class="progress-bar <?= $barClass ?>"
                                             style="width:<?= $pct ?>%"></div>
                                    </div>
                                    <small class="text-muted"><?= $pct ?>%</small>
                                </td>
                                <td>
                                    <form method="post" class="d-inline"
                                          onsubmit="return confirm('Tisch <?= (int)$t['tischnummer'] ?> wirklich löschen? Alle Sitzplätze und Reservierungen werden gelöscht!');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="post_action" value="delete_table">
                                        <input type="hidden" name="table_id" value="<?= $t['id'] ?>">
                                        <input type="hidden" name="event_id" value="<?= $manageEvent['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ Event-Edit-Inline (wenn aktiv) ══════════════════════════════════════ -->
    <?php if ($editEvent): ?>
    <div class="card border-0 shadow-sm border-start border-warning border-4 mb-4">
        <div class="card-header bg-warning bg-opacity-10 d-flex align-items-center justify-content-between">
            <h5 class="mb-0 fw-semibold text-warning">
                <i class="bi bi-pencil-square me-2"></i>Event bearbeiten: <?= htmlspecialchars($editEvent['name']) ?>
            </h5>
            <a href="/pages/admin_events.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-x"></i>
            </a>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrfField() ?>
                <input type="hidden" name="post_action" value="edit_event">
                <input type="hidden" name="event_id" value="<?= $editEvent['id'] ?>">
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Datum <span class="text-danger">*</span></label>
                    <input type="date" name="datum" class="form-control"
                           value="<?= htmlspecialchars($editEvent['datum']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold small">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" maxlength="255"
                           value="<?= htmlspecialchars($editEvent['name']) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small">Max. Gäste <span class="text-danger">*</span></label>
                    <input type="number" name="max_gaeste" class="form-control" min="1"
                           value="<?= (int)$editEvent['max_gaeste'] ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small">Ticket-Preis (€) <span class="text-danger">*</span></label>
                    <input type="number" name="preis" class="form-control" min="0.01" step="0.01"
                           value="<?= number_format((float)($editEvent['preis'] ?? 15.00), 2, '.', '') ?>" required>
                </div>
                <div class="col-md-1">
                    <label class="form-label fw-semibold small">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['planung','aktiv','abgerechnet'] as $s): ?>
                        <option value="<?= $s ?>"
                            <?= $editEvent['status'] === $s ? 'selected' : '' ?>>
                            <?= ucfirst($s) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold small">Beschreibung</label>
                    <textarea name="beschreibung" class="form-control" rows="2"
                    ><?= htmlspecialchars($editEvent['beschreibung'] ?? '') ?></textarea>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-save me-1"></i>Speichern
                    </button>
                    <a href="/pages/admin_events.php" class="btn btn-outline-secondary">Abbrechen</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ Event-Liste ═════════════════════════════════════════════════════════ -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between">
            <h5 class="mb-0 fw-semibold">
                <i class="bi bi-calendar-week me-2 text-primary"></i>Alle Events
                <span class="badge bg-secondary ms-1"><?= count($events) ?></span>
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($events)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
                Noch keine Events vorhanden.
                <div class="mt-3">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createEventModal">
                        <i class="bi bi-plus me-1"></i>Erstes Event erstellen
                    </button>
                </div>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Datum</th>
                            <th>Name</th>
                            <th>Max. Gäste</th>
                            <th>Preis</th>
                            <th>Tische</th>
                            <th>Sitze</th>
                            <th>Belegt</th>
                            <th>Status</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($events as $ev):
                        $sitze   = (int)$ev['sitze_gesamt'];
                        $belegt  = (int)$ev['sitze_belegt'];
                        $frei    = $sitze - $belegt;
                        $pct     = $sitze > 0 ? round(($belegt / $sitze) * 100) : 0;
                        $barCls  = $pct >= 90 ? 'bg-danger' : ($pct >= 60 ? 'bg-warning' : 'bg-success');
                    ?>
                    <tr>
                        <td class="text-muted small">#<?= $ev['id'] ?></td>
                        <td>
                            <span class="fw-semibold"><?= formatDatum($ev['datum']) ?></span>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($ev['name']) ?></div>
                            <?php if (!empty($ev['beschreibung'])): ?>
                            <small class="text-muted d-block text-truncate" style="max-width:250px;">
                                <?= htmlspecialchars($ev['beschreibung']) ?>
                            </small>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$ev['max_gaeste'] ?></td>
                        <td class="fw-bold text-success"><?= formatBetrag((float)($ev['preis'] ?? 0)) ?></td>
                        <td><span class="badge bg-secondary"><?= (int)$ev['tische_anzahl'] ?></span></td>
                        <td><?= $sitze ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2" style="min-width:120px;">
                                <div class="progress flex-grow-1" style="height:6px;">
                                    <div class="progress-bar <?= $barCls ?>"
                                         style="width:<?= $pct ?>%"></div>
                                </div>
                                <small class="text-nowrap"><?= $belegt ?>/<?= $sitze ?></small>
                            </div>
                        </td>
                        <td><?= statusBadge($ev['status']) ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="/pages/admin_events.php?action=tables&event_id=<?= $ev['id'] ?>"
                                   class="btn btn-outline-primary" title="Tische verwalten">
                                    <i class="bi bi-grid-3x3"></i>
                                </a>
                                <a href="/pages/admin_events.php?action=edit&event_id=<?= $ev['id'] ?>"
                                   class="btn btn-outline-warning" title="Bearbeiten">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ($ev['status'] !== 'abgerechnet'): ?>
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('Event als abgerechnet markieren?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="post_action" value="mark_abgerechnet">
                                    <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                                    <button type="submit" class="btn btn-outline-success"
                                            title="Als abgerechnet markieren">
                                        <i class="bi bi-check-circle"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('Event und alle zugehörigen Daten wirklich löschen?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="post_action" value="delete_event">
                                    <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger" title="Löschen">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
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

    <!-- ═══ Manuelle Reservierung ════════════════════════════════════════════════ -->
    <div class="card border-0 shadow-sm" id="manual-reservation">
        <div class="card-header bg-white border-bottom d-flex align-items-center">
            <h5 class="mb-0 fw-semibold">
                <i class="bi bi-ticket-perforated me-2 text-success"></i>Manuelle Reservierung erstellen
            </h5>
        </div>
        <div class="card-body">
            <form method="post" id="manualResForm" class="row g-3">
                <?= csrfField() ?>
                <input type="hidden" name="post_action" value="manual_reservation">

                <div class="col-md-4">
                    <label class="form-label fw-semibold small">Event <span class="text-danger">*</span></label>
                    <select name="event_id" id="res_event" class="form-select" required onchange="loadSeats(this.value)">
                        <option value="">-- Event wählen --</option>
                        <?php foreach ($activeEvents4Res as $ev): ?>
                        <option value="<?= $ev['id'] ?>">
                            <?= htmlspecialchars($ev['name']) ?> (<?= formatDatum($ev['datum']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold small">
                        Benutzer <span class="text-danger">*</span>
                        <small class="text-muted fw-normal">(suchen)</small>
                    </label>
                    <input type="text" id="userSearchInput" class="form-control mb-1"
                           placeholder="Name oder E-Mail suchen..." autocomplete="off">
                    <input type="hidden" name="user_id" id="res_user_id" required>
                    <div id="userSearchResults" class="list-group" style="max-height:160px;overflow-y:auto;"></div>
                    <div id="selectedUser" class="text-success small mt-1"></div>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold small">Sitzplatz <span class="text-danger">*</span></label>
                    <select name="seat_id" id="res_seat" class="form-select" required>
                        <option value="">-- Zuerst Event wählen --</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Zahlungsart</label>
                    <select name="zahlungsart" class="form-select">
                        <option value="bar">Bar</option>
                        <option value="ueberweisung">Überweisung</option>
                        <option value="paypal">PayPal</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Zahlungsstatus</label>
                    <select name="pay_status" class="form-select">
                        <option value="offen">Offen</option>
                        <option value="bezahlt">Bezahlt</option>
                    </select>
                </div>

                <div class="col-md-3 d-flex align-items-end">
                    <div class="w-100">
                        <p class="mb-1 fw-semibold small">Preis</p>
                        <p class="mb-0 text-success fw-bold fs-5"><?= formatBetrag(TICKET_PREIS) ?></p>
                    </div>
                </div>

                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-plus-circle me-1"></i>Reservierung erstellen
                    </button>
                </div>
            </form>
        </div>
    </div>

</main>

<!-- ═══ Modal: Neues Event ══════════════════════════════════════════════════════ -->
<div class="modal fade" id="createEventModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold">
                    <i class="bi bi-calendar-plus me-2 text-primary"></i>Neues Event erstellen
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="post_action" value="create_event">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Datum <span class="text-danger">*</span></label>
                            <input type="date" name="datum" class="form-control"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold small">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" maxlength="255"
                                   placeholder="z.B. Karneval 2026" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Max. Gäste <span class="text-danger">*</span></label>
                            <input type="number" name="max_gaeste" class="form-control" min="1" value="200" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small">Ticket-Preis (€) <span class="text-danger">*</span></label>
                            <input type="number" name="preis" class="form-control" min="0.01" step="0.01" value="15.00" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small">Status</label>
                            <select name="status" class="form-select">
                                <option value="planung">In Planung</option>
                                <option value="aktiv" selected>Aktiv</option>
                                <option value="abgerechnet">Abgerechnet</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold small">Beschreibung</label>
                            <textarea name="beschreibung" class="form-control" rows="2"
                                      placeholder="Kurze Beschreibung des Events..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus me-1"></i>Event erstellen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// Benutzerdaten für JS
$usersJson = json_encode(array_map(fn($u) => [
    'id'    => $u['id'],
    'label' => $u['vorname'] . ' ' . $u['nachname'] . ' – ' . $u['email'],
    'email' => $u['email'],
], $allUsers));

$extraScripts = '<script>
// Benutzer-Suche für manuelle Reservierung
const allUsers = ' . $usersJson . ';
const userInput = document.getElementById("userSearchInput");
const userResults = document.getElementById("userSearchResults");
const userIdField = document.getElementById("res_user_id");
const selectedUserDiv = document.getElementById("selectedUser");

if (userInput) {
    userInput.addEventListener("input", function() {
        const q = this.value.toLowerCase().trim();
        userResults.innerHTML = "";
        if (!q) return;
        const matches = allUsers.filter(u => u.label.toLowerCase().includes(q)).slice(0, 8);
        matches.forEach(u => {
            const a = document.createElement("a");
            a.href = "#";
            a.className = "list-group-item list-group-item-action small";
            a.textContent = u.label;
            a.addEventListener("click", function(e) {
                e.preventDefault();
                userIdField.value = u.id;
                userInput.value = u.label;
                selectedUserDiv.textContent = "Ausgewählt: " + u.label;
                userResults.innerHTML = "";
            });
            userResults.appendChild(a);
        });
    });
}

// Sitzplätze laden wenn Event gewählt
function loadSeats(eventId) {
    const seatSel = document.getElementById("res_seat");
    seatSel.innerHTML = "<option>Wird geladen...</option>";
    if (!eventId) {
        seatSel.innerHTML = "<option value=\"\">-- Zuerst Event wählen --</option>";
        return;
    }
    fetch("/api/seats.php?event_id=" + eventId + "&status=verfuegbar")
        .then(r => r.json())
        .then(data => {
            seatSel.innerHTML = "<option value=\"\">-- Sitzplatz wählen --</option>";
            if (data.seats && data.seats.length > 0) {
                data.seats.forEach(s => {
                    const opt = document.createElement("option");
                    opt.value = s.id;
                    opt.textContent = "Tisch " + s.tischnummer + " – Platz " + s.sitzplatznummer;
                    seatSel.appendChild(opt);
                });
            } else {
                seatSel.innerHTML = "<option value=\"\">Keine freien Plätze</option>";
            }
        })
        .catch(() => {
            seatSel.innerHTML = "<option value=\"\">Fehler beim Laden</option>";
        });
}

// Modal öffnen wenn action=create in URL
' . ($action === 'create' ? '
document.addEventListener("DOMContentLoaded", function() {
    const modal = new bootstrap.Modal(document.getElementById("createEventModal"));
    modal.show();
});' : '') . '
</script>';

include __DIR__ . '/../includes/footer.php';
?>

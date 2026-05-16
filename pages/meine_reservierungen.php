<?php
/**
 * Meine Reservierungen - Übersicht für eingeloggte Benutzer
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Reservierungen laden (neueste zuerst)
$stmt = $pdo->prepare(
    'SELECT r.id, r.buchungsnummer, r.status, r.preis, r.erstellt_am,
            e.name AS event_name, e.datum AS event_datum,
            t.tischnummer,
            s.sitzplatznummer,
            p.zahlungsart, p.status AS payment_status, p.betrag
     FROM reservations r
     JOIN events e  ON r.event_id = e.id
     JOIN seats  s  ON r.seat_id  = s.id
     JOIN tables t  ON s.table_id = t.id
     LEFT JOIN payments p ON p.reservation_id = r.id
     WHERE r.user_id = ?
     ORDER BY r.erstellt_am DESC'
);
$stmt->execute([$userId]);
$reservierungen = $stmt->fetchAll();

// Statistiken
$gesamt     = count($reservierungen);
$geplant    = count(array_filter($reservierungen, fn($r) => $r['status'] === 'geplant'));
$eingecheckt = count(array_filter($reservierungen, fn($r) => $r['status'] === 'eingecheckt'));
$gesamtBetrag = array_sum(array_column($reservierungen, 'betrag'));

$pageTitle = 'Meine Reservierungen';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="py-4">
    <div class="container">
        <?= getFlash() ?>

        <div class="row mb-4">
            <div class="col-12">
                <h2 class="fw-bold">
                    <i class="bi bi-ticket-perforated text-warning me-2"></i>Meine Reservierungen
                </h2>
                <p class="text-muted">Alle Ihre Buchungen im Überblick</p>
            </div>
        </div>

        <!-- KPI-Karten -->
        <div class="row g-3 mb-4">
            <div class="col-sm-3">
                <div class="card text-center border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="display-6 fw-bold text-primary"><?= $gesamt ?></div>
                        <small class="text-muted">Gesamt</small>
                    </div>
                </div>
            </div>
            <div class="col-sm-3">
                <div class="card text-center border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="display-6 fw-bold text-secondary"><?= $geplant ?></div>
                        <small class="text-muted">Geplant</small>
                    </div>
                </div>
            </div>
            <div class="col-sm-3">
                <div class="card text-center border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="display-6 fw-bold text-success"><?= $eingecheckt ?></div>
                        <small class="text-muted">Eingecheckt</small>
                    </div>
                </div>
            </div>
            <div class="col-sm-3">
                <div class="card text-center border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="display-6 fw-bold text-warning"><?= formatBetrag($gesamtBetrag) ?></div>
                        <small class="text-muted">Gesamtbetrag</small>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($reservierungen)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-calendar-x display-3 text-muted d-block mb-3"></i>
                <h5 class="text-muted">Noch keine Reservierungen</h5>
                <p class="text-muted">Sie haben noch keine Plätze reserviert.</p>
                <a href="/pages/events.php" class="btn btn-warning">
                    <i class="bi bi-calendar-event me-2"></i>Events entdecken
                </a>
            </div>
        </div>
        <?php else: ?>

        <!-- Reservierungen Tabelle -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Buchungsnr.</th>
                                <th>Veranstaltung</th>
                                <th>Datum</th>
                                <th>Sitzplatz</th>
                                <th>Zahlungsart</th>
                                <th>Betrag</th>
                                <th>Buchungsstatus</th>
                                <th>Zahlungsstatus</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservierungen as $res): ?>
                            <tr>
                                <td>
                                    <code class="fs-6 text-primary fw-bold"><?= htmlspecialchars($res['buchungsnummer']) ?></code>
                                </td>
                                <td><?= htmlspecialchars($res['event_name']) ?></td>
                                <td>
                                    <span class="badge bg-warning text-dark">
                                        <?= formatDatum($res['event_datum']) ?>
                                    </span>
                                </td>
                                <td>
                                    <i class="bi bi-grid text-muted me-1"></i>
                                    Tisch <strong><?= $res['tischnummer'] ?></strong>, Platz <strong><?= $res['sitzplatznummer'] ?></strong>
                                </td>
                                <td>
                                    <?php
                                    $icon = match($res['zahlungsart'] ?? '') {
                                        'paypal'       => 'bi-paypal text-primary',
                                        'ueberweisung' => 'bi-bank text-info',
                                        default        => 'bi-cash text-success',
                                    };
                                    ?>
                                    <i class="bi <?= $icon ?> me-1"></i>
                                    <?= zahlungsartLabel($res['zahlungsart'] ?? 'bar') ?>
                                </td>
                                <td class="fw-bold"><?= formatBetrag((float)($res['betrag'] ?? 0)) ?></td>
                                <td><?= statusBadge($res['status']) ?></td>
                                <td><?= statusBadge($res['payment_status'] ?? 'offen') ?></td>
                                <td>
                                    <?php if ($res['status'] === 'geplant'): ?>
                                    <form method="POST" action="/api/reserve_seat.php"
                                          onsubmit="return confirm('Möchten Sie diese Reservierung wirklich stornieren?')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="event_id" value="">
                                        <input type="hidden" name="reservation_id" value="<?= $res['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="bi bi-x-circle me-1"></i>Stornieren
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <span class="text-muted small">–</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <!-- Detail-Zeile mit Buchungsnummer zum Drucken -->
                            <tr class="bg-light">
                                <td colspan="9" class="py-1">
                                    <small class="text-muted">
                                        <i class="bi bi-clock me-1"></i>
                                        Reserviert am: <?= date('d.m.Y H:i', strtotime($res['erstellt_am'])) ?> Uhr
                                        <?php if ($res['zahlungsart'] === 'ueberweisung' && $res['payment_status'] === 'offen'): ?>
                                        | <span class="text-warning fw-bold">
                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                            Bitte überweisen Sie <?= formatBetrag((float)($res['betrag'] ?? 0)) ?> mit Verwendungszweck: <?= htmlspecialchars($res['buchungsnummer']) ?>
                                        </span>
                                        <?php endif; ?>
                                    </small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="row mt-4 g-3">
            <div class="col-md-4">
                <a href="/pages/events.php" class="btn btn-outline-warning w-100">
                    <i class="bi bi-calendar-event me-2"></i>Neue Reservierung
                </a>
            </div>
            <div class="col-md-4">
                <a href="/pages/tischplan.php" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-grid-3x3 me-2"></i>Zum Tischplan
                </a>
            </div>
            <div class="col-md-4">
                <a href="/pages/profil.php" class="btn btn-outline-primary w-100">
                    <i class="bi bi-person me-2"></i>Mein Profil
                </a>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php
/**
 * Meine Reservierungen - Übersicht für eingeloggte Benutzer
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/qrcode.php';

requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Reservierungen laden (neueste zuerst; LEFT JOIN damit freie Tickets ohne Sitzplatz erscheinen)
$stmt = $pdo->prepare(
    'SELECT r.id, r.seat_id, r.buchungsnummer, r.status, r.preis, r.erstellt_am,
            e.name AS event_name, e.datum AS event_datum,
            t.tischnummer,
            s.sitzplatznummer,
            p.zahlungsart, p.status AS payment_status, p.betrag
     FROM reservations r
     JOIN events e  ON r.event_id = e.id
     LEFT JOIN seats  s  ON r.seat_id  = s.id
     LEFT JOIN tables t  ON s.table_id = t.id
     LEFT JOIN payments p ON p.reservation_id = r.id
     WHERE r.user_id = ?
     ORDER BY r.erstellt_am DESC'
);
$stmt->execute([$userId]);
$reservierungen = $stmt->fetchAll();

// Wartelisten-Einträge laden
$stmtWl = $pdo->prepare(
    'SELECT w.id, w.erstellt_am, e.id AS event_id, e.name AS event_name, e.datum AS event_datum
     FROM waitlist w
     JOIN events e ON w.event_id = e.id
     WHERE w.user_id = ?
     ORDER BY w.erstellt_am ASC'
);
$stmtWl->execute([$userId]);
$warteliste = $stmtWl->fetchAll();

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

        <?php
        // PayPal-URL (einmal berechnen)
        $paypalUrl = PAYPAL_SANDBOX
            ? 'https://www.sandbox.paypal.com/cgi-bin/webscr'
            : 'https://www.paypal.com/cgi-bin/webscr';
        // Alle offenen PayPal-Buchungen zählen (für "Alle auswählen" Checkbox)
        $paypalOffenCount = count(array_filter($reservierungen, fn($r) =>
            ($r['zahlungsart'] ?? '') === 'paypal' && ($r['payment_status'] ?? '') === 'offen'
        ));
        ?>

        <!-- Reservierungen Tabelle -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th class="ps-3" style="width:42px;">
                                    <?php if ($paypalOffenCount > 1): ?>
                                    <input type="checkbox" id="selectAllPaypal" class="form-check-input"
                                           title="Alle offenen PayPal-Buchungen auswählen">
                                    <?php endif; ?>
                                </th>
                                <th>Buchungsnr.</th>
                                <th>Veranstaltung</th>
                                <th>Datum</th>
                                <th>Platz/Ticket</th>
                                <th>Zahlungsart</th>
                                <th>Betrag</th>
                                <th>Buchungsstatus</th>
                                <th>Zahlungsstatus</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservierungen as $res):
                                $isPaypalOffen = ($res['zahlungsart'] ?? '') === 'paypal'
                                             && ($res['payment_status'] ?? '') === 'offen';
                                $betrag = (float)($res['betrag'] ?? 0);
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <?php if ($isPaypalOffen): ?>
                                    <input type="checkbox" class="form-check-input paypal-select"
                                           value="<?= htmlspecialchars($res['buchungsnummer']) ?>"
                                           data-amount="<?= number_format($betrag, 2, '.', '') ?>"
                                           data-name="<?= htmlspecialchars($res['event_name'] . ' – ' . $res['buchungsnummer']) ?>">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code class="fs-6 text-primary fw-bold"><?= htmlspecialchars($res['buchungsnummer']) ?></code>
                                    <button class="btn btn-link btn-sm p-0 ms-2 text-muted"
                                            type="button"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#qr-<?= $res['id'] ?>"
                                            title="QR-Code anzeigen">
                                        <i class="bi bi-qr-code"></i>
                                    </button>
                                </td>
                                <td><?= htmlspecialchars($res['event_name']) ?></td>
                                <td>
                                    <span class="badge bg-warning text-dark">
                                        <?= formatDatum($res['event_datum']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($res['seat_id'] === null): ?>
                                    <span class="badge bg-success">
                                        <i class="bi bi-ticket-perforated me-1"></i>Freies Ticket
                                    </span>
                                    <?php else: ?>
                                    <i class="bi bi-grid text-muted me-1"></i>
                                    Tisch <strong><?= $res['tischnummer'] ?></strong>, Platz <strong><?= $res['sitzplatznummer'] ?></strong>
                                    <?php endif; ?>
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
                                <td class="fw-bold"><?= formatBetrag($betrag) ?></td>
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
                                    <?php if ($isPaypalOffen): ?>
                                    <form action="<?= $paypalUrl ?>" method="post" target="_blank" class="mt-1">
                                        <input type="hidden" name="cmd"           value="_xclick">
                                        <input type="hidden" name="business"      value="<?= htmlspecialchars(PAYPAL_EMAIL) ?>">
                                        <input type="hidden" name="item_name"     value="<?= htmlspecialchars($res['event_name'] . ' – ' . $res['buchungsnummer']) ?>">
                                        <input type="hidden" name="item_number"   value="<?= htmlspecialchars($res['buchungsnummer']) ?>">
                                        <input type="hidden" name="amount"        value="<?= number_format($betrag, 2, '.', '') ?>">
                                        <input type="hidden" name="currency_code" value="EUR">
                                        <input type="hidden" name="return"        value="<?= APP_URL ?>/pages/meine_reservierungen.php">
                                        <input type="hidden" name="cancel_return" value="<?= APP_URL ?>/pages/meine_reservierungen.php">
                                        <input type="hidden" name="notify_url"    value="<?= APP_URL ?>/api/paypal_ipn.php">
                                        <input type="hidden" name="custom"        value="<?= htmlspecialchars($res['buchungsnummer']) ?>">
                                        <input type="hidden" name="no_shipping"   value="1">
                                        <input type="hidden" name="lc"            value="DE">
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="bi bi-paypal me-1"></i>Bezahlen
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <!-- Detail-Zeile -->
                            <tr class="bg-light">
                                <td colspan="10" class="py-1">
                                    <small class="text-muted">
                                        <i class="bi bi-clock me-1"></i>
                                        Reserviert am: <?= date('d.m.Y H:i', strtotime($res['erstellt_am'])) ?> Uhr
                                        <?php if (($res['zahlungsart'] ?? '') === 'ueberweisung' && ($res['payment_status'] ?? '') === 'offen'): ?>
                                        | <span class="text-warning fw-bold">
                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                            Bitte überweisen Sie <?= formatBetrag($betrag) ?> mit Verwendungszweck: <?= htmlspecialchars($res['buchungsnummer']) ?>
                                        </span>
                                        <?php endif; ?>
                                    </small>
                                </td>
                            </tr>
                            <tr class="collapse" id="qr-<?= $res['id'] ?>">
                                <td colspan="10" class="bg-white text-center py-3">
                                    <div class="d-inline-block text-center p-3 border rounded shadow-sm">
                                        <?= qrCodeImg(ticketPayload($res['buchungsnummer']), 170, 'QR-Code ' . $res['buchungsnummer']) ?>
                                        <div class="mt-2">
                                            <code class="fs-6 fw-bold text-primary"><?= htmlspecialchars($res['buchungsnummer']) ?></code><br>
                                            <small class="text-muted"><?= htmlspecialchars($res['event_name']) ?> · <?= formatDatum($res['event_datum']) ?></small>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Sticky PayPal Multi-Zahlungs-Bar (erscheint wenn ≥1 Checkbox aktiv) -->
        <div id="paypal-bar" class="d-none position-fixed bottom-0 start-0 end-0 bg-dark text-white shadow-lg px-3 py-2 d-flex align-items-center justify-content-between flex-wrap gap-2" style="z-index:1050;">
            <div class="d-flex align-items-center gap-3">
                <i class="bi bi-paypal fs-4 text-primary"></i>
                <div>
                    <div class="fw-bold">
                        <span id="bar-count">0 Buchungen</span> ausgewählt
                    </div>
                    <div class="small text-white-50">Gesamt: <strong id="bar-total" class="text-white">0,00 €</strong></div>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <button type="button" class="btn btn-sm btn-outline-light" id="bar-clear">
                    <i class="bi bi-x me-1"></i>Auswahl aufheben
                </button>
                <!-- PayPal-Formular wird per JS dynamisch befüllt und abgesendet -->
                <form id="paypal-multi-form" action="<?= $paypalUrl ?>" method="post" target="_blank">
                    <input type="hidden" name="business"      value="<?= htmlspecialchars(PAYPAL_EMAIL) ?>">
                    <input type="hidden" name="currency_code" value="EUR">
                    <input type="hidden" name="return"        value="<?= APP_URL ?>/pages/meine_reservierungen.php">
                    <input type="hidden" name="cancel_return" value="<?= APP_URL ?>/pages/meine_reservierungen.php">
                    <input type="hidden" name="notify_url"    value="<?= APP_URL ?>/api/paypal_ipn.php">
                    <input type="hidden" name="no_shipping"   value="1">
                    <input type="hidden" name="lc"            value="DE">
                    <button type="submit" id="bar-pay-btn" class="btn btn-primary">
                        <i class="bi bi-paypal me-2"></i>Jetzt mit PayPal bezahlen
                    </button>
                </form>
            </div>
        </div>

        <?php endif; ?>

        <?php if (!empty($warteliste)): ?>
        <!-- Warteliste -->
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-info text-white fw-bold">
                <i class="bi bi-hourglass-split me-2"></i>Meine Wartelisten-Einträge
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-secondary">
                            <tr>
                                <th>Veranstaltung</th>
                                <th>Datum</th>
                                <th>Eingetragen am</th>
                                <th>Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($warteliste as $wl): ?>
                            <tr>
                                <td><?= htmlspecialchars($wl['event_name']) ?></td>
                                <td><span class="badge bg-warning text-dark"><?= formatDatum($wl['event_datum']) ?></span></td>
                                <td><small class="text-muted"><?= date('d.m.Y H:i', strtotime($wl['erstellt_am'])) ?> Uhr</small></td>
                                <td>
                                    <form method="POST" action="/api/join_waitlist.php"
                                          onsubmit="return confirm('Von der Warteliste entfernen?')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="leave">
                                        <input type="hidden" name="event_id" value="<?= $wl['event_id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="bi bi-x-circle me-1"></i>Entfernen
                                        </button>
                                    </form>
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

<?php
$extraScripts = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function() {
    // QR-Codes werden serverseitig erzeugt (includes/qrcode.php) – kein JS nötig.

    // ─── Multi-PayPal Sticky Bar ──────────────────────────────────────────────
    var bar      = document.getElementById('paypal-bar');
    var barCount = document.getElementById('bar-count');
    var barTotal = document.getElementById('bar-total');
    var barClear = document.getElementById('bar-clear');
    var form     = document.getElementById('paypal-multi-form');
    var selectAll = document.getElementById('selectAllPaypal');

    if (!bar) return; // keine offenen PayPal-Buchungen

    function getChecked() {
        return Array.from(document.querySelectorAll('.paypal-select:checked'));
    }

    function updateBar() {
        var checked = getChecked();
        if (checked.length === 0) {
            bar.classList.add('d-none');
            return;
        }
        bar.classList.remove('d-none');
        var total = checked.reduce(function(s, cb) { return s + parseFloat(cb.dataset.amount); }, 0);
        barCount.textContent = checked.length + (checked.length === 1 ? ' Buchung' : ' Buchungen');
        barTotal.textContent = total.toFixed(2).replace('.', ',') + ' €';

        // Synchronise "Alle auswählen" Indeterminate-Zustand
        var all = document.querySelectorAll('.paypal-select');
        if (selectAll) {
            selectAll.checked = checked.length === all.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
        }
    }

    document.querySelectorAll('.paypal-select').forEach(function(cb) {
        cb.addEventListener('change', updateBar);
    });

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.paypal-select').forEach(function(cb) {
                cb.checked = selectAll.checked;
            });
            updateBar();
        });
    }

    if (barClear) {
        barClear.addEventListener('click', function() {
            document.querySelectorAll('.paypal-select').forEach(function(cb) { cb.checked = false; });
            if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
            updateBar();
        });
    }

    // Beim Abschicken: PayPal-Felder dynamisch befüllen
    form.addEventListener('submit', function(e) {
        var checked = getChecked();
        if (checked.length === 0) { e.preventDefault(); return; }

        // Alte dynamische Felder entfernen
        form.querySelectorAll('.pp-dyn').forEach(function(el) { el.remove(); });

        function addHidden(name, val) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = name;
            inp.value = val;
            inp.className = 'pp-dyn';
            form.appendChild(inp);
        }

        if (checked.length === 1) {
            addHidden('cmd',         '_xclick');
            addHidden('item_name',   checked[0].dataset.name);
            addHidden('item_number', checked[0].value);
            addHidden('amount',      checked[0].dataset.amount);
            addHidden('custom',      checked[0].value);
        } else {
            addHidden('cmd',    '_cart');
            addHidden('upload', '1');
            var bns = [];
            checked.forEach(function(cb, i) {
                var n = i + 1;
                addHidden('item_name_'   + n, cb.dataset.name);
                addHidden('amount_'      + n, cb.dataset.amount);
                addHidden('quantity_'    + n, '1');
                addHidden('item_number_' + n, cb.value);
                bns.push(cb.value);
            });
            addHidden('custom', 'BATCH:' + bns.join(','));
        }
    });
});

</script>
HTML;
include __DIR__ . '/../includes/footer.php';
?>

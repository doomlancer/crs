<?php
/**
 * Buchungsdetail / Digitales Ticket mit QR-Code
 * Zugänglich für: eigener Benutzer, Kassierer, Admin
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

requireLogin();

$pdo = getDB();
$buchungsnummer = trim($_GET['buchungsnummer'] ?? '');

if (empty($buchungsnummer)) {
    setFlash('error', 'Keine Buchungsnummer angegeben.');
    redirect('/pages/meine_reservierungen.php');
}

// Buchung laden
$stmt = $pdo->prepare(
    'SELECT r.id, r.buchungsnummer, r.status, r.preis, r.erstellt_am, r.user_id,
            e.name AS event_name, e.datum AS event_datum, e.beschreibung AS event_beschreibung,
            t.tischnummer,
            s.sitzplatznummer,
            u.vorname, u.nachname, u.email,
            p.zahlungsart, p.status AS payment_status, p.betrag
     FROM reservations r
     JOIN events e  ON r.event_id = e.id
     JOIN seats  s  ON r.seat_id  = s.id
     JOIN tables t  ON s.table_id = t.id
     JOIN users  u  ON r.user_id  = u.id
     LEFT JOIN payments p ON p.reservation_id = r.id
     WHERE r.buchungsnummer = ?'
);
$stmt->execute([$buchungsnummer]);
$buchung = $stmt->fetch();

if (!$buchung) {
    setFlash('error', 'Buchung nicht gefunden.');
    redirect('/pages/meine_reservierungen.php');
}

// Zugriffskontrolle
$userId = (int)$_SESSION['user_id'];
$isOwnBooking    = ($buchung['user_id'] === $userId);
$isPrivileged    = hasRole('kassierer', 'admin');

if (!$isOwnBooking && !$isPrivileged) {
    http_response_code(403);
    setFlash('error', 'Sie haben keinen Zugriff auf dieses Ticket.');
    redirect('/pages/meine_reservierungen.php');
}

// QR-Code generieren (HMAC-geschützte Verify-URL)
$hmacToken  = generateHmacToken($buchung['buchungsnummer']);
$qrUrl      = APP_URL . '/api/verify_checkin.php?token=' . urlencode($hmacToken) . '&nr=' . urlencode($buchung['buchungsnummer']);
$qrHtml     = generateQrCode($qrUrl, 220);

$pageTitle = 'Ticket – ' . $buchung['buchungsnummer'];
$bodyClass = 'bg-light';
$extraHead = '';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<style>
@media print {
    .navbar, .no-print { display: none !important; }
    body { background: white !important; }
    .ticket-card { box-shadow: none !important; border: 2px solid #000 !important; }
    .print-only { display: block !important; }
}
.print-only { display: none; }
.ticket-card {
    max-width: 680px;
    margin: 0 auto;
}
.ticket-header {
    background: linear-gradient(135deg, #111 0%, #2d2d2d 100%);
    border-bottom: 4px solid #f59e0b;
}
.ticket-stripe {
    background: repeating-linear-gradient(
        45deg, #f59e0b, #f59e0b 6px, #111 6px, #111 14px
    );
    height: 8px;
}
</style>

<main class="py-5">
<div class="container">

    <?= getFlash() ?>

    <div class="row justify-content-center">
        <div class="col-12">

            <!-- Aktionsbuttons -->
            <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                <a href="<?= $isPrivileged ? '/pages/kassierer_guestlist.php' : '/pages/meine_reservierungen.php' ?>"
                   class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Zurück
                </a>
                <div class="d-flex gap-2">
                    <?php if ($buchung['status'] === 'geplant' && $isPrivileged): ?>
                    <a href="/api/verify_checkin.php?token=<?= urlencode($hmacToken) ?>&nr=<?= urlencode($buchung['buchungsnummer']) ?>"
                       class="btn btn-success fw-semibold">
                        <i class="bi bi-check-circle me-1"></i>Check-in
                    </a>
                    <?php endif; ?>
                    <button onclick="window.print()" class="btn btn-warning fw-semibold">
                        <i class="bi bi-printer me-2"></i>Drucken / PDF
                    </button>
                </div>
            </div>

            <!-- Ticket-Karte -->
            <div class="card border-0 shadow-lg ticket-card">
                <!-- Karneval-Streifen oben -->
                <div class="ticket-stripe"></div>

                <!-- Ticket-Header -->
                <div class="ticket-header text-white p-4">
                    <div class="row align-items-center">
                        <div class="col">
                            <div class="text-warning small fw-bold text-uppercase letter-spacing-1 mb-1">
                                <i class="bi bi-music-note-beamed me-1"></i>Karneval Reservierungssystem
                            </div>
                            <h2 class="fw-bold mb-0 h3">
                                <?= htmlspecialchars($buchung['event_name']) ?>
                            </h2>
                            <div class="mt-2 text-white-50">
                                <i class="bi bi-calendar3 text-warning me-1"></i>
                                <?= formatDatum($buchung['event_datum']) ?>
                            </div>
                        </div>
                        <div class="col-auto text-end">
                            <?= statusBadge($buchung['status']) ?>
                        </div>
                    </div>
                </div>

                <div class="card-body p-4">
                    <div class="row g-4">
                        <!-- Linke Seite: Details -->
                        <div class="col-md-7">
                            <h5 class="fw-bold text-warning border-bottom border-warning pb-2 mb-3">
                                <i class="bi bi-ticket-perforated me-2"></i>Buchungsdetails
                            </h5>

                            <div class="row g-3">
                                <div class="col-6">
                                    <div class="text-muted small text-uppercase fw-semibold">Buchungsnummer</div>
                                    <div class="fw-bold text-primary font-monospace">
                                        <?= htmlspecialchars($buchung['buchungsnummer']) ?>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted small text-uppercase fw-semibold">Sitzplatz</div>
                                    <div class="fw-bold">
                                        Tisch <?= (int)$buchung['tischnummer'] ?>,
                                        Platz <?= (int)$buchung['sitzplatznummer'] ?>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted small text-uppercase fw-semibold">Gast</div>
                                    <div class="fw-bold">
                                        <?= htmlspecialchars($buchung['vorname'] . ' ' . $buchung['nachname']) ?>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted small text-uppercase fw-semibold">E-Mail</div>
                                    <div class="text-truncate" style="max-width:200px;">
                                        <small><?= htmlspecialchars($buchung['email']) ?></small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted small text-uppercase fw-semibold">Zahlungsart</div>
                                    <div class="fw-bold">
                                        <?php
                                        $zahlIcon = match($buchung['zahlungsart'] ?? '') {
                                            'paypal'       => 'bi-paypal text-primary',
                                            'ueberweisung' => 'bi-bank text-info',
                                            default        => 'bi-cash text-success',
                                        };
                                        ?>
                                        <i class="bi <?= $zahlIcon ?> me-1"></i>
                                        <?= zahlungsartLabel($buchung['zahlungsart'] ?? 'bar') ?>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted small text-uppercase fw-semibold">Betrag</div>
                                    <div class="fw-bold text-success fs-5">
                                        <?= formatBetrag((float)($buchung['betrag'] ?? $buchung['preis'] ?? 0)) ?>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted small text-uppercase fw-semibold">Zahlungsstatus</div>
                                    <div><?= statusBadge($buchung['payment_status'] ?? 'offen') ?></div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted small text-uppercase fw-semibold">Buchungsstatus</div>
                                    <div><?= statusBadge($buchung['status']) ?></div>
                                </div>
                                <div class="col-12">
                                    <div class="text-muted small text-uppercase fw-semibold">Buchungsdatum</div>
                                    <div><?= date('d.m.Y H:i', strtotime($buchung['erstellt_am'])) ?> Uhr</div>
                                </div>
                            </div>

                            <?php if ($buchung['zahlungsart'] === 'ueberweisung' && ($buchung['payment_status'] ?? '') === 'offen'): ?>
                            <div class="alert alert-warning mt-3 py-2">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Bitte überweisen Sie</strong>
                                <?= formatBetrag((float)($buchung['betrag'] ?? 0)) ?>
                                mit Verwendungszweck:
                                <strong class="font-monospace"><?= htmlspecialchars($buchung['buchungsnummer']) ?></strong>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Rechte Seite: QR-Code -->
                        <div class="col-md-5 d-flex flex-column align-items-center justify-content-center border-start">
                            <div class="text-muted small text-uppercase fw-semibold mb-2 text-center">
                                <i class="bi bi-qr-code me-1"></i>Check-in QR-Code
                            </div>
                            <?= $qrHtml ?>
                            <div class="text-center mt-2">
                                <small class="text-muted">Zum Einlass scannen</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Karneval-Streifen unten -->
                <div class="ticket-stripe"></div>

                <div class="card-footer bg-dark text-white text-center py-2">
                    <small class="text-warning fw-semibold">
                        <i class="bi bi-shield-check me-1"></i>
                        Dieses Ticket ist fälschungssicher und personenbezogen.
                    </small>
                </div>
            </div>

        </div>
    </div>
</div>
</main>

<?php
$extraScripts = <<<'HTML'
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"
        integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSi2jPCWvgYyMsHZBeMLHiKxhfHFDYLFqQ=="
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function() {
    var canvas = document.getElementById('qr-canvas');
    if (!canvas) return;
    var data = canvas.dataset.qr;
    var size = parseInt(canvas.dataset.size) || 200;
    canvas.parentNode.innerHTML = '<div id="qr-render"></div>';
    new QRCode(document.getElementById('qr-render'), {
        text: data,
        width: size,
        height: size,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });
})();
</script>
HTML;

include __DIR__ . '/../includes/footer.php';

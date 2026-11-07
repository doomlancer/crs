<?php
/**
 * Tickets ohne Anmeldung – Ziel des Links aus der Bestätigungs-Mail.
 *
 *   ?u=<user>&e=<event>&t=<signatur>   alle Tickets eines Gastes für ein Event
 *   ?b=<buchungsnummer>&t=<signatur>   ein einzelnes Ticket (zum Weitergeben)
 *
 * Die Signatur (ticketLinkToken) verhindert, dass jemand durch Hochzählen
 * fremde Tickets abruft. Wer den Link hat, hat das Ticket – genau wie bei
 * der Mail selbst, in der er steht.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/ticket_karte.php';

$token   = (string)($_GET['t'] ?? '');
$tickets = [];
$event   = null;
$gueltig = false;

$basisSql =
    "SELECT r.buchungsnummer, r.status, r.seat_id, r.preis, r.event_id, r.user_id,
            t.tischnummer, s.sitzplatznummer,
            p.zahlungsart, p.status AS zahl_status, p.betrag,
            u.vorname, u.nachname,
            e.name AS event_name, e.datum AS event_datum
     FROM reservations r
     JOIN users u   ON u.id = r.user_id
     JOIN events e  ON e.id = r.event_id
     LEFT JOIN seats s    ON s.id = r.seat_id
     LEFT JOIN tables t   ON t.id = s.table_id
     LEFT JOIN payments p ON p.reservation_id = r.id
     WHERE r.status IN ('geplant','eingecheckt') AND ";

if (isset($_GET['u'], $_GET['e'])) {
    $u = (int)$_GET['u'];
    $ev = (int)$_GET['e'];
    if ($u > 0 && $ev > 0 && hash_equals(ticketLinkToken("u{$u}e{$ev}"), $token)) {
        $gueltig = true;
        $stmt = getDB()->prepare($basisSql . 'r.user_id = ? AND r.event_id = ? ORDER BY t.tischnummer, s.sitzplatznummer, r.id');
        $stmt->execute([$u, $ev]);
        $tickets = $stmt->fetchAll();
    }
} elseif (isset($_GET['b'])) {
    $b = (string)$_GET['b'];
    if (preg_match('/^KARN-\d{4}-[0-9A-F]{6}$/', $b) && hash_equals(ticketLinkToken('b' . $b), $token)) {
        $gueltig = true;
        $stmt = getDB()->prepare($basisSql . 'r.buchungsnummer = ?');
        $stmt->execute([$b]);
        $tickets = $stmt->fetchAll();
    }
}

if (!$gueltig) {
    http_response_code(404);
}

$pageTitle = __('ticket.page_title');
$bodyClass = 'bg-light';
$extraHead = '<meta name="robots" content="noindex, nofollow">';
include __DIR__ . '/../includes/header.php';
?>
<main class="py-4">
    <div class="container" style="max-width:560px;">
        <div class="text-center mb-3">
            <a href="/index.php" class="text-decoration-none fw-bold text-dark"><?= htmlspecialchars(getSetting('app_name', APP_NAME)) ?></a>
        </div>

        <?php if (!$gueltig || !$tickets): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-ticket-perforated display-4 text-muted d-block mb-3"></i>
                <p class="mb-0"><?= htmlspecialchars($gueltig ? __('ticket.none') : __('ticket.invalid')) ?></p>
            </div>
        </div>
        <?php else:
            $erstes = $tickets[0];
            $offen = 0.0;
            $nummern = [];
            foreach ($tickets as $t) {
                if ($t['status'] === 'geplant' && ($t['zahl_status'] ?? 'offen') === 'offen') {
                    $offen += (float)($t['betrag'] ?? $t['preis']);
                }
                $nummern[] = $t['buchungsnummer'];
            }
        ?>
        <h1 class="h4 fw-bold text-center mb-1"><?= htmlspecialchars($erstes['event_name']) ?></h1>
        <p class="text-center text-muted mb-1"><?= formatDatum($erstes['event_datum']) ?></p>
        <p class="text-center small text-muted mb-3">
            <?= htmlspecialchars(sprintf(__('ticket.booked_by'), trim($erstes['vorname'] . ' ' . $erstes['nachname']))) ?>
        </p>
        <p class="text-center small mb-3"><i class="bi bi-brightness-high me-1"></i><?= htmlspecialchars(__('ticket.hint')) ?></p>

        <?php if ($offen > 0): ?>
        <div class="mb-3"><?= renderZahlungshinweis($erstes['zahlungsart'] ?? 'bar', $offen, $nummern, $erstes['nachname']) ?></div>
        <?php endif; ?>

        <div class="d-flex flex-column gap-3">
            <?php foreach ($tickets as $i => $t): ?>
            <?= renderTicketKarte($t, $i + 1, count($tickets), count($tickets) > 1) ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

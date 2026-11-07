<?php
/**
 * Meine Tickets – Buchungen des eingeloggten Gastes, nach Veranstaltung
 * gruppiert. Pro Person ein Ticket mit eigenem QR-Code; die QR-Codes öffnen
 * sich groß in einem Fenster (auf dem Handy bildschirmfüllend).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ticket_karte.php';

requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare(
    'SELECT r.id, r.seat_id, r.buchungsnummer, r.status, r.preis, r.erstellt_am, r.event_id,
            e.name AS event_name, e.datum AS event_datum,
            t.tischnummer, s.sitzplatznummer,
            p.zahlungsart, p.status AS zahl_status, p.betrag
     FROM reservations r
     JOIN events e  ON r.event_id = e.id
     LEFT JOIN seats  s  ON r.seat_id  = s.id
     LEFT JOIN tables t  ON s.table_id = t.id
     LEFT JOIN payments p ON p.reservation_id = r.id
     WHERE r.user_id = ?
     ORDER BY e.datum ASC, t.tischnummer ASC, s.sitzplatznummer ASC, r.id ASC'
);
$stmt->execute([$userId]);

// Nach Veranstaltung gruppieren – kommende zuerst, vergangene danach
$gruppen = [];
foreach ($stmt->fetchAll() as $r) {
    $eid = (int)$r['event_id'];
    $gruppen[$eid] ??= [
        'event_id' => $eid, 'name' => $r['event_name'], 'datum' => $r['event_datum'],
        'vergangen' => $r['event_datum'] < date('Y-m-d'), 'tickets' => [],
    ];
    $gruppen[$eid]['tickets'][] = $r;
}
usort($gruppen, fn($a, $b) => [$a['vergangen'], $a['vergangen'] ? -strtotime($a['datum']) : strtotime($a['datum'])]
                          <=> [$b['vergangen'], $b['vergangen'] ? -strtotime($b['datum']) : strtotime($b['datum'])]);

$stmtWl = $pdo->prepare(
    'SELECT w.id, w.erstellt_am, w.benachrichtigt_am, e.id AS event_id, e.name AS event_name, e.datum AS event_datum
     FROM waitlist w
     JOIN events e ON w.event_id = e.id
     WHERE w.user_id = ?
     ORDER BY e.datum ASC'
);
$stmtWl->execute([$userId]);
$warteliste = $stmtWl->fetchAll();

$nachname = (string)($_SESSION['nachname'] ?? '');

$pageTitle = __('res.title');
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="py-4">
    <div class="container" style="max-width:960px;">
        <?= getFlash() ?>

        <div class="mb-4">
            <h1 class="h2 fw-bold mb-1">
                <i class="bi bi-ticket-perforated text-warning me-2"></i><?= __('res.title') ?>
            </h1>
            <p class="text-muted mb-0"><?= __('res.subtitle') ?></p>
        </div>

        <?php if (empty($gruppen)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-calendar-x display-3 text-muted d-block mb-3"></i>
                <h2 class="h5 text-muted"><?= __('res.empty_title') ?></h2>
                <p class="text-muted"><?= __('res.empty_text') ?></p>
                <a href="/pages/events.php" class="btn btn-warning">
                    <i class="bi bi-calendar-event me-2"></i><?= __('res.discover_events') ?>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <div class="d-flex flex-column gap-4">
        <?php foreach ($gruppen as $g):
            $aktive  = array_values(array_filter($g['tickets'], fn($t) => in_array($t['status'], ['geplant', 'eingecheckt'], true)));
            $offen   = 0.0;
            $summe   = 0.0;
            foreach ($aktive as $t) {
                $betrag = (float)($t['betrag'] ?? $t['preis']);
                $summe += $betrag;
                if ($t['status'] === 'geplant' && ($t['zahl_status'] ?? 'offen') === 'offen') $offen += $betrag;
            }
            $zahlungsart = $aktive[0]['zahlungsart'] ?? 'bar';
            $modalId = 'tickets-' . $g['event_id'];
        ?>
            <section class="card border-0 shadow-sm">
                <div class="card-body d-flex flex-column gap-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <h2 class="h5 fw-bold mb-1"><?= htmlspecialchars($g['name']) ?></h2>
                            <div class="text-muted small">
                                <i class="bi bi-calendar3 me-1"></i><?= formatDatum($g['datum']) ?>
                                <?php if ($g['vergangen']): ?> · <?= __('res.past') ?><?php endif; ?>
                                · <?= htmlspecialchars(sprintf(__('res.tickets_count'), count($aktive))) ?>
                                <?php if ($summe > 0): ?> · <?= formatBetrag($summe) ?><?php endif; ?>
                            </div>
                        </div>
                        <?php if ($aktive && !$g['vergangen']): ?>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-warning fw-semibold" data-bs-toggle="modal" data-bs-target="#<?= $modalId ?>">
                                <i class="bi bi-qr-code me-1"></i><?= htmlspecialchars(sprintf(__('res.show_all_qr'), count($aktive))) ?>
                            </button>
                            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(ticketLinkUrl($userId, $g['event_id'])) ?>"
                               title="<?= htmlspecialchars(__('res.ticket_link_title')) ?>">
                                <i class="bi bi-link-45deg me-1"></i><?= __('res.ticket_link') ?>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($offen > 0 && !$g['vergangen']): ?>
                    <?= renderZahlungshinweis($zahlungsart, $offen, array_column($aktive, 'buchungsnummer'), $nachname) ?>
                    <?php endif; ?>

                    <ul class="list-group list-group-flush">
                    <?php foreach ($g['tickets'] as $t):
                        $aktiv = in_array($t['status'], ['geplant', 'eingecheckt'], true);
                    ?>
                        <li class="list-group-item px-0 d-flex flex-wrap align-items-center gap-2 <?= $aktiv ? '' : 'text-muted' ?>">
                            <div class="flex-grow-1" style="min-width:12rem;">
                                <div class="fw-semibold">
                                    <?php if ($t['seat_id'] === null): ?>
                                        <i class="bi bi-ticket-perforated me-1"></i><?= __('res.free_ticket_badge') ?>
                                    <?php else: ?>
                                        <i class="bi bi-grid me-1 text-muted"></i><?= sprintf(__('res.table_seat_combo'), (int)$t['tischnummer'], (int)$t['sitzplatznummer']) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="small text-muted font-monospace"><?= htmlspecialchars($t['buchungsnummer']) ?></div>
                            </div>
                            <div class="d-flex flex-wrap gap-1 align-items-center">
                                <?= statusBadge($t['status']) ?>
                                <?php if ($aktiv): ?><?= statusBadge($t['zahl_status'] ?? 'offen') ?><?php endif; ?>
                            </div>
                            <div class="d-flex gap-2 ms-auto">
                                <?php if ($aktiv && !$g['vergangen']): ?>
                                <button type="button" class="btn btn-sm btn-outline-dark"
                                        data-ticket-show="<?= htmlspecialchars($t['buchungsnummer']) ?>" data-ticket-modal="<?= $modalId ?>">
                                    <i class="bi bi-qr-code me-1"></i>QR
                                </button>
                                <?php endif; ?>
                                <?php if ($t['status'] === 'geplant' && !$g['vergangen']): ?>
                                <form method="POST" action="/api/reserve_seat.php" class="m-0"
                                      data-confirm="<?= htmlspecialchars(__('res.cancel_confirm'), ENT_QUOTES) ?>">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="reservation_id" value="<?= (int)$t['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-x-circle me-1"></i><?= __('res.cancel') ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
            </section>

            <?php if ($aktive && !$g['vergangen']): ?>
            <div class="modal fade" id="<?= $modalId ?>" tabindex="-1" aria-labelledby="<?= $modalId ?>-titel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title h5 fw-bold" id="<?= $modalId ?>-titel"><?= htmlspecialchars($g['name']) ?></h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(__('general.close')) ?>"></button>
                        </div>
                        <div class="modal-body bg-light">
                            <p class="small text-center mb-3"><i class="bi bi-brightness-high me-1"></i><?= htmlspecialchars(__('ticket.hint')) ?></p>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($aktive as $i => $t): ?>
                                <?= renderTicketKarte($t, $i + 1, count($aktive), count($aktive) > 1) ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
        </div>

        <?php if (!empty($warteliste)): ?>
        <section class="card border-0 shadow-sm mt-4">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3"><i class="bi bi-hourglass-split text-warning me-2"></i><?= __('res.waitlist_title') ?></h2>
                <ul class="list-group list-group-flush">
                    <?php foreach ($warteliste as $wl): ?>
                    <li class="list-group-item px-0 d-flex flex-wrap align-items-center gap-2">
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?= htmlspecialchars($wl['event_name']) ?> · <?= formatDatum($wl['event_datum']) ?></div>
                            <div class="small text-muted">
                                <?php if ($wl['benachrichtigt_am']): ?>
                                    <span class="text-success fw-semibold"><?= htmlspecialchars(sprintf(__('res.waitlist_notified'), date('d.m.Y H:i', strtotime($wl['benachrichtigt_am'])))) ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars(__('res.waitlist_waiting')) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($wl['benachrichtigt_am']): ?>
                        <a class="btn btn-sm btn-warning" href="/pages/tischplan.php?event_id=<?= (int)$wl['event_id'] ?>"><?= __('res.book_now') ?></a>
                        <?php endif; ?>
                        <form method="POST" action="/api/join_waitlist.php" class="m-0"
                              data-confirm="<?= htmlspecialchars(__('res.waitlist_confirm'), ENT_QUOTES) ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="leave">
                            <input type="hidden" name="event_id" value="<?= (int)$wl['event_id'] ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-x-circle me-1"></i><?= __('res.waitlist_remove') ?>
                            </button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2 mt-4">
            <a href="/pages/events.php" class="btn btn-outline-warning">
                <i class="bi bi-calendar-event me-2"></i><?= __('res.new_reservation') ?>
            </a>
            <a href="/pages/profil.php" class="btn btn-outline-secondary">
                <i class="bi bi-person me-2"></i><?= __('res.my_profile') ?>
            </a>
        </div>
    </div>
</main>

<?php
$extraScripts = <<<'HTML'
<script>
// "QR" an einem einzelnen Ticket: Fenster des Events öffnen und zum Ticket springen
document.querySelectorAll('[data-ticket-show]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var modalEl = document.getElementById(btn.getAttribute('data-ticket-modal'));
        if (!modalEl || !window.bootstrap) return;
        var ziel = document.getElementById('ticket-' + btn.getAttribute('data-ticket-show'));
        modalEl.addEventListener('shown.bs.modal', function handler() {
            modalEl.removeEventListener('shown.bs.modal', handler);
            if (ziel) ziel.scrollIntoView({ block: 'start' });
        });
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });
});
</script>
HTML;
include __DIR__ . '/../includes/footer.php';
?>

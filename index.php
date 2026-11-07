<?php
/**
 * Haupt-Einstiegspunkt: Landing Page / Router
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Logout-Aktion
if (isset($_GET['logout'])) {
    logoutUser();
}

$pageTitle = __('index.page_title');
$pdo = getDB();

// Die nächsten buchbaren Events (nur Status "aktiv"): Events in Planung
// verdrängten sonst die eigentliche Hauptveranstaltung von der Startseite.
$aktuelleEvents = $pdo->query(
    "SELECT id, datum, name, beschreibung, status, preis, event_typ
     FROM events
     WHERE status = 'aktiv' AND datum >= CURDATE()
     ORDER BY datum ASC
     LIMIT 3"
)->fetchAll();
foreach ($aktuelleEvents as &$ev) {
    $ev['auslastung'] = getEventAuslastung((int)$ev['id']);
}
unset($ev);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>

<main>
    <!-- Hero Section -->
    <section class="hero-section">
        <div class="hero-overlay">
            <div class="container text-center py-5">
                <div class="hero-content">
                    <?php
                    $heroLogo = getSetting('app_logo', '');
                    $heroLogoSrc = ($heroLogo && file_exists(UPLOAD_DIR . $heroLogo))
                        ? '/uploads/' . htmlspecialchars($heroLogo)
                        : null;
                    $heroName = getSetting('app_name', APP_NAME);
                    $heroSlogan = getSetting('app_slogan', '');
                    $heroDash = strrpos($heroName, '-');
                    $heroNameA = $heroDash !== false ? substr($heroName, 0, $heroDash) : $heroName;
                    $heroNameB = $heroDash !== false ? substr($heroName, $heroDash)    : '';
                    ?>
                    <?php if ($heroLogoSrc): ?>
                    <div class="mb-3">
                        <img src="<?= $heroLogoSrc ?>"
                             alt="<?= htmlspecialchars($heroName) ?>"
                             style="width:100px;height:100px;object-fit:contain;border-radius:50%;border:3px solid var(--club-red);background:#fff;">
                    </div>
                    <?php endif; ?>
                    <h1 class="display-4 fw-bold text-white mb-3">
                        <?= htmlspecialchars($heroNameA) ?><span class="text-warning"><?= htmlspecialchars($heroNameB) ?></span>
                    </h1>
                    <p class="lead text-white-50 mb-4">
                        <?= $heroSlogan
                            ? htmlspecialchars($heroSlogan)
                            : __('index.hero_slogan_fallback')
                        ?>
                    </p>
                    <div class="d-flex gap-3 justify-content-center flex-wrap">
                        <a href="/pages/events.php" class="btn btn-warning btn-lg px-4">
                            <i class="bi bi-calendar-event me-2"></i><?= __('index.view_events_button') ?>
                        </a>
                        <?php if (!isLoggedIn()): ?>
                        <a href="/pages/register.php" class="btn btn-outline-light btn-lg px-4">
                            <i class="bi bi-person-plus me-2"></i><?= __('auth.register_now') ?>
                        </a>
                        <?php else: ?>
                        <a href="/pages/meine_reservierungen.php" class="btn btn-outline-light btn-lg px-4">
                            <i class="bi bi-ticket-perforated me-2"></i><?= __('nav.my_reservations') ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-card text-center p-4 h-100">
                        <div class="feature-icon mb-3">
                            <i class="bi bi-grid-3x3 text-warning"></i>
                        </div>
                        <h5 class="fw-bold"><?= __('index.feature_seating_title') ?></h5>
                        <p class="text-muted"><?= __('index.feature_seating_text') ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card text-center p-4 h-100">
                        <div class="feature-icon mb-3">
                            <i class="bi bi-ticket-perforated text-warning"></i>
                        </div>
                        <h5 class="fw-bold"><?= __('index.feature_instant_title') ?></h5>
                        <p class="text-muted"><?= __('index.feature_instant_text') ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card text-center p-4 h-100">
                        <div class="feature-icon mb-3">
                            <i class="bi bi-credit-card text-warning"></i>
                        </div>
                        <h5 class="fw-bold"><?= __('index.feature_payment_title') ?></h5>
                        <p class="text-muted"><?= __('index.feature_payment_text') ?></p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Aktuelle Events -->
    <?php if (!empty($aktuelleEvents)): ?>
    <section class="py-5">
        <div class="container">
            <h2 class="text-center fw-bold mb-4">
                <i class="bi bi-calendar-star text-warning me-2"></i><?= __('index.current_events_heading') ?>
            </h2>
            <div class="row g-4">
                <?php foreach ($aktuelleEvents as $event):
                    $a          = $event['auslastung'];
                    $freiticket = ($event['event_typ'] ?? 'tischplan') === 'freie_tickets';
                    $gesamt     = (int)$a['gesamt'];
                    $belegt     = (int)$a['belegt'];
                    $prozent    = (int)$a['prozent'];
                    $voll       = $gesamt > 0 && (int)$a['frei'] <= 0;
                    $barColor   = $prozent >= 90 ? 'bg-danger' : ($prozent >= 70 ? 'bg-warning' : 'bg-success');
                    $preis      = (float)($event['preis'] ?? 0) > 0 ? (float)$event['preis'] : (float)TICKET_PREIS;
                ?>
                <div class="col-md-4">
                    <div class="card event-card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="badge bg-warning text-dark">
                                    <i class="bi bi-calendar3"></i>
                                    <?= formatDatum($event['datum']) ?>
                                </span>
                                <?= statusBadge($event['status']) ?>
                            </div>
                            <h5 class="card-title fw-bold"><?= htmlspecialchars($event['name']) ?></h5>
                            <p class="card-text text-muted small">
                                <?= htmlspecialchars(mb_substr($event['beschreibung'] ?? '', 0, 120)) ?>
                                <?= strlen($event['beschreibung'] ?? '') > 120 ? '…' : '' ?>
                            </p>
                            <div class="mt-auto">
                                <div class="d-flex justify-content-between mb-1 small">
                                    <span><?= __('events.occupancy') ?></span>
                                    <strong><?= $prozent ?>%</strong>
                                </div>
                                <div class="progress mb-3" style="height: 8px;">
                                    <div class="progress-bar <?= $barColor ?>" style="width: <?= $prozent ?>%"></div>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <small class="text-muted">
                                        <i class="bi bi-people"></i>
                                        <?php if ($freiticket): ?>
                                        <?= htmlspecialchars($gesamt > 0 ? sprintf(__('events.tickets_sold'), $belegt, $gesamt) : sprintf(__('events.tickets_sold_nolimit'), $belegt)) ?>
                                        <?php else: ?>
                                        <?= htmlspecialchars(sprintf(__('index.seats_taken_of_total'), $belegt, $gesamt)) ?>
                                        <?php endif; ?>
                                    </small>
                                    <small class="fw-bold"><?= formatBetrag($preis) ?></small>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent">
                            <?php if ($voll): ?>
                            <a href="/pages/events.php" class="btn btn-outline-danger w-100">
                                <i class="bi bi-x-circle me-1"></i><?= __('events.sold_out') ?>
                            </a>
                            <?php else: ?>
                            <a href="/pages/tischplan.php?event_id=<?= (int)$event['id'] ?>" class="btn btn-outline-warning w-100">
                                <i class="bi <?= $freiticket ? 'bi-ticket-perforated' : 'bi-grid-3x3' ?> me-1"></i><?= $freiticket ? __('events.buy_tickets') : __('events.book_now') ?>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="text-center mt-4">
                <a href="/pages/events.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-right me-1"></i><?= __('index.show_all_events_button') ?>
                </a>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- CTA Section -->
    <section class="py-5 bg-dark text-white">
        <div class="container text-center">
            <h3 class="fw-bold mb-3">
                <i class="bi bi-emoji-laughing text-warning me-2"></i>
                <?= __('index.cta_heading') ?>
            </h3>
            <p class="text-white-50 mb-4"><?= __('index.cta_subtitle') ?></p>
            <?php if (!isLoggedIn()): ?>
            <a href="/pages/register.php" class="btn btn-warning btn-lg me-2">
                <i class="bi bi-person-plus me-2"></i><?= __('index.cta_register_button') ?>
            </a>
            <a href="/pages/login.php" class="btn btn-outline-light btn-lg">
                <i class="bi bi-box-arrow-in-right me-2"></i><?= __('auth.login_button') ?>
            </a>
            <?php else: ?>
            <a href="/pages/events.php" class="btn btn-warning btn-lg">
                <i class="bi bi-calendar-event me-2"></i><?= __('index.cta_reserve_button') ?>
            </a>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

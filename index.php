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

$pageTitle = 'Willkommen';
$pdo = getDB();

// Aktive Events laden
$stmt = $pdo->prepare(
    "SELECT e.*,
        COUNT(s.id) AS gesamt_sitze,
        SUM(CASE WHEN s.status != 'verfuegbar' THEN 1 ELSE 0 END) AS belegte_sitze
     FROM events e
     LEFT JOIN tables t ON t.event_id = e.id
     LEFT JOIN seats s ON s.table_id = t.id
     WHERE e.status != 'abgerechnet' AND e.datum >= CURDATE()
     GROUP BY e.id
     ORDER BY e.datum ASC
     LIMIT 3"
);
$stmt->execute();
$aktuelleEvents = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>

<main>
    <!-- Hero Section -->
    <section class="hero-section">
        <div class="hero-overlay">
            <div class="container text-center py-5">
                <div class="hero-content">
                    <div class="mb-3">
                        <i class="bi bi-music-note-beamed display-1 text-warning"></i>
                    </div>
                    <h1 class="display-4 fw-bold text-white mb-3">
                        Kameruner<span class="text-warning">-Tickets</span>
                    </h1>
                    <p class="lead text-white-50 mb-4">
                        Sichern Sie sich Ihren Platz bei den unvergesslichen Veranstaltungen!<br>
                        Einfach online reservieren, bequem bezahlen und den Spaß genießen.
                    </p>
                    <div class="d-flex gap-3 justify-content-center flex-wrap">
                        <a href="/pages/events.php" class="btn btn-warning btn-lg px-4">
                            <i class="bi bi-calendar-event me-2"></i>Events ansehen
                        </a>
                        <?php if (!isLoggedIn()): ?>
                        <a href="/pages/register.php" class="btn btn-outline-light btn-lg px-4">
                            <i class="bi bi-person-plus me-2"></i>Jetzt registrieren
                        </a>
                        <?php else: ?>
                        <a href="/pages/tischplan.php" class="btn btn-outline-light btn-lg px-4">
                            <i class="bi bi-grid-3x3 me-2"></i>Tischplan
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
                        <h5 class="fw-bold">Grafischer Tischplan</h5>
                        <p class="text-muted">Wählen Sie Ihren Wunschtisch visuell aus. Sehen Sie in Echtzeit, welche Plätze noch verfügbar sind.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card text-center p-4 h-100">
                        <div class="feature-icon mb-3">
                            <i class="bi bi-ticket-perforated text-warning"></i>
                        </div>
                        <h5 class="fw-bold">Sofort-Buchung</h5>
                        <p class="text-muted">Reservieren Sie Ihren Platz in wenigen Sekunden und erhalten Sie eine Bestätigungsnummer.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card text-center p-4 h-100">
                        <div class="feature-icon mb-3">
                            <i class="bi bi-credit-card text-warning"></i>
                        </div>
                        <h5 class="fw-bold">Flexible Zahlung</h5>
                        <p class="text-muted">Bezahlen Sie bequem per Bar, Überweisung oder PayPal – ganz nach Ihren Wünschen.</p>
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
                <i class="bi bi-calendar-star text-warning me-2"></i>Aktuelle Veranstaltungen
            </h2>
            <div class="row g-4">
                <?php foreach ($aktuelleEvents as $event):
                    $gesamt  = (int)($event['gesamt_sitze'] ?? 0);
                    $belegt  = (int)($event['belegte_sitze'] ?? 0);
                    $prozent = $gesamt > 0 ? round(($belegt / $gesamt) * 100) : 0;
                    $barColor = $prozent >= 90 ? 'bg-danger' : ($prozent >= 70 ? 'bg-warning' : 'bg-success');
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
                                    <span>Auslastung</span>
                                    <strong><?= $prozent ?>%</strong>
                                </div>
                                <div class="progress mb-3" style="height: 8px;">
                                    <div class="progress-bar <?= $barColor ?>" style="width: <?= $prozent ?>%"></div>
                                </div>
                                <small class="text-muted">
                                    <i class="bi bi-people"></i>
                                    <?= $belegt ?> / <?= $gesamt ?> Plätze belegt
                                </small>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent">
                            <a href="/pages/tischplan.php?event_id=<?= $event['id'] ?>" class="btn btn-outline-warning w-100">
                                <i class="bi bi-grid-3x3 me-1"></i>Tischplan anzeigen
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="text-center mt-4">
                <a href="/pages/events.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-right me-1"></i>Alle Events anzeigen
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
                Bereit für Kameruner-Tickets?
            </h3>
            <p class="text-muted mb-4">Melden Sie sich an und reservieren Sie Ihren Platz noch heute!</p>
            <?php if (!isLoggedIn()): ?>
            <a href="/pages/register.php" class="btn btn-warning btn-lg me-2">
                <i class="bi bi-person-plus me-2"></i>Kostenlos registrieren
            </a>
            <a href="/pages/login.php" class="btn btn-outline-light btn-lg">
                <i class="bi bi-box-arrow-in-right me-2"></i>Anmelden
            </a>
            <?php else: ?>
            <a href="/pages/tischplan.php" class="btn btn-warning btn-lg">
                <i class="bi bi-grid-3x3 me-2"></i>Jetzt Platz reservieren
            </a>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

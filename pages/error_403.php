<?php
// Wird sowohl über requireRole() eingebunden als auch per ErrorDocument als
// eigenständiger Request ausgeliefert. Im zweiten Fall ist noch nichts geladen;
// require_once ist deshalb nötig und im ersten Fall ein No-op.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$pageTitle = __('error.forbidden_title');
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>
<main class="py-5">
    <div class="container text-center py-5">
        <div class="display-1 fw-bold text-danger mb-3">403</div>
        <h2 class="fw-bold mb-3"><?= __('error.forbidden_title') ?></h2>
        <p class="text-muted mb-4"><?= __('error.forbidden_text') ?></p>
        <a href="/index.php" class="btn btn-warning me-2">
            <i class="bi bi-house me-2"></i><?= __('error.home_button') ?>
        </a>
        <a href="/pages/events.php" class="btn btn-outline-secondary">
            <i class="bi bi-calendar-event me-2"></i><?= __('error.events_button') ?>
        </a>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

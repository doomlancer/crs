<?php
$pageTitle = 'Seite nicht gefunden';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>
<main class="py-5">
    <div class="container text-center py-5">
        <div class="display-1 fw-bold text-warning mb-3">404</div>
        <h2 class="fw-bold mb-3">Seite nicht gefunden</h2>
        <p class="text-muted mb-4">Die aufgerufene Seite existiert leider nicht.</p>
        <a href="/index.php" class="btn btn-warning me-2">
            <i class="bi bi-house me-2"></i>Zur Startseite
        </a>
        <a href="javascript:history.back()" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-2"></i>Zurück
        </a>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

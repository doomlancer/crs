<?php
$pageTitle = 'Zugriff verweigert';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>
<main class="py-5">
    <div class="container text-center py-5">
        <div class="display-1 fw-bold text-danger mb-3">403</div>
        <h2 class="fw-bold mb-3">Zugriff verweigert</h2>
        <p class="text-muted mb-4">Sie haben keine Berechtigung, diese Seite aufzurufen.</p>
        <a href="/index.php" class="btn btn-warning me-2">
            <i class="bi bi-house me-2"></i>Zur Startseite
        </a>
        <a href="javascript:history.back()" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-2"></i>Zurück
        </a>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>

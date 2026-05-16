    <footer class="footer mt-auto py-3 bg-dark text-light">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <span class="text-muted">
                        <i class="bi bi-music-note-beamed text-warning"></i>
                        <?= htmlspecialchars(APP_NAME) ?> &copy; <?= date('Y') ?>
                    </span>
                </div>
                <div class="col-md-6 text-md-end">
                    <?php if (isLoggedIn()): ?>
                    <small class="text-muted">
                        Angemeldet als <strong><?= htmlspecialchars($_SESSION['vorname'] . ' ' . $_SESSION['nachname']) ?></strong>
                        | <a href="/includes/auth.php?action=logout" class="text-warning text-decoration-none">Abmelden</a>
                    </small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="/js/main.js"></script>
    <?php if (!empty($extraScripts)) echo $extraScripts; ?>
</body>
</html>

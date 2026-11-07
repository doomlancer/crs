    <footer class="footer mt-auto py-3 bg-dark text-light">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <span class="footer-text">
                        <i class="bi bi-music-note-beamed text-warning"></i>
                        <?= htmlspecialchars(APP_NAME) ?> &copy; <?= date('Y') ?>
                    </span>
                </div>
                <div class="col-md-6 text-md-end">
                    <?php /* Einlass-Sitzungen haben kein Konto und ihren eigenen Abmelde-Link auf der Scan-Seite. */ ?>
                    <?php if (isLoggedIn() && !hasRole('einlass')): ?>
                    <small class="footer-text">
                        <?= sprintf(__('footer.logged_in_as'), '<strong>' . htmlspecialchars(trim(($_SESSION['vorname'] ?? '') . ' ' . ($_SESSION['nachname'] ?? ''))) . '</strong>') ?>
                        | <a href="/includes/auth.php?action=logout" class="text-warning text-decoration-none"><?= __('nav.logout') ?></a>
                    </small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </footer>

    <script src="/assets/vendor/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/vendor/js/chart.umd.min.js"></script>
    <?php if (file_exists(__DIR__ . '/../dist/js/main.js')): ?>
    <script src="/dist/js/main.js"></script>
    <?php else: ?>
    <script src="/js/main.js"></script>
    <?php endif; ?>
    <?php
    // Nonce injizieren, damit die Inline-Blöcke der Seiten die CSP passieren
    if (!empty($extraScripts)) echo withCspNonce($extraScripts);
    ?>
</body>
</html>

<?php
require_once __DIR__ . '/lang.php';
$currentPage = basename($_SERVER['PHP_SELF']);
$currentLang = $_SESSION['lang'] ?? 'de';
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="/index.php">
            <?php
            $logoPath = defined('LOGO_PATH') ? LOGO_PATH : '';
            $logoFile = __DIR__ . '/../' . ltrim($logoPath, '/');
            if ($logoPath && file_exists($logoFile)): ?>
                <img src="<?= htmlspecialchars($logoPath) ?>?v=<?= filemtime($logoFile) ?>"
                     alt="<?= htmlspecialchars(APP_NAME) ?>" height="36" style="max-height:36px;">
            <?php else: ?>
                <i class="bi bi-music-note-beamed text-warning"></i>
                <span class="text-warning"><?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Kameruner-Tickets') ?></span>
            <?php endif; ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'events.php' ? 'active' : '' ?>"
                       href="/pages/events.php">
                        <i class="bi bi-calendar-event"></i> <?= __('nav_events') ?>
                    </a>
                </li>

                <?php if (isLoggedIn()): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'tischplan.php' ? 'active' : '' ?>"
                       href="/pages/tischplan.php">
                        <i class="bi bi-grid-3x3"></i> <?= __('nav_tischplan') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'meine_reservierungen.php' ? 'active' : '' ?>"
                       href="/pages/meine_reservierungen.php">
                        <i class="bi bi-ticket-perforated"></i> <?= __('nav_my_bookings') ?>
                    </a>
                </li>

                <?php if (hasRole('kassierer', 'admin')): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= str_starts_with($currentPage, 'kassierer_') ? 'active' : '' ?>"
                       href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-cash-register"></i> <?= __('nav_cashier') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li><a class="dropdown-item" href="/pages/kassierer_dashboard.php">
                            <i class="bi bi-speedometer2"></i> <?= __('nav_dashboard') ?>
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/kassierer_guestlist.php">
                            <i class="bi bi-people"></i> <?= __('nav_guestlist') ?>
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/kassierer_tagesabschluss.php">
                            <i class="bi bi-clipboard-data"></i> <?= __('nav_daily_close') ?>
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/kassierer_statistiken.php">
                            <i class="bi bi-bar-chart"></i> <?= __('nav_statistics') ?>
                        </a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (hasRole('admin')): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= str_starts_with($currentPage, 'admin_') ? 'active' : '' ?>"
                       href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-gear"></i> <?= __('nav_admin') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li><a class="dropdown-item" href="/pages/admin_dashboard.php">
                            <i class="bi bi-speedometer2"></i> <?= __('nav_dashboard') ?>
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/admin_events.php">
                            <i class="bi bi-calendar-plus"></i> <?= __('nav_event_mgmt') ?>
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/admin_users.php">
                            <i class="bi bi-people-fill"></i> <?= __('nav_users') ?>
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/admin_statistiken.php">
                            <i class="bi bi-graph-up"></i> <?= __('nav_statistics') ?>
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/admin_auditlog.php">
                            <i class="bi bi-shield-check"></i> <?= __('nav_audit') ?>
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/pages/admin_settings.php">
                            <i class="bi bi-sliders"></i> <?= __('nav_settings') ?>
                        </a></li>
                    </ul>
                </li>
                <?php endif; ?>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav ms-auto align-items-center gap-2">
                <!-- Language Toggle -->
                <li class="nav-item">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Language">
                        <a href="/api/set_lang.php?lang=de"
                           class="btn <?= $currentLang === 'de' ? 'btn-warning text-dark fw-bold' : 'btn-outline-secondary text-light' ?>">
                            DE
                        </a>
                        <a href="/api/set_lang.php?lang=en"
                           class="btn <?= $currentLang === 'en' ? 'btn-warning text-dark fw-bold' : 'btn-outline-secondary text-light' ?>">
                            EN
                        </a>
                    </div>
                </li>

                <?php if (isLoggedIn()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <?= htmlspecialchars($_SESSION['vorname']) ?>
                        <?php if (hasRole('admin')): ?>
                            <span class="badge bg-danger ms-1">Admin</span>
                        <?php elseif (hasRole('kassierer')): ?>
                            <span class="badge bg-warning text-dark ms-1">Kassierer</span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
                        <li><a class="dropdown-item" href="/pages/profil.php">
                            <i class="bi bi-person"></i> <?= __('nav_profile') ?>
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="/includes/auth.php?action=logout">
                                <i class="bi bi-box-arrow-right"></i> <?= __('nav_logout') ?>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php else: ?>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'login.php' ? 'active' : '' ?>"
                       href="/pages/login.php">
                        <i class="bi bi-box-arrow-in-right"></i> <?= __('nav_login') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="btn btn-warning btn-sm ms-2" href="/pages/register.php">
                        <i class="bi bi-person-plus"></i> <?= __('nav_register') ?>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

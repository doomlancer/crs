<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$currentLang = getCurrentLang();
$currentUrl  = htmlspecialchars($_SERVER['REQUEST_URI']);
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/index.php">
            <i class="bi bi-music-note-beamed text-warning"></i>
            <span class="text-warning">Kameruner</span>-Tickets
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'events.php' ? 'active' : '' ?>"
                       href="/pages/events.php">
                        <i class="bi bi-calendar-event"></i> Events
                    </a>
                </li>

                <?php if (isLoggedIn()): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'tischplan.php' ? 'active' : '' ?>"
                       href="/pages/tischplan.php">
                        <i class="bi bi-grid-3x3"></i> Tischplan
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'meine_reservierungen.php' ? 'active' : '' ?>"
                       href="/pages/meine_reservierungen.php">
                        <i class="bi bi-ticket-perforated"></i> Meine Reservierungen
                    </a>
                </li>

                <?php if (hasRole('kassierer', 'admin')): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= str_starts_with($currentPage, 'kassierer_') ? 'active' : '' ?>"
                       href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-cash-register"></i> Kassierer
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li><a class="dropdown-item" href="/pages/kassierer_dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/kassierer_guestlist.php">
                            <i class="bi bi-people"></i> Gästeliste
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/kassierer_statistiken.php">
                            <i class="bi bi-bar-chart"></i> Statistiken
                        </a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (hasRole('admin')): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= str_starts_with($currentPage, 'admin_') ? 'active' : '' ?>"
                       href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-gear"></i> Admin
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li><a class="dropdown-item" href="/pages/admin_dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/admin_events.php">
                            <i class="bi bi-calendar-plus"></i> Event-Management
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/admin_users.php">
                            <i class="bi bi-people-fill"></i> Benutzer
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/admin_statistiken.php">
                            <i class="bi bi-graph-up"></i> Statistiken
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/admin_auditlog.php">
                            <i class="bi bi-shield-check"></i> Audit-Log
                        </a></li>
                    </ul>
                </li>
                <?php endif; ?>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav ms-auto">
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
                            <i class="bi bi-person"></i> Mein Profil
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="/includes/auth.php?action=logout">
                                <i class="bi bi-box-arrow-right"></i> Abmelden
                            </a>
                        </li>
                    </ul>
                </li>
                <?php else: ?>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'login.php' ? 'active' : '' ?>"
                       href="/pages/login.php">
                        <i class="bi bi-box-arrow-in-right"></i> <?= __('nav.login') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="btn btn-warning btn-sm ms-2" href="/pages/register.php">
                        <i class="bi bi-person-plus"></i> <?= __('nav.register') ?>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Sprachumschalter -->
                <li class="nav-item ms-2">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Sprache">
                        <a href="/api/set_lang.php?lang=de&redirect=<?= urlencode($currentUrl) ?>"
                           class="btn <?= $currentLang === 'de' ? 'btn-warning' : 'btn-outline-secondary' ?>"
                           title="Deutsch">DE</a>
                        <a href="/api/set_lang.php?lang=en&redirect=<?= urlencode($currentUrl) ?>"
                           class="btn <?= $currentLang === 'en' ? 'btn-warning' : 'btn-outline-secondary' ?>"
                           title="English">EN</a>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</nav>

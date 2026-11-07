<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$currentLang = getCurrentLang();
// urlencode() allein reicht für den href-Wert – das Ergebnis enthält nur
// %XX-Folgen und alphanumerische Zeichen, keine HTML-Sonderzeichen mehr.
// Ein vorheriges htmlspecialchars() hätte "&" zu "&amp;" gemacht, das
// urlencode() dann nochmal zu "%26amp%3B" verschlüsselt hätte – der
// Sprachumschalter riss dadurch bei Seiten mit mehreren Query-Parametern
// (z.B. "?event_id=3&suche=meier") alle Parameter ab dem zweiten weg.
$currentUrl  = urlencode($_SERVER['REQUEST_URI']);

$_navLogoFile = getSetting('app_logo', '');
$_navLogoSrc  = ($_navLogoFile && file_exists(UPLOAD_DIR . $_navLogoFile))
    ? '/uploads/' . htmlspecialchars($_navLogoFile)
    : null;
$_navAppName  = getSetting('app_name', APP_NAME);
// Split on last hyphen for two-tone brand rendering; fallback: show full name
$_navDash = strrpos($_navAppName, '-');
$_navNameA = $_navDash !== false ? substr($_navAppName, 0, $_navDash) : $_navAppName;
$_navNameB = $_navDash !== false ? substr($_navAppName, $_navDash)    : '';
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="/index.php">
            <?php if ($_navLogoSrc): ?>
            <img src="<?= $_navLogoSrc ?>"
                 alt="<?= htmlspecialchars($_navAppName) ?>"
                 class="club-logo">
            <?php endif; ?>
            <span><span style="color:var(--club-red);"><?= htmlspecialchars($_navNameA) ?></span><?= htmlspecialchars($_navNameB) ?></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'events.php' ? 'active' : '' ?>"
                       href="/pages/events.php">
                        <i class="bi bi-calendar-event"></i> <?= __('nav.events') ?>
                    </a>
                </li>

                <?php if (isLoggedIn() && !hasRole('kassierer', 'admin')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'meine_reservierungen.php' ? 'active' : '' ?>"
                       href="/pages/meine_reservierungen.php">
                        <i class="bi bi-ticket-perforated"></i> <?= __('nav.my_reservations') ?>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (hasRole('kassierer', 'admin')): ?>
                <!-- Bereich "Abend": alles für den Einlass und die Kasse -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= (str_starts_with($currentPage, 'kassierer_') || $currentPage === 'event_live_dashboard.php') ? 'active' : '' ?>"
                       href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-broadcast"></i> Abend
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li><a class="dropdown-item" href="/pages/event_live_dashboard.php">
                            <i class="bi bi-grid-3x3-gap"></i> Live-Übersicht
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/kassierer_scan.php">
                            <i class="bi bi-qr-code-scan"></i> QR-Scanner
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/kassierer_guestlist.php">
                            <i class="bi bi-people"></i> Gästeliste
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/kassierer_dashboard.php">
                            <i class="bi bi-cash-stack"></i> Kassen-Übersicht
                        </a></li>
                        <?php if (!hasRole('admin')): ?>
                        <li><a class="dropdown-item" href="/pages/kassierer_statistiken.php">
                            <i class="bi bi-bar-chart"></i> Statistik
                        </a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (hasRole('admin')): ?>
                <!-- Bereich "Verwaltung": Vorbereitung und Auswertung -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= str_starts_with($currentPage, 'admin_') ? 'active' : '' ?>"
                       href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-gear"></i> Verwaltung
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li><a class="dropdown-item" href="/pages/admin_dashboard.php">
                            <i class="bi bi-speedometer2"></i> Übersicht
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/admin_events.php">
                            <i class="bi bi-calendar-plus"></i> Events &amp; Tische
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/admin_reservierungen.php">
                            <i class="bi bi-ticket-perforated-fill"></i> Buchungen
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/admin_users.php">
                            <i class="bi bi-people-fill"></i> Benutzer
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/admin_statistiken.php">
                            <i class="bi bi-graph-up"></i> Auswertung
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/pages/admin_einstellungen.php">
                            <i class="bi bi-palette"></i> Design &amp; Einstellungen
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/admin_uebersetzungen.php">
                            <i class="bi bi-translate"></i> Übersetzungen
                        </a></li>
                        <li><a class="dropdown-item" href="/pages/admin_auditlog.php">
                            <i class="bi bi-shield-check"></i> Audit-Log
                        </a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav ms-auto">
                <?php if (isLoggedIn()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <?= htmlspecialchars($_SESSION['vorname'] ?? '') ?>
                        <?php if (hasRole('admin')): ?>
                            <span class="badge bg-danger ms-1"><?= __('nav.admin') ?></span>
                        <?php elseif (hasRole('kassierer')): ?>
                            <span class="badge bg-warning text-dark ms-1"><?= __('nav.cashier') ?></span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
                        <li><a class="dropdown-item" href="/pages/profil.php">
                            <i class="bi bi-person"></i> <?= __('nav.my_profile') ?>
                        </a></li>
                        <?php if (hasRole('kassierer', 'admin')): ?>
                        <li><a class="dropdown-item" href="/pages/meine_reservierungen.php">
                            <i class="bi bi-ticket-perforated"></i> <?= __('nav.my_reservations') ?>
                        </a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="/includes/auth.php?action=logout">
                                <i class="bi bi-box-arrow-right"></i> <?= __('nav.logout') ?>
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

                <!-- Sprachumschalter: nur auf Gast-Seiten – die Personal- und
                     Verwaltungsseiten gibt es nur auf Deutsch. -->
                <?php if (!preg_match('/^(admin_|kassierer_|event_live_|einlass_)/', $currentPage)): ?>
                <li class="nav-item ms-2">
                    <div class="btn-group btn-group-sm" role="group" aria-label="<?= htmlspecialchars(__('nav.language_aria')) ?>">
                        <a href="/api/set_lang.php?lang=de&redirect=<?= $currentUrl ?>"
                           class="btn <?= $currentLang === 'de' ? 'btn-warning' : 'btn-outline-secondary' ?>"
                           title="<?= htmlspecialchars(__('general.language_de')) ?>">DE</a>
                        <a href="/api/set_lang.php?lang=en&redirect=<?= $currentUrl ?>"
                           class="btn <?= $currentLang === 'en' ? 'btn-warning' : 'btn-outline-secondary' ?>"
                           title="<?= htmlspecialchars(__('general.language_en')) ?>">EN</a>
                    </div>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

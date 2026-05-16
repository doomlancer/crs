<?php
/**
 * Admin: Audit-Log Viewer mit Pagination und Filtern
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');

$pdo = getDB();

// Pagination
$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Filter
$filterUser   = (int)($_GET['user_id'] ?? 0);
$filterAktion = trim($_GET['aktion'] ?? '');
$filterTabelle = trim($_GET['tabelle'] ?? '');
$filterDatumVon = trim($_GET['datum_von'] ?? '');
$filterDatumBis = trim($_GET['datum_bis'] ?? '');
$search       = trim($_GET['q'] ?? '');

// WHERE-Bedingungen aufbauen
$where  = ['1=1'];
$params = [];

if ($filterUser) {
    $where[]  = 'a.user_id = ?';
    $params[] = $filterUser;
}
if ($filterAktion) {
    $where[]  = 'a.aktion LIKE ?';
    $params[] = '%' . $filterAktion . '%';
}
if ($filterTabelle) {
    $where[]  = 'a.tabelle = ?';
    $params[] = $filterTabelle;
}
if ($filterDatumVon) {
    $where[]  = 'DATE(a.zeitstempel) >= ?';
    $params[] = $filterDatumVon;
}
if ($filterDatumBis) {
    $where[]  = 'DATE(a.zeitstempel) <= ?';
    $params[] = $filterDatumBis;
}
if ($search) {
    $where[]  = '(a.aktion LIKE ? OR a.aenderung LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$whereSQL = implode(' AND ', $where);

// Gesamtanzahl
$stmtCount = $pdo->prepare(
    "SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON a.user_id = u.id WHERE {$whereSQL}"
);
$stmtCount->execute($params);
$total    = (int)$stmtCount->fetchColumn();
$maxPages = (int)ceil($total / $perPage);

// Einträge laden
$stmt = $pdo->prepare(
    "SELECT a.*, u.vorname, u.nachname, u.email
     FROM audit_log a
     LEFT JOIN users u ON a.user_id = u.id
     WHERE {$whereSQL}
     ORDER BY a.zeitstempel DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($params, [$perPage, $offset]));
$logs = $stmt->fetchAll();

// Distinkte Tabellen für Filter
$tabellen = $pdo->query('SELECT DISTINCT tabelle FROM audit_log ORDER BY tabelle')->fetchAll(PDO::FETCH_COLUMN);

// Benutzer für Filter
$benutzer = $pdo->query('SELECT id, vorname, nachname, email FROM users ORDER BY nachname, vorname')->fetchAll();

$pageTitle = 'Audit-Log';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';

// Aktion → CSS-Klasse
function auditRowClass(string $aktion): string {
    $aktion = strtolower($aktion);
    if (str_starts_with($aktion, 'login'))         return 'audit-login';
    if (str_starts_with($aktion, 'logout'))        return 'audit-logout';
    if (str_contains($aktion, 'delete') || str_contains($aktion, 'loeschung')) return 'audit-delete';
    if (str_contains($aktion, 'erstell') || str_contains($aktion, 'registr') || str_contains($aktion, 'create')) return 'audit-create';
    if (str_contains($aktion, 'reservier'))        return 'audit-reservierung';
    if (str_contains($aktion, 'checkin'))          return 'audit-checkin';
    if (str_contains($aktion, 'stornier'))         return 'audit-stornierung';
    if (str_contains($aktion, 'export'))           return 'audit-export';
    return 'audit-update';
}
?>

<main class="py-4">
<div class="container-fluid px-4">
    <?= getFlash() ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold mb-0">
            <i class="bi bi-shield-check text-warning me-2"></i>Audit-Log
        </h2>
        <span class="badge bg-secondary fs-6"><?= number_format($total, 0, ',', '.') ?> Einträge</span>
    </div>

    <!-- Filter-Karte -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header fw-bold">
            <i class="bi bi-funnel me-2 text-warning"></i>Filter
        </div>
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small fw-bold">Suche</label>
                    <input type="text" name="q" class="form-control form-control-sm"
                           placeholder="Aktion, Änderung, E-Mail…"
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small fw-bold">Benutzer</label>
                    <select name="user_id" class="form-select form-select-sm">
                        <option value="">Alle</option>
                        <?php foreach ($benutzer as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $filterUser === $u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['vorname'] . ' ' . $u['nachname']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small fw-bold">Tabelle</label>
                    <select name="tabelle" class="form-select form-select-sm">
                        <option value="">Alle</option>
                        <?php foreach ($tabellen as $tab): ?>
                        <option value="<?= htmlspecialchars($tab) ?>" <?= $filterTabelle === $tab ? 'selected' : '' ?>>
                            <?= htmlspecialchars($tab) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small fw-bold">Von</label>
                    <input type="date" name="datum_von" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filterDatumVon) ?>">
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small fw-bold">Bis</label>
                    <input type="date" name="datum_bis" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filterDatumBis) ?>">
                </div>
                <div class="col-sm-6 col-md-1">
                    <button type="submit" class="btn btn-warning btn-sm w-100">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Legende -->
    <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="d-flex align-items-center gap-1 badge bg-success bg-opacity-75">LOGIN</span>
        <span class="d-flex align-items-center gap-1 badge bg-primary bg-opacity-75">ERSTELLEN</span>
        <span class="d-flex align-items-center gap-1 badge bg-warning text-dark">ÄNDERN</span>
        <span class="d-flex align-items-center gap-1 badge bg-danger bg-opacity-75">LÖSCHEN</span>
        <span class="d-flex align-items-center gap-1 badge bg-purple text-white" style="background:#8b5cf6!important;">RESERVIERUNG</span>
        <span class="d-flex align-items-center gap-1 badge bg-info">CHECK-IN</span>
    </div>

    <!-- Log-Tabelle -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th width="150">Zeitstempel</th>
                            <th width="160">Benutzer</th>
                            <th width="150">Aktion</th>
                            <th width="120">Tabelle</th>
                            <th width="80">DS-ID</th>
                            <th>Änderung</th>
                            <th width="120">IP-Adresse</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="bi bi-inbox display-4 d-block mb-2"></i>
                                Keine Einträge gefunden.
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($logs as $log):
                            $rowClass = auditRowClass($log['aktion']);
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td>
                                <small class="text-muted">
                                    <?= date('d.m.Y', strtotime($log['zeitstempel'])) ?><br>
                                    <strong><?= date('H:i:s', strtotime($log['zeitstempel'])) ?></strong>
                                </small>
                            </td>
                            <td>
                                <?php if ($log['user_id']): ?>
                                    <small>
                                        <strong><?= htmlspecialchars($log['vorname'] . ' ' . $log['nachname']) ?></strong><br>
                                        <span class="text-muted"><?= htmlspecialchars($log['email'] ?? '') ?></span>
                                    </small>
                                <?php else: ?>
                                    <span class="text-muted small">System</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $aktion = strtoupper($log['aktion']);
                                $badgeClass = match(true) {
                                    str_starts_with($aktion, 'LOGIN')      => 'bg-success',
                                    str_starts_with($aktion, 'LOGOUT')     => 'bg-secondary',
                                    str_contains($aktion, 'DELETE') || str_contains($aktion, 'STONIER') => 'bg-danger',
                                    str_contains($aktion, 'ERSTELL') || str_contains($aktion, 'REGISTR') || str_contains($aktion, 'CREATE') => 'bg-primary',
                                    str_contains($aktion, 'RESERVIER')     => 'bg-purple',
                                    str_contains($aktion, 'CHECKIN')       => 'bg-info',
                                    str_contains($aktion, 'EXPORT')        => 'bg-cyan',
                                    default                                 => 'bg-warning text-dark',
                                };
                                ?>
                                <span class="badge <?= $badgeClass ?>" style="<?= $badgeClass === 'bg-purple' ? 'background:#8b5cf6!important;' : '' ?>">
                                    <?= htmlspecialchars($log['aktion']) ?>
                                </span>
                            </td>
                            <td><code class="small"><?= htmlspecialchars($log['tabelle']) ?></code></td>
                            <td class="text-muted small"><?= $log['datensatz_id'] ? '#' . $log['datensatz_id'] : '–' ?></td>
                            <td>
                                <small class="text-muted text-truncate d-block" style="max-width:300px;"
                                       title="<?= htmlspecialchars($log['aenderung'] ?? '') ?>">
                                    <?= htmlspecialchars(mb_substr($log['aenderung'] ?? '–', 0, 100)) ?>
                                    <?= strlen($log['aenderung'] ?? '') > 100 ? '…' : '' ?>
                                </small>
                            </td>
                            <td><code class="small text-muted"><?= htmlspecialchars($log['ip_adresse'] ?? '–') ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($maxPages > 1): ?>
        <div class="card-footer bg-transparent">
            <nav>
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    <?php
                    $queryBase = http_build_query(array_filter([
                        'q'         => $search,
                        'user_id'   => $filterUser ?: null,
                        'tabelle'   => $filterTabelle,
                        'datum_von' => $filterDatumVon,
                        'datum_bis' => $filterDatumBis,
                    ]));
                    ?>
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= $queryBase ?>&page=<?= $page - 1 ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    <?php
                    $range = range(max(1, $page - 2), min($maxPages, $page + 2));
                    foreach ($range as $p):
                    ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= $queryBase ?>&page=<?= $p ?>"><?= $p ?></a>
                    </li>
                    <?php endforeach; ?>
                    <li class="page-item <?= $page >= $maxPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= $queryBase ?>&page=<?= $page + 1 ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <p class="text-center text-muted small mb-0 mt-1">
                Seite <?= $page ?> von <?= $maxPages ?> (<?= $total ?> Einträge gesamt)
            </p>
        </div>
        <?php endif; ?>
    </div>
</div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

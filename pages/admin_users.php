<?php
/**
 * Admin Benutzerverwaltung
 * Vollständiges CRUD für Benutzer mit Rollen- und Status-Verwaltung.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

requireRole('admin');

$pdo       = getDB();
$myId      = (int)$_SESSION['user_id'];
$action    = $_GET['action'] ?? '';
$errors    = [];

// ═══════════════════════════════════════════════════════════════════════════════
// POST-Handler
// ═══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiges CSRF-Token. Bitte erneut versuchen.');
        redirect('/pages/admin_users.php');
    }

    $postAction = $_POST['post_action'] ?? '';

    // ── Benutzer erstellen ───────────────────────────────────────────────────
    if ($postAction === 'create_user') {
        $vorname     = sanitize($_POST['vorname'] ?? '');
        $nachname    = sanitize($_POST['nachname'] ?? '');
        $email       = trim(strtolower($_POST['email'] ?? ''));
        $passwort    = $_POST['passwort'] ?? '';
        $zahlungsart = in_array($_POST['zahlungsart'] ?? '', ['bar','ueberweisung','paypal'])
                       ? $_POST['zahlungsart'] : 'bar';
        $adresse     = sanitize($_POST['adresse'] ?? '');
        $rolle       = in_array($_POST['rolle'] ?? '', ['user','kassierer','admin'])
                       ? $_POST['rolle'] : 'user';
        $aktiv       = isset($_POST['aktiv']) ? 1 : 0;

        if (empty($vorname))  $errors[] = 'Vorname ist erforderlich.';
        if (empty($nachname)) $errors[] = 'Nachname ist erforderlich.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Ungültige E-Mail-Adresse.';
        if (strlen($passwort) < 8) $errors[] = 'Passwort muss mindestens 8 Zeichen lang sein.';

        if (empty($errors)) {
            // E-Mail-Duplikat prüfen
            $chk = $pdo->prepare('SELECT id FROM users WHERE email=?');
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $errors[] = 'Diese E-Mail-Adresse ist bereits vergeben.';
            }
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare(
                'INSERT INTO users (vorname, nachname, email, passwort, zahlungsart, adresse, rolle, aktiv)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $vorname, $nachname, $email, hashPassword($passwort),
                $zahlungsart, $adresse, $rolle, $aktiv
            ]);
            $newId = (int)$pdo->lastInsertId();
            logAudit('CREATE', 'users', $newId,
                json_encode(compact('vorname','nachname','email','rolle','aktiv')));
            setFlash('success', "Benutzer {$vorname} {$nachname} wurde erfolgreich erstellt.");
            redirect('/pages/admin_users.php');
        }
    }

    // ── Benutzer bearbeiten ──────────────────────────────────────────────────
    if ($postAction === 'edit_user') {
        $id          = (int)($_POST['user_id'] ?? 0);
        $vorname     = sanitize($_POST['vorname'] ?? '');
        $nachname    = sanitize($_POST['nachname'] ?? '');
        $email       = trim(strtolower($_POST['email'] ?? ''));
        $zahlungsart = in_array($_POST['zahlungsart'] ?? '', ['bar','ueberweisung','paypal'])
                       ? $_POST['zahlungsart'] : 'bar';
        $adresse     = sanitize($_POST['adresse'] ?? '');
        $rolle       = in_array($_POST['rolle'] ?? '', ['user','kassierer','admin'])
                       ? $_POST['rolle'] : 'user';
        $aktiv       = isset($_POST['aktiv']) ? 1 : 0;

        // Eigenes Konto: kein Deaktivieren oder Herabstufen
        if ($id === $myId) {
            $aktiv = 1;
            $rolle = 'admin';
        }

        if (empty($vorname))  $errors[] = 'Vorname ist erforderlich.';
        if (empty($nachname)) $errors[] = 'Nachname ist erforderlich.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Ungültige E-Mail-Adresse.';

        if (empty($errors) && $id > 0) {
            // E-Mail-Duplikat prüfen (außer eigenem)
            $chk = $pdo->prepare('SELECT id FROM users WHERE email=? AND id!=?');
            $chk->execute([$email, $id]);
            if ($chk->fetch()) {
                $errors[] = 'Diese E-Mail-Adresse ist bereits vergeben.';
            }
        }

        if (empty($errors) && $id > 0) {
            // Passwort optional ändern
            $pwSet = '';
            $params = [$vorname, $nachname, $email, $zahlungsart, $adresse, $rolle, $aktiv];
            if (!empty($_POST['neues_passwort'])) {
                if (strlen($_POST['neues_passwort']) < 8) {
                    $errors[] = 'Neues Passwort muss mindestens 8 Zeichen lang sein.';
                } else {
                    $pwSet = ', passwort=?';
                    $params[] = hashPassword($_POST['neues_passwort']);
                }
            }

            if (empty($errors)) {
                $params[] = $id;
                $stmt = $pdo->prepare(
                    "UPDATE users SET vorname=?, nachname=?, email=?, zahlungsart=?,
                     adresse=?, rolle=?, aktiv=? {$pwSet} WHERE id=?"
                );
                $stmt->execute($params);
                logAudit('UPDATE', 'users', $id,
                    json_encode(compact('vorname','nachname','email','rolle','aktiv')));
                setFlash('success', "Benutzer {$vorname} {$nachname} wurde aktualisiert.");
                redirect('/pages/admin_users.php');
            }
        }
    }

    // ── Aktiv-Status umschalten ──────────────────────────────────────────────
    if ($postAction === 'toggle_aktiv') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id === $myId) {
            setFlash('error', 'Sie können Ihr eigenes Konto nicht deaktivieren.');
            redirect('/pages/admin_users.php');
        }
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT aktiv, vorname, nachname FROM users WHERE id=?');
            $stmt->execute([$id]);
            $u = $stmt->fetch();
            if ($u) {
                $neu = $u['aktiv'] ? 0 : 1;
                $pdo->prepare('UPDATE users SET aktiv=? WHERE id=?')->execute([$neu, $id]);
                $label = $neu ? 'aktiviert' : 'deaktiviert';
                logAudit('UPDATE', 'users', $id, "aktiv gesetzt auf: {$neu}");
                setFlash('success', "Benutzer {$u['vorname']} {$u['nachname']} wurde {$label}.");
            }
        }
        redirect('/pages/admin_users.php' . (isset($_GET['search']) ? '?search=' . urlencode($_GET['search']) : ''));
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// Daten laden
// ═══════════════════════════════════════════════════════════════════════════════

// Filter-Parameter
$search      = sanitize($_GET['search'] ?? '');
$filterRolle = in_array($_GET['rolle'] ?? '', ['','user','kassierer','admin'])
               ? ($_GET['rolle'] ?? '') : '';

// Benutzer mit Reservierungsanzahl laden
$where  = [];
$params = [];
if ($search !== '') {
    $where[]  = '(u.vorname LIKE ? OR u.nachname LIKE ? OR u.email LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($filterRolle !== '') {
    $where[]  = 'u.rolle = ?';
    $params[] = $filterRolle;
}
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$users = $pdo->prepare(
    "SELECT u.*,
            COUNT(r.id) AS reservierungen
     FROM users u
     LEFT JOIN reservations r ON r.user_id = u.id
     {$whereClause}
     GROUP BY u.id
     ORDER BY u.erstellt_am DESC"
);
$users->execute($params);
$users = $users->fetchAll();

// Editier-Modus: Benutzer laden
$editUser = null;
if ($action === 'edit' && isset($_GET['user_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
    $stmt->execute([(int)$_GET['user_id']]);
    $editUser = $stmt->fetch();
}

$pageTitle = 'Admin – Benutzerverwaltung';
$bodyClass = 'bg-light';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container-fluid py-4">

    <!-- Seitentitel -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-0">
                <i class="bi bi-people-fill text-secondary me-2"></i>Benutzerverwaltung
            </h1>
            <p class="text-muted mb-0 small">Benutzer anlegen, bearbeiten und verwalten</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="/pages/admin_dashboard.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
                <i class="bi bi-person-plus me-1"></i>Neuer Benutzer
            </button>
        </div>
    </div>

    <?= getFlash() ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible">
        <strong>Fehler:</strong>
        <ul class="mb-0 mt-1"><?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?></ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ═══ Benutzer bearbeiten (Inline) ════════════════════════════════════════ -->
    <?php if ($editUser): ?>
    <div class="card border-0 shadow-sm border-start border-warning border-4 mb-4">
        <div class="card-header bg-warning bg-opacity-10 d-flex align-items-center justify-content-between">
            <h5 class="mb-0 fw-semibold text-warning">
                <i class="bi bi-pencil-square me-2"></i>Benutzer bearbeiten:
                <?= htmlspecialchars($editUser['vorname'] . ' ' . $editUser['nachname']) ?>
            </h5>
            <a href="/pages/admin_users.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-x"></i>
            </a>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrfField() ?>
                <input type="hidden" name="post_action" value="edit_user">
                <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">

                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Vorname <span class="text-danger">*</span></label>
                    <input type="text" name="vorname" class="form-control"
                           value="<?= htmlspecialchars($editUser['vorname']) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Nachname <span class="text-danger">*</span></label>
                    <input type="text" name="nachname" class="form-control"
                           value="<?= htmlspecialchars($editUser['nachname']) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">E-Mail <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($editUser['email']) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Adresse</label>
                    <input type="text" name="adresse" class="form-control"
                           value="<?= htmlspecialchars($editUser['adresse'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Zahlungsart</label>
                    <select name="zahlungsart" class="form-select">
                        <?php foreach (['bar'=>'Bar','ueberweisung'=>'Überweisung','paypal'=>'PayPal'] as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= $editUser['zahlungsart'] === $k ? 'selected':'' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Rolle</label>
                    <?php if ($editUser['id'] === $myId): ?>
                        <input type="text" class="form-control" value="Admin (eigenes Konto)" readonly>
                        <input type="hidden" name="rolle" value="admin">
                    <?php else: ?>
                        <select name="rolle" class="form-select">
                            <option value="user"      <?= $editUser['rolle']==='user'      ? 'selected':'' ?>>Benutzer</option>
                            <option value="kassierer" <?= $editUser['rolle']==='kassierer' ? 'selected':'' ?>>Kassierer</option>
                            <option value="admin"     <?= $editUser['rolle']==='admin'     ? 'selected':'' ?>>Admin</option>
                        </select>
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Neues Passwort <span class="text-muted">(optional)</span></label>
                    <input type="password" name="neues_passwort" class="form-control" minlength="8"
                           placeholder="Leer lassen = unverändert">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check form-switch ms-2 mt-2">
                        <?php if ($editUser['id'] === $myId): ?>
                            <input class="form-check-input" type="checkbox" disabled checked>
                            <input type="hidden" name="aktiv" value="1">
                            <label class="form-check-label">Aktiv <small class="text-muted">(eigenes Konto)</small></label>
                        <?php else: ?>
                            <input class="form-check-input" type="checkbox" name="aktiv" id="editAktiv"
                                   <?= $editUser['aktiv'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="editAktiv">Aktiv</label>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-save me-1"></i>Speichern
                    </button>
                    <a href="/pages/admin_users.php" class="btn btn-outline-secondary">Abbrechen</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ Filter & Suche ══════════════════════════════════════════════════════ -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <label class="form-label fw-semibold small mb-1">Suche</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control"
                               placeholder="Name oder E-Mail..."
                               value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label fw-semibold small mb-1">Rolle</label>
                    <select name="rolle" class="form-select form-select-sm">
                        <option value="">Alle Rollen</option>
                        <option value="user"      <?= $filterRolle==='user'      ? 'selected':'' ?>>Benutzer</option>
                        <option value="kassierer" <?= $filterRolle==='kassierer' ? 'selected':'' ?>>Kassierer</option>
                        <option value="admin"     <?= $filterRolle==='admin'     ? 'selected':'' ?>>Admin</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-funnel me-1"></i>Filtern
                    </button>
                </div>
                <div class="col-12 col-md-2">
                    <a href="/pages/admin_users.php" class="btn btn-sm btn-outline-secondary w-100">
                        <i class="bi bi-x me-1"></i>Zurücksetzen
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══ Benutzerliste ═══════════════════════════════════════════════════════ -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between">
            <h5 class="mb-0 fw-semibold">
                <i class="bi bi-list-ul me-2 text-secondary"></i>Benutzer
                <span class="badge bg-secondary ms-1"><?= count($users) ?></span>
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($users)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-person-x fs-1 d-block mb-2"></i>
                Keine Benutzer gefunden.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>E-Mail</th>
                            <th>Rolle</th>
                            <th>Zahlungsart</th>
                            <th>Reservierungen</th>
                            <th>Status</th>
                            <th>Registriert</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr class="<?= !$u['aktiv'] ? 'table-secondary text-muted' : '' ?>">
                        <td class="small text-muted">#<?= $u['id'] ?></td>
                        <td>
                            <div class="fw-semibold d-flex align-items-center gap-1">
                                <?php if ($u['id'] === $myId): ?>
                                <i class="bi bi-person-fill-check text-success" title="Ihr Konto"></i>
                                <?php endif; ?>
                                <?= htmlspecialchars($u['vorname'] . ' ' . $u['nachname']) ?>
                            </div>
                            <?php if (!empty($u['adresse'])): ?>
                            <small class="text-muted"><?= htmlspecialchars($u['adresse']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="mailto:<?= htmlspecialchars($u['email']) ?>"
                               class="text-decoration-none small">
                                <?= htmlspecialchars($u['email']) ?>
                            </a>
                        </td>
                        <td>
                            <?php
                            $rolleClass = match($u['rolle']) {
                                'admin'     => 'bg-danger',
                                'kassierer' => 'bg-warning text-dark',
                                default     => 'bg-secondary',
                            };
                            $rolleLabel = match($u['rolle']) {
                                'admin'     => 'Admin',
                                'kassierer' => 'Kassierer',
                                default     => 'Benutzer',
                            };
                            ?>
                            <span class="badge <?= $rolleClass ?>"><?= $rolleLabel ?></span>
                        </td>
                        <td>
                            <small><?= zahlungsartLabel($u['zahlungsart']) ?></small>
                        </td>
                        <td>
                            <?php if ((int)$u['reservierungen'] > 0): ?>
                            <span class="badge bg-primary bg-opacity-75">
                                <?= (int)$u['reservierungen'] ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted small">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['aktiv']): ?>
                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Aktiv</span>
                            <?php else: ?>
                            <span class="badge bg-secondary"><i class="bi bi-slash-circle me-1"></i>Inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="text-muted"><?= formatDatum($u['erstellt_am']) ?></small>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="/pages/admin_users.php?action=edit&user_id=<?= $u['id'] ?>"
                                   class="btn btn-outline-warning" title="Bearbeiten">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ($u['id'] !== $myId): ?>
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="post_action" value="toggle_aktiv">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <?php if ($search): ?>
                                    <input type="hidden" name="search_redirect" value="<?= htmlspecialchars($search) ?>">
                                    <?php endif; ?>
                                    <button type="submit"
                                            class="btn btn-sm <?= $u['aktiv'] ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                            title="<?= $u['aktiv'] ? 'Deaktivieren' : 'Aktivieren' ?>"
                                            onclick="return confirm('Benutzer <?= $u['aktiv'] ? 'deaktivieren' : 'aktivieren' ?>?');">
                                        <i class="bi bi-<?= $u['aktiv'] ? 'slash-circle' : 'check-circle' ?>"></i>
                                    </button>
                                </form>
                                <?php else: ?>
                                <button class="btn btn-sm btn-outline-secondary" disabled title="Eigenes Konto">
                                    <i class="bi bi-lock"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</main>

<!-- ═══ Modal: Neuer Benutzer ═══════════════════════════════════════════════════ -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold">
                    <i class="bi bi-person-plus me-2 text-primary"></i>Neuen Benutzer anlegen
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="post_action" value="create_user">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Vorname <span class="text-danger">*</span></label>
                            <input type="text" name="vorname" class="form-control" required maxlength="100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Nachname <span class="text-danger">*</span></label>
                            <input type="text" name="nachname" class="form-control" required maxlength="100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">E-Mail <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required maxlength="255">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Passwort <span class="text-danger">*</span></label>
                            <input type="password" name="passwort" class="form-control" required minlength="8">
                            <div class="form-text">Mindestens 8 Zeichen</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Rolle</label>
                            <select name="rolle" class="form-select">
                                <option value="user">Benutzer</option>
                                <option value="kassierer">Kassierer</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Zahlungsart</label>
                            <select name="zahlungsart" class="form-select">
                                <option value="bar">Bar</option>
                                <option value="ueberweisung">Überweisung</option>
                                <option value="paypal">PayPal</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold small">Adresse</label>
                            <input type="text" name="adresse" class="form-control" maxlength="255"
                                   placeholder="Straße, PLZ Ort">
                        </div>
                        <div class="col-md-4 d-flex align-items-end pb-1">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="aktiv" id="createAktiv" checked>
                                <label class="form-check-label fw-semibold small" for="createAktiv">
                                    Konto aktiv
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-person-plus me-1"></i>Benutzer erstellen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraScripts = '<script>
// Modal öffnen wenn action=create in URL
' . ($action === 'create' ? '
document.addEventListener("DOMContentLoaded", function() {
    const modal = new bootstrap.Modal(document.getElementById("createUserModal"));
    modal.show();
});' : '') . '
</script>';
include __DIR__ . '/../includes/footer.php';
?>

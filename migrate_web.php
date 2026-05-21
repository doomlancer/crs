<?php
/**
 * Web-basierter Migrations-Runner
 * Aufruf im Browser: https://karten.die-kameruner.de/migrate_web.php
 * Nur für Admins zugänglich.
 * Nach der Ersteinrichtung: Diese Datei per FTP löschen oder umbenennen.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/includes/auth.php';

requireRole('admin');

$pdo = getDB();

// Migrations-Tracking-Tabelle sicherstellen
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `migrations` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `dateiname` VARCHAR(255) NOT NULL,
      `ausgefuehrt_am` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `dateiname` (`dateiname`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$done  = array_column($pdo->query('SELECT dateiname FROM migrations ORDER BY dateiname')->fetchAll(), 'dateiname');
$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);

$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $done, true)) continue;

        $sql = file_get_contents($file);

        // CREATE DATABASE und USE-Anweisungen entfernen (Plesk verwaltet die DB selbst)
        $sql = preg_replace('/^CREATE\s+DATABASE[^;]+;\s*/im', '', $sql);
        $sql = preg_replace('/^USE\s+[^;]+;\s*/im', '', $sql);
        $sql = preg_replace('/^SET\s+SQL_MODE[^;]+;\s*/im', '', $sql);

        // Mehrere Statements splitten und einzeln ausführen
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        $fehler = null;

        foreach ($statements as $stmt) {
            if (empty($stmt)) continue;
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                // "already exists" ignorieren
                if (!str_contains($e->getMessage(), 'already exists') && !str_contains($e->getMessage(), 'Duplicate')) {
                    $fehler = $e->getMessage();
                    break;
                }
            }
        }

        if ($fehler) {
            $messages[] = ['type' => 'danger', 'text' => "❌ <strong>{$name}</strong>: {$fehler}"];
        } else {
            $pdo->prepare('INSERT IGNORE INTO migrations (dateiname) VALUES (?)')->execute([$name]);
            $messages[] = ['type' => 'success', 'text' => "✓ <strong>{$name}</strong> erfolgreich ausgeführt."];
        }
    }

    // Status neu laden
    $done = array_column($pdo->query('SELECT dateiname FROM migrations ORDER BY dateiname')->fetchAll(), 'dateiname');

    if (empty($messages)) {
        $messages[] = ['type' => 'info', 'text' => 'Alle Migrationen waren bereits ausgeführt.'];
    }
}

$pageTitle = 'Datenbankmigrationen';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>

<main class="py-4">
<div class="container" style="max-width:760px">

    <div class="alert alert-warning d-flex align-items-start">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 me-2 mt-1"></i>
        <div>
            <strong>Sicherheitshinweis:</strong> Lösche oder benenne diese Datei um, nachdem die Einrichtung abgeschlossen ist.
            (<code>migrate_web.php</code> → löschen via Plesk-Dateimanager)
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-dark text-white fw-bold">
            <i class="bi bi-database-gear me-2"></i>Datenbankmigrationen
        </div>
        <div class="card-body">

            <?php foreach ($messages as $msg): ?>
            <div class="alert alert-<?= $msg['type'] ?>"><?= $msg['text'] ?></div>
            <?php endforeach; ?>

            <table class="table table-hover align-middle mb-4">
                <thead class="table-secondary">
                    <tr>
                        <th>Migrationsdatei</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($files as $file):
                        $name    = basename($file);
                        $isDone  = in_array($name, $done, true);
                    ?>
                    <tr>
                        <td><code><?= htmlspecialchars($name) ?></code></td>
                        <td class="text-center">
                            <?php if ($isDone): ?>
                                <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Ausgeführt</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Ausstehend</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if (empty($files)): ?>
                    <tr><td colspan="2" class="text-muted text-center">Keine Migrationsdateien gefunden.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            $pending = array_filter($files, fn($f) => !in_array(basename($f), $done, true));
            ?>

            <form method="POST" action="">
                <?= csrfField() ?>
                <button type="submit" class="btn btn-warning fw-bold"
                    <?= empty($pending) ? 'disabled' : '' ?>>
                    <i class="bi bi-play-circle me-2"></i>
                    <?= empty($pending) ? 'Alles aktuell' : count($pending) . ' ausstehende Migration(en) ausführen' ?>
                </button>
            </form>

        </div>
    </div>
</div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

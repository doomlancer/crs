<?php
/**
 * Datenbankmigrationen ausführen
 * Aufruf: php migrate.php
 * Optional: php migrate.php --status   (nur Status anzeigen)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Nur über CLI ausführbar.');
}

require_once __DIR__ . '/config.php';

$statusOnly = in_array('--status', $argv ?? [], true);
$pdo        = getDB();

// Migrations-Tracking-Tabelle anlegen
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `migrations` (
      `id` INT NOT NULL AUTO_INCREMENT,
      `dateiname` VARCHAR(255) NOT NULL,
      `ausgefuehrt_am` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `dateiname` (`dateiname`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Bereits ausgeführte Migrationen laden
$stmt     = $pdo->query('SELECT dateiname FROM migrations ORDER BY dateiname');
$done     = array_column($stmt->fetchAll(), 'dateiname');

// Alle Migration-Dateien einlesen
$files    = glob(__DIR__ . '/migrations/*.sql');
sort($files);

if ($statusOnly) {
    echo "\n=== Migrations-Status ===\n\n";
    foreach ($files as $file) {
        $name  = basename($file);
        $check = in_array($name, $done) ? '[✓]' : '[ ]';
        echo "  {$check} {$name}\n";
    }
    echo "\n";
    exit(0);
}

$ausgefuehrt = 0;

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $done)) {
        echo "  [skip] {$name}\n";
        continue;
    }

    echo "  [run]  {$name} ... ";
    $sql = file_get_contents($file);

    try {
        $pdo->exec($sql);
        $pdo->prepare('INSERT INTO migrations (dateiname) VALUES (?)')->execute([$name]);
        echo "OK\n";
        $ausgefuehrt++;
    } catch (PDOException $e) {
        echo "FEHLER: " . $e->getMessage() . "\n";
        exit(1);
    }
}

if ($ausgefuehrt === 0) {
    echo "\n  Alle Migrationen bereits ausgeführt.\n\n";
} else {
    echo "\n  {$ausgefuehrt} Migration(en) erfolgreich ausgeführt.\n\n";
}

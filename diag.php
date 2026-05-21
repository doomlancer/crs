<?php
// Temporary diagnostic — DELETE after troubleshooting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>PHP OK</h2>";
echo "PHP: " . PHP_VERSION . "<br>";
echo "SAPI: " . PHP_SAPI . "<br>";
echo "HTTPS: " . ($_SERVER['HTTPS'] ?? 'not set') . "<br>";
echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'n/a') . "<br>";
echo "Script: " . __FILE__ . "<br>";

echo "<h3>.env check</h3>";
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    echo ".env found at: $envFile<br>";
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        [$k] = explode('=', $line, 2);
        echo "Key found: " . htmlspecialchars(trim($k)) . "<br>";
    }
} else {
    echo "<strong>ERROR: .env not found at $envFile</strong><br>";
}

echo "<h3>config.php</h3>";
try {
    require_once __DIR__ . '/config.php';
    echo "config.php OK<br>";
    echo "DB_HOST: " . DB_HOST . "<br>";
    echo "DB_NAME: " . DB_NAME . "<br>";
    echo "DB_USER: " . DB_USER . "<br>";
    echo "DEBUG_MODE: " . (DEBUG_MODE ? 'true' : 'false') . "<br>";
} catch (Throwable $e) {
    echo "<strong>config.php ERROR: " . htmlspecialchars($e->getMessage()) . "</strong><br>";
}

echo "<h3>DB connection</h3>";
try {
    $pdo = getDB();
    $pdo->query('SELECT 1');
    echo "DB connection OK<br>";
} catch (Throwable $e) {
    echo "<strong>DB ERROR: " . htmlspecialchars($e->getMessage()) . "</strong><br>";
}

echo "<h3>functions.php</h3>";
try {
    require_once __DIR__ . '/functions.php';
    echo "functions.php OK<br>";
} catch (Throwable $e) {
    echo "<strong>functions.php ERROR: " . htmlspecialchars($e->getMessage()) . "</strong><br>";
}

echo "<h3>lang/de.php</h3>";
try {
    $t = require __DIR__ . '/lang/de.php';
    echo "lang/de.php OK (" . count($t) . " strings)<br>";
} catch (Throwable $e) {
    echo "<strong>lang/de.php ERROR: " . htmlspecialchars($e->getMessage()) . "</strong><br>";
}

echo "<h2>All checks done</h2>";

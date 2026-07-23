<?php
/**
 * Legt beim ersten Container-Start einen Admin-Benutzer an,
 * sofern noch kein Admin existiert. Zugangsdaten kommen aus den
 * Umgebungsvariablen ADMIN_EMAIL / ADMIN_PASSWORD (+ optional ADMIN_VORNAME/ADMIN_NACHNAME).
 * Idempotent: bei bereits vorhandenem Admin passiert nichts.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Nur CLI.\n");
}

require_once __DIR__ . '/../config.php';

$email    = $_ENV['ADMIN_EMAIL']    ?? getenv('ADMIN_EMAIL')    ?: '';
$password = $_ENV['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD') ?: '';
$vorname  = $_ENV['ADMIN_VORNAME']  ?? getenv('ADMIN_VORNAME')  ?: 'Admin';
$nachname = $_ENV['ADMIN_NACHNAME'] ?? getenv('ADMIN_NACHNAME') ?: 'Kameruner';

if ($email === '' || $password === '') {
    echo "  ADMIN_EMAIL/ADMIN_PASSWORD nicht gesetzt – überspringe.\n";
    exit(0);
}

$pdo = getDB();

// Existiert bereits irgendein Admin?
$hasAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE rolle = 'admin'")->fetchColumn();
if ($hasAdmin > 0) {
    echo "  Es existiert bereits ein Admin – überspringe.\n";
    exit(0);
}

// E-Mail schon vergeben? Dann auf Admin hochstufen statt neu anzulegen.
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$existing = $stmt->fetchColumn();

if ($existing) {
    $pdo->prepare("UPDATE users SET rolle = 'admin', aktiv = 1 WHERE id = ?")->execute([$existing]);
    echo "  Bestehender Benutzer {$email} wurde zum Admin hochgestuft.\n";
    exit(0);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo->prepare(
    "INSERT INTO users (vorname, nachname, email, passwort, zahlungsart, rolle, aktiv)
     VALUES (?, ?, ?, ?, 'bar', 'admin', 1)"
)->execute([$vorname, $nachname, $email, $hash]);

echo "  ✓ Admin-Benutzer {$email} angelegt.\n";
exit(0);

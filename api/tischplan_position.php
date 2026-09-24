<?php
/**
 * API: Position eines Tisches im freien Sitzplan-Editor speichern.
 * Koordinaten sind Prozentwerte (0-100) relativ zur Editor-Fläche, damit die
 * Darstellung unabhängig von der tatsächlichen Bildschirmgröße bleibt.
 *
 * POST: table_id, pos_x, pos_y, csrf_token
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Nur POST erlaubt.', 'data' => null], 405);
}

requireRole('kassierer', 'admin');

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Sicherheitsfehler. Bitte Seite neu laden.', 'data' => null], 403);
}

$tableId = (int)($_POST['table_id'] ?? 0);
$posX    = isset($_POST['pos_x']) && is_numeric($_POST['pos_x']) ? (float)$_POST['pos_x'] : null;
$posY    = isset($_POST['pos_y']) && is_numeric($_POST['pos_y']) ? (float)$_POST['pos_y'] : null;

if ($tableId < 1 || $posX === null || $posY === null) {
    jsonResponse(['success' => false, 'message' => 'Ungültige Daten.', 'data' => null], 400);
}

$posX = round(max(0, min(100, $posX)), 2);
$posY = round(max(0, min(100, $posY)), 2);

ensureTischplanEditorColumns();

$pdo = getDB();
try {
    $stmt = $pdo->prepare('UPDATE `tables` SET pos_x = ?, pos_y = ? WHERE id = ?');
    $stmt->execute([$posX, $posY, $tableId]);
} catch (PDOException $e) {
    error_log('Tischposition speichern fehlgeschlagen: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Speichern fehlgeschlagen.', 'data' => null], 500);
}

jsonResponse(['success' => true, 'message' => 'Gespeichert.', 'data' => ['pos_x' => $posX, 'pos_y' => $posY]]);

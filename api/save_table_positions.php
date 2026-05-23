<?php
/**
 * API: Tischpositionen speichern (AJAX)
 * POST JSON: { event_id, positions: [{table_id, x, y}, ...] }
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

requireRole('admin');

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['event_id'], $data['positions']) || !is_array($data['positions'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Daten']);
    exit;
}

$eventId = (int)$data['event_id'];
if ($eventId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Event-ID']);
    exit;
}

$pdo  = getDB();
$stmt = $pdo->prepare(
    'UPDATE `tables` SET pos_x = ?, pos_y = ? WHERE id = ? AND event_id = ?'
);

$updated = 0;
$stmtNull = $pdo->prepare(
    'UPDATE `tables` SET pos_x = NULL, pos_y = NULL WHERE id = ? AND event_id = ?'
);

foreach ($data['positions'] as $pos) {
    $tableId = (int)($pos['table_id'] ?? 0);
    if ($tableId < 1) continue;

    // null-Wert = Position löschen
    if ($pos['x'] === null || $pos['y'] === null) {
        $stmtNull->execute([$tableId, $eventId]);
        $updated += $stmtNull->rowCount();
        continue;
    }

    $x = round((float)$pos['x'], 2);
    $y = round((float)$pos['y'], 2);
    if ($x < 0 || $x > 100 || $y < 0 || $y > 100) continue;

    $stmt->execute([$x, $y, $tableId, $eventId]);
    $updated += $stmt->rowCount();
}

logAudit('UPDATE', 'tables', $eventId, "Tischpositionen gesetzt: {$updated} Tische");

echo json_encode(['ok' => true, 'updated' => $updated]);

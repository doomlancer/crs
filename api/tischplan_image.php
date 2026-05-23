<?php
/**
 * API: Tischplan-Bild sicher ausliefern
 * GET: event_id=X
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$eventId = (int)($_GET['event_id'] ?? 0);
if ($eventId < 1) { http_response_code(400); exit; }

$pdo  = getDB();
$stmt = $pdo->prepare('SELECT tischplan_bild FROM events WHERE id = ?');
$stmt->execute([$eventId]);
$row  = $stmt->fetch();

if (!$row || empty($row['tischplan_bild'])) { http_response_code(404); exit; }

$filename = basename($row['tischplan_bild']);
$path     = __DIR__ . '/../uploads/tischplan/' . $filename;

if (!file_exists($path)) { http_response_code(404); exit; }

$ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mime = match($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png'         => 'image/png',
    'webp'        => 'image/webp',
    default       => null,
};
if (!$mime) { http_response_code(403); exit; }

header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=3600');
readfile($path);

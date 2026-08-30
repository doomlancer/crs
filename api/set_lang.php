<?php
/**
 * API: Sprache wechseln
 * GET: lang=de|en&redirect=/pages/...
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$lang = $_GET['lang'] ?? 'de';
setLang($lang);

// Nur seiteninterne Pfade erlauben (Open-Redirect-Schutz)
redirect(safeRedirectTarget($_GET['redirect'] ?? null, '/pages/events.php'));

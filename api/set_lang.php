<?php
/**
 * API: Sprache wechseln
 * GET: lang=de|en&redirect=/pages/...
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$lang = $_GET['lang'] ?? 'de';
setLang($lang);

$redirect = $_GET['redirect'] ?? '/pages/events.php';
// Nur relative Pfade erlauben (Open-Redirect-Schutz)
if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
    $redirect = '/pages/events.php';
}
redirect($redirect);

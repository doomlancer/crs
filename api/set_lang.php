<?php
require_once __DIR__ . '/../config.php';

$lang = $_POST['lang'] ?? $_GET['lang'] ?? 'de';
if (!in_array($lang, ['de', 'en'], true)) {
    $lang = 'de';
}
$_SESSION['lang'] = $lang;

$redirect = $_SERVER['HTTP_REFERER'] ?? '/index.php';
// Prevent open redirect
if (!str_starts_with($redirect, '/') && !str_starts_with($redirect, APP_URL)) {
    $redirect = '/index.php';
}
header('Location: ' . $redirect);
exit;

<?php
/**
 * Dynamisches Theme-CSS – überschreibt CSS-Variablen aus style.css.
 * Wird als same-origin Stylesheet geladen (kein CSP-Problem).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$s = getAllSettings();

// HTTP-Caching via ETag (wird bei Einstellungsänderung durch theme_version invalidiert)
$etag = '"' . md5($s['theme_version'] ?? '1') . '"';
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('ETag: ' . $etag);

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

$fontMap = [
    'inter'   => 'Inter',
    'lato'    => 'Lato',
    'poppins' => 'Poppins',
    'roboto'  => 'Roboto',
    'oswald'  => 'Oswald',
];
$fontKey  = $s['font_family'] ?? 'inter';
$fontName = $fontMap[$fontKey] ?? 'Inter';

$primary      = $s['color_primary']       ?? '#cf2e2e';
$primaryDark  = $s['color_primary_dark']  ?? '#a82424';
$primaryLight = $s['color_primary_light'] ?? '#e84444';
$dark         = $s['color_dark']          ?? '#1a1a1a';
$dark2        = $s['color_dark2']         ?? '#2d2d2d';
$bg           = $s['color_bg']            ?? '#f5f5f5';

// Sanitize: only allow valid hex colors
$hexRe = '/^#[0-9a-fA-F]{6}$/';
$primary      = preg_match($hexRe, $primary)      ? $primary      : '#cf2e2e';
$primaryDark  = preg_match($hexRe, $primaryDark)  ? $primaryDark  : '#a82424';
$primaryLight = preg_match($hexRe, $primaryLight) ? $primaryLight : '#e84444';
$dark         = preg_match($hexRe, $dark)         ? $dark         : '#1a1a1a';
$dark2        = preg_match($hexRe, $dark2)        ? $dark2        : '#2d2d2d';
$bg           = preg_match($hexRe, $bg)           ? $bg           : '#f5f5f5';
?>
:root {
    --club-red:   <?= $primary ?>;
    --club-red-d: <?= $primaryDark ?>;
    --club-red-l: <?= $primaryLight ?>;
    --club-dark:  <?= $dark ?>;
    --club-dark2: <?= $dark2 ?>;
    --club-light: <?= $bg ?>;
    --bs-font-sans-serif: '<?= htmlspecialchars($fontName) ?>', system-ui, -apple-system, sans-serif;
}
body { background-color: <?= $bg ?>; }

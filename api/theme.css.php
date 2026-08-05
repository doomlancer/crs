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

// System-Schriftstapel statt Google Fonts: keine externe Abhängigkeit,
// funktioniert offline und ohne CSP-Ausnahme.
$fontMap = [
    'system'    => "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
    'humanist'  => "'Segoe UI', Candara, 'Trebuchet MS', Verdana, sans-serif",
    'geometric' => "Futura, 'Century Gothic', 'Avenir Next', Avenir, 'Trebuchet MS', sans-serif",
    'classic'   => "Georgia, 'Times New Roman', 'Iowan Old Style', serif",
    'condensed' => "'Arial Narrow', 'Roboto Condensed', 'Liberation Sans Narrow', Impact, sans-serif",
    'mono'      => "'SF Mono', 'Cascadia Mono', Menlo, Consolas, 'Liberation Mono', monospace",
];
$fontKey   = $s['font_family'] ?? 'system';
$fontStack = $fontMap[$fontKey] ?? $fontMap['system'];

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
<?php
// Hilfsfunktion: "#rrggbb" -> "r, g, b" (für Bootstraps *-rgb Variablen)
function hexToRgb(string $hex): string {
    return implode(', ', [
        hexdec(substr($hex, 1, 2)),
        hexdec(substr($hex, 3, 2)),
        hexdec(substr($hex, 5, 2)),
    ]);
}
?>
:root {
    /* Projekt-eigene Tokens */
    --club-red:   <?= $primary ?>;
    --club-red-d: <?= $primaryDark ?>;
    --club-red-l: <?= $primaryLight ?>;
    --club-dark:  <?= $dark ?>;
    --club-dark2: <?= $dark2 ?>;
    --club-light: <?= $bg ?>;

    /* Bootstrap-Variablen mitziehen – sonst bliebe z.B. jede .btn-primary,
       jeder Modal-Header und jede KPI-Karte im Bootstrap-Standardblau. */
    --bs-primary:     <?= $primary ?>;
    --bs-primary-rgb: <?= hexToRgb($primary) ?>;
    --bs-link-color:  <?= $primary ?>;
    --bs-link-color-rgb: <?= hexToRgb($primary) ?>;
    --bs-link-hover-color: <?= $primaryDark ?>;
    --bs-dark:        <?= $dark ?>;
    --bs-dark-rgb:    <?= hexToRgb($dark) ?>;
    --bs-body-bg:     <?= $bg ?>;
    --bs-font-sans-serif: <?= $fontStack ?>;
}

/* Bootstrap-Komponenten, die feste Farbwerte statt Variablen nutzen */
.btn-primary {
    --bs-btn-bg: <?= $primary ?>;
    --bs-btn-border-color: <?= $primary ?>;
    --bs-btn-hover-bg: <?= $primaryDark ?>;
    --bs-btn-hover-border-color: <?= $primaryDark ?>;
    --bs-btn-active-bg: <?= $primaryDark ?>;
    --bs-btn-active-border-color: <?= $primaryDark ?>;
    --bs-btn-disabled-bg: <?= $primaryLight ?>;
    --bs-btn-disabled-border-color: <?= $primaryLight ?>;
}
.btn-outline-primary {
    --bs-btn-color: <?= $primary ?>;
    --bs-btn-border-color: <?= $primary ?>;
    --bs-btn-hover-bg: <?= $primary ?>;
    --bs-btn-hover-border-color: <?= $primary ?>;
    --bs-btn-active-bg: <?= $primary ?>;
    --bs-btn-active-border-color: <?= $primary ?>;
}
.bg-primary  { background-color: <?= $primary ?> !important; }
.text-primary{ color: <?= $primary ?> !important; }
.border-primary { border-color: <?= $primary ?> !important; }
.bg-dark     { background-color: <?= $dark ?> !important; }
.navbar.bg-dark, footer.bg-dark { background-color: <?= $dark ?> !important; }
.form-control:focus, .form-select:focus {
    border-color: <?= $primaryLight ?>;
    box-shadow: 0 0 0 .25rem rgba(<?= hexToRgb($primary) ?>, .25);
}
body { background-color: <?= $bg ?>; font-family: <?= $fontStack ?>; }

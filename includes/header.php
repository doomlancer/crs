<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars(APP_NAME) ?> - Tischreservierungen online">
    <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?> | <?= htmlspecialchars(APP_NAME) ?></title>
    <!-- Bootstrap + Icons: lokal ausgeliefert, damit die App auch ohne
         Internetverbindung (Veranstaltungsort!) vollständig funktioniert. -->
    <link rel="stylesheet" href="/assets/vendor/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/vendor/css/bootstrap-icons.min.css">
    <?php if (file_exists(__DIR__ . '/../dist/css/main.css')): ?>
    <link rel="stylesheet" href="/dist/css/main.css">
    <?php else: ?>
    <link rel="stylesheet" href="/css/style.css">
    <?php endif; ?>
    <link rel="stylesheet" href="/api/theme.css.php?v=<?= htmlspecialchars(getSetting('theme_version', '1')) ?>">
    <?php
    $favicon = getSetting('app_favicon', '');
    if ($favicon && file_exists(UPLOAD_DIR . $favicon)):
    ?>
    <link rel="icon" href="/uploads/<?= htmlspecialchars($favicon) ?>">
    <?php endif; ?>
    <?php // Schriftart kommt aus theme.css.php (System-Schriften, kein CDN nötig) ?>
    <?php if (!empty($extraHead)) echo withCspNonce($extraHead); ?>
</head>
<body class="<?= htmlspecialchars($bodyClass ?? '') ?>">

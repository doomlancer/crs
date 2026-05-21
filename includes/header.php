<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars(APP_NAME) ?> - Tischreservierungen online">
    <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?> | <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <?php if (file_exists(__DIR__ . '/../dist/css/main.css')): ?>
    <link rel="stylesheet" href="/dist/css/main.css">
    <?php else: ?>
    <link rel="stylesheet" href="/css/style.css">
    <?php endif; ?>
    <?php if (!empty($extraHead)) echo $extraHead; ?>
</head>
<body class="<?= htmlspecialchars($bodyClass ?? '') ?>">

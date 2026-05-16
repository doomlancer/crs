<?php
/**
 * API: Gästeliste exportieren (CSV oder PDF)
 * GET: event_id, format (csv|pdf)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('kassierer', 'admin');

$eventId = (int)($_GET['event_id'] ?? 0);
$format  = strtolower($_GET['format'] ?? 'csv');

if (!$eventId || !in_array($format, ['csv', 'pdf'], true)) {
    http_response_code(400);
    die('Ungültige Parameter.');
}

$pdo = getDB();

// Event laden
$stmtEvent = $pdo->prepare('SELECT id, name, datum FROM events WHERE id = ?');
$stmtEvent->execute([$eventId]);
$event = $stmtEvent->fetch();

if (!$event) {
    http_response_code(404);
    die('Event nicht gefunden.');
}

// Gästeliste laden
$stmt = $pdo->prepare(
    'SELECT r.buchungsnummer, r.status AS reservierungsstatus, r.erstellt_am,
            u.vorname, u.nachname, u.email, u.adresse,
            t.tischnummer, s.sitzplatznummer,
            p.zahlungsart, p.status AS zahlungsstatus, p.betrag
     FROM reservations r
     JOIN users u    ON r.user_id      = u.id
     JOIN seats s    ON r.seat_id      = s.id
     JOIN tables t   ON s.table_id     = t.id
     LEFT JOIN payments p ON p.reservation_id = r.id
     WHERE r.event_id = ?
     ORDER BY t.tischnummer, s.sitzplatznummer'
);
$stmt->execute([$eventId]);
$gaeste = $stmt->fetchAll();

$dateiname = 'gaesteliste_' . date('Y-m-d', strtotime($event['datum'])) . '_' . $eventId;

logAudit('EXPORT', 'reservations', $eventId, "Gästeliste-Export ({$format}) für Event: {$event['name']}");

// ========================
// CSV-Export
// ========================
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $dateiname . '.csv"');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'w');

    // UTF-8 BOM für Excel
    fputs($output, "\xEF\xBB\xBF");

    // Header-Zeile
    fputcsv($output, [
        'Buchungsnummer',
        'Vorname',
        'Nachname',
        'E-Mail',
        'Adresse',
        'Tisch',
        'Sitzplatz',
        'Zahlungsart',
        'Zahlungsstatus',
        'Betrag (EUR)',
        'Buchungsstatus',
        'Reserviert am',
    ], ';');

    foreach ($gaeste as $gast) {
        fputcsv($output, [
            $gast['buchungsnummer'],
            $gast['vorname'],
            $gast['nachname'],
            $gast['email'],
            $gast['adresse'] ?? '',
            $gast['tischnummer'],
            $gast['sitzplatznummer'],
            zahlungsartLabel($gast['zahlungsart'] ?? ''),
            match($gast['zahlungsstatus'] ?? 'offen') {
                'bezahlt'   => 'Bezahlt',
                'storniert' => 'Storniert',
                default     => 'Offen',
            },
            number_format((float)($gast['betrag'] ?? 0), 2, ',', '.'),
            match($gast['reservierungsstatus']) {
                'eingecheckt'  => 'Eingecheckt',
                'abgerechnet'  => 'Abgerechnet',
                default        => 'Geplant',
            },
            date('d.m.Y H:i', strtotime($gast['erstellt_am'])),
        ], ';');
    }

    fclose($output);
    exit;
}

// ========================
// PDF-Export (HTML-basiert, via Browser-Druck)
// ========================
if ($format === 'pdf') {
    // Einfaches druckbares HTML (kein externer PDF-Generator benötigt)
    $gesamtUmsatz  = array_sum(array_column($gaeste, 'betrag'));
    $eingecheckt   = count(array_filter($gaeste, fn($g) => $g['reservierungsstatus'] === 'eingecheckt'));
    $bezahlt       = count(array_filter($gaeste, fn($g) => ($g['zahlungsstatus'] ?? '') === 'bezahlt'));
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Gästeliste – <?= htmlspecialchars($event['name']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 11px; color: #333; padding: 20px; }
        h1 { font-size: 18px; margin-bottom: 4px; color: #1a1a2e; }
        .subtitle { color: #666; margin-bottom: 15px; font-size: 12px; }
        .stats { display: flex; gap: 20px; margin-bottom: 15px; padding: 10px; background: #f8f9fa; border-radius: 4px; }
        .stat { text-align: center; }
        .stat strong { display: block; font-size: 18px; color: #1a1a2e; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #1a1a2e; color: white; padding: 6px 8px; text-align: left; font-size: 10px; }
        td { padding: 5px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
        tr:nth-child(even) { background: #f8f9fa; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 9px; font-weight: bold; }
        .badge-success { background: #22c55e; color: white; }
        .badge-warning { background: #eab308; color: black; }
        .badge-secondary { background: #6b7280; color: white; }
        .badge-danger { background: #ef4444; color: white; }
        .footer { margin-top: 20px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 10px; color: #999; display: flex; justify-content: space-between; }
        @media print {
            body { padding: 10px; }
            button { display: none; }
        }
    </style>
</head>
<body>
    <div style="text-align:right; margin-bottom:10px;">
        <button onclick="window.print()" style="padding:8px 16px; background:#f59e0b; border:none; border-radius:4px; font-weight:bold; cursor:pointer;">
            🖨️ Drucken / Als PDF speichern
        </button>
    </div>

    <h1><?= htmlspecialchars(APP_NAME) ?> – Gästeliste</h1>
    <div class="subtitle">
        <?= htmlspecialchars($event['name']) ?> |
        <?= formatDatum($event['datum']) ?> |
        Erstellt: <?= date('d.m.Y H:i') ?> Uhr
    </div>

    <div class="stats">
        <div class="stat">
            <strong><?= count($gaeste) ?></strong>
            Reservierungen
        </div>
        <div class="stat">
            <strong><?= $eingecheckt ?></strong>
            Eingecheckt
        </div>
        <div class="stat">
            <strong><?= $bezahlt ?></strong>
            Bezahlt
        </div>
        <div class="stat">
            <strong><?= formatBetrag($gesamtUmsatz) ?></strong>
            Gesamtumsatz
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Buchungsnr.</th>
                <th>Name</th>
                <th>E-Mail</th>
                <th>Tisch</th>
                <th>Platz</th>
                <th>Zahlung</th>
                <th>Betrag</th>
                <th>Check-in</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($gaeste as $i => $gast): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= htmlspecialchars($gast['buchungsnummer']) ?></strong></td>
                <td><?= htmlspecialchars($gast['vorname'] . ' ' . $gast['nachname']) ?></td>
                <td><?= htmlspecialchars($gast['email']) ?></td>
                <td style="text-align:center;"><?= $gast['tischnummer'] ?></td>
                <td style="text-align:center;"><?= $gast['sitzplatznummer'] ?></td>
                <td>
                    <?= htmlspecialchars(zahlungsartLabel($gast['zahlungsart'] ?? '')) ?>
                    <span class="badge <?= ($gast['zahlungsstatus'] ?? '') === 'bezahlt' ? 'badge-success' : 'badge-warning' ?>">
                        <?= ($gast['zahlungsstatus'] ?? '') === 'bezahlt' ? '✓ Bezahlt' : 'Offen' ?>
                    </span>
                </td>
                <td><?= formatBetrag((float)($gast['betrag'] ?? 0)) ?></td>
                <td>
                    <?php if ($gast['reservierungsstatus'] === 'eingecheckt'): ?>
                        <span class="badge badge-success">✓ Eingecheckt</span>
                    <?php elseif ($gast['reservierungsstatus'] === 'abgerechnet'): ?>
                        <span class="badge badge-secondary">Abgerechnet</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Geplant</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer">
        <span><?= htmlspecialchars(APP_NAME) ?> – Vertraulich</span>
        <span>Erstellt von: <?= htmlspecialchars($_SESSION['vorname'] . ' ' . $_SESSION['nachname']) ?></span>
    </div>
</body>
</html>
    <?php
    exit;
}

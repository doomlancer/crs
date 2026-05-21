<?php
/**
 * API: Sitzplatz reservieren oder stornieren
 * POST: seat_ids (kommagetrennte IDs), event_id, csrf_token, [action=cancel]
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Nur POST erlauben
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('error', 'Ungültige Anfrage.');
    redirect('/pages/tischplan.php');
}

requireLogin();

// CSRF prüfen
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Sicherheitsfehler. Bitte Seite neu laden und erneut versuchen.');
    redirect('/pages/tischplan.php');
}

$pdo     = getDB();
$userId  = (int)$_SESSION['user_id'];
$eventId = (int)($_POST['event_id'] ?? 0);
$action  = $_POST['action'] ?? 'reserve';

// Stornierung
if ($action === 'cancel') {
    $reservationId = (int)($_POST['reservation_id'] ?? 0);
    $seatId        = (int)($_POST['seat_ids'] ?? 0);

    try {
        $pdo->beginTransaction();

        if ($reservationId) {
            // Über Reservation-ID stornieren
            $stmt = $pdo->prepare(
                'SELECT r.id, r.seat_id, r.event_id, r.buchungsnummer FROM reservations r
                 WHERE r.id = ? AND r.user_id = ? AND r.status = "geplant"'
            );
            $stmt->execute([$reservationId, $userId]);
        } else {
            // Über Seat-ID stornieren
            $stmt = $pdo->prepare(
                'SELECT r.id, r.seat_id, r.event_id, r.buchungsnummer FROM reservations r
                 WHERE r.seat_id = ? AND r.user_id = ? AND r.status = "geplant"'
            );
            $stmt->execute([$seatId, $userId]);
        }

        $reservation = $stmt->fetch();
        if (!$reservation) {
            $pdo->rollBack();
            setFlash('error', 'Reservierung nicht gefunden oder kann nicht storniert werden.');
            redirect('/pages/meine_reservierungen.php');
        }

        // Reservierung stornieren
        $pdo->prepare('UPDATE reservations SET status = "abgerechnet" WHERE id = ?')
            ->execute([$reservation['id']]);
        // Sitzplatz freigeben
        $pdo->prepare('UPDATE seats SET status = "verfuegbar" WHERE id = ?')
            ->execute([$reservation['seat_id']]);
        // Zahlung stornieren
        $pdo->prepare('UPDATE payments SET status = "storniert" WHERE reservation_id = ?')
            ->execute([$reservation['id']]);

        logAudit('STORNIERUNG', 'reservations', $reservation['id'], "Stornierung durch Benutzer");

        // Stornierungsbestätigung per E-Mail
        $stmtUserInfo = $pdo->prepare('SELECT email, vorname FROM users WHERE id = ?');
        $stmtUserInfo->execute([$userId]);
        $ui = $stmtUserInfo->fetch();
        $stmtEvtInfo  = $pdo->prepare('SELECT name FROM events WHERE id = ?');
        $stmtEvtInfo->execute([$reservation['event_id']]);
        $ei = $stmtEvtInfo->fetch();
        if ($ui && $ei) {
            sendStornierungsbestaetigung($ui['email'], $ui['vorname'], $reservation['buchungsnummer'] ?? '', $ei['name']);
        }

        // Warteliste: ältesten Eintrag für dieses Event benachrichtigen
        $stmtWl = $pdo->prepare(
            'SELECT w.user_id, u.email, u.vorname FROM waitlist w
             JOIN users u ON w.user_id = u.id
             WHERE w.event_id = ? ORDER BY w.erstellt_am ASC LIMIT 1'
        );
        $stmtWl->execute([$reservation['event_id']]);
        $nextUser = $stmtWl->fetch();
        if ($nextUser) {
            $stmtEvt = $pdo->prepare('SELECT name, datum FROM events WHERE id = ?');
            $stmtEvt->execute([$reservation['event_id']]);
            $evtData = $stmtEvt->fetch();
            $tischUrl = APP_URL . '/pages/tischplan.php?event_id=' . $reservation['event_id'];
            sendWaitlistNotification(
                $nextUser['email'],
                $nextUser['vorname'],
                $evtData['name'] ?? '',
                $tischUrl
            );
        }

        $pdo->commit();

        setFlash('success', 'Reservierung erfolgreich storniert.');
        redirect('/pages/meine_reservierungen.php');
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Stornierung Fehler: ' . $e->getMessage());
        setFlash('error', 'Fehler beim Stornieren. Bitte erneut versuchen.');
        redirect('/pages/meine_reservierungen.php');
    }
}

// Reservierung erstellen
if (!$eventId) {
    setFlash('error', 'Kein Event ausgewählt.');
    redirect('/pages/tischplan.php');
}

$seatIdsRaw = trim($_POST['seat_ids'] ?? '');
if (empty($seatIdsRaw)) {
    setFlash('error', 'Bitte wählen Sie mindestens einen Sitzplatz aus.');
    redirect('/pages/tischplan.php?event_id=' . $eventId);
}

// Seat-IDs parsen und validieren (nur positive Integer)
$seatIds = array_filter(
    array_map('intval', explode(',', $seatIdsRaw)),
    fn($id) => $id > 0
);
$seatIds = array_unique($seatIds);

if (empty($seatIds) || count($seatIds) > 10) {
    setFlash('error', 'Ungültige Sitzplatz-Auswahl (max. 10 Plätze pro Buchung).');
    redirect('/pages/tischplan.php?event_id=' . $eventId);
}

try {
    $pdo->beginTransaction();

    // Event prüfen (existiert, ist aktiv)
    $stmtEvent = $pdo->prepare("SELECT id, status FROM events WHERE id = ? AND status = 'aktiv'");
    $stmtEvent->execute([$eventId]);
    if (!$stmtEvent->fetch()) {
        $pdo->rollBack();
        setFlash('error', 'Dieses Event ist nicht mehr verfügbar.');
        redirect('/pages/events.php');
    }

    // Alle Sitze validieren – müssen zum Event gehören und verfügbar sein
    $placeholders = implode(',', array_fill(0, count($seatIds), '?'));
    $stmtSeats = $pdo->prepare(
        "SELECT s.id, s.status, t.event_id
         FROM seats s
         JOIN tables t ON s.table_id = t.id
         WHERE s.id IN ({$placeholders})
         FOR UPDATE"
    );
    $stmtSeats->execute($seatIds);
    $seats = $stmtSeats->fetchAll();

    if (count($seats) !== count($seatIds)) {
        $pdo->rollBack();
        setFlash('error', 'Einige Sitzplätze konnten nicht gefunden werden.');
        redirect('/pages/tischplan.php?event_id=' . $eventId);
    }

    foreach ($seats as $seat) {
        if ((int)$seat['event_id'] !== $eventId) {
            $pdo->rollBack();
            setFlash('error', 'Sitzplatz gehört nicht zu diesem Event.');
            redirect('/pages/tischplan.php?event_id=' . $eventId);
        }
        if ($seat['status'] !== 'verfuegbar') {
            $pdo->rollBack();
            setFlash('error', 'Ein oder mehrere Sitzplätze sind nicht mehr verfügbar. Bitte neu auswählen.');
            redirect('/pages/tischplan.php?event_id=' . $eventId);
        }
    }

    // Zahlungsart des Benutzers holen
    $stmtUser = $pdo->prepare('SELECT zahlungsart FROM users WHERE id = ?');
    $stmtUser->execute([$userId]);
    $userZahlungsart = $stmtUser->fetchColumn() ?: 'bar';

    // Für jeden Sitz eine Reservierung anlegen
    $stmtRes = $pdo->prepare(
        'INSERT INTO reservations (user_id, event_id, seat_id, buchungsnummer, preis)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmtPay = $pdo->prepare(
        'INSERT INTO payments (reservation_id, zahlungsart, betrag, status)
         VALUES (?, ?, ?, ?)'
    );
    $stmtSeat = $pdo->prepare("UPDATE seats SET status = 'reserviert' WHERE id = ?");

    $buchungsnummern = [];
    foreach ($seatIds as $seatId) {
        $buchungsnummer = generateBuchungsnummer();
        $stmtRes->execute([$userId, $eventId, $seatId, $buchungsnummer, TICKET_PREIS]);
        $reservationId = (int)$pdo->lastInsertId();

        $payStatus = $userZahlungsart === 'bar' ? 'offen' : 'offen';
        $stmtPay->execute([$reservationId, $userZahlungsart, TICKET_PREIS, $payStatus]);
        $stmtSeat->execute([$seatId]);

        $buchungsnummern[] = $buchungsnummer;
        logAudit('RESERVIERUNG', 'reservations', $reservationId,
            "Buchung: {$buchungsnummer}, Event: {$eventId}, Sitz: {$seatId}");
    }

    $pdo->commit();

    // Reservierungsbestätigung per E-Mail
    $stmtUserInfo = $pdo->prepare('SELECT email, vorname FROM users WHERE id = ?');
    $stmtUserInfo->execute([$userId]);
    $userInfo = $stmtUserInfo->fetch();

    $stmtEvtInfo = $pdo->prepare('SELECT name, datum FROM events WHERE id = ?');
    $stmtEvtInfo->execute([$eventId]);
    $evtInfo = $stmtEvtInfo->fetch();

    if ($userInfo && $evtInfo) {
        // Sitzplatzdaten für Bestätigungs-E-Mail laden
        $buchungenFuerMail = [];
        $stmtBuchDaten = $pdo->prepare(
            'SELECT r.buchungsnummer, r.preis, t.tischnummer, s.sitzplatznummer
             FROM reservations r
             JOIN seats s ON r.seat_id = s.id
             JOIN tables t ON s.table_id = t.id
             WHERE r.buchungsnummer = ?'
        );
        foreach ($buchungsnummern as $bn) {
            $stmtBuchDaten->execute([$bn]);
            $row = $stmtBuchDaten->fetch();
            if ($row) $buchungenFuerMail[] = $row;
        }
        sendReservierungsbestaetigung(
            $userInfo['email'],
            $userInfo['vorname'],
            $buchungenFuerMail,
            $evtInfo['name'],
            $evtInfo['datum']
        );
    }

    $anzahl = count($buchungsnummern);
    $nummernText = implode(', ', $buchungsnummern);
    setFlash('success', "✓ {$anzahl} Platz/Plätze erfolgreich reserviert! Ihre Buchungsnummer(n): {$nummernText}");
    redirect('/pages/meine_reservierungen.php');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Reservierung Fehler: ' . $e->getMessage());
    setFlash('error', 'Technischer Fehler bei der Reservierung. Bitte erneut versuchen.');
    redirect('/pages/tischplan.php?event_id=' . $eventId);
}

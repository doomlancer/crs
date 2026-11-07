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

// ════════════════════════════════════════════════════════════════════
// Freie-Ticket-Buchung (kein Sitzplan)
// ════════════════════════════════════════════════════════════════════
if ($action === 'reserve_free_ticket') {
    if (!$eventId) {
        setFlash('error', 'Kein Event ausgewählt.');
        redirect('/pages/events.php');
    }

    $anzahl = max(1, min(10, (int)($_POST['anzahl'] ?? 1)));

    try {
        $pdo->beginTransaction();

        // Event laden und prüfen
        // FOR UPDATE sperrt die Event-Zeile für die Dauer der Transaktion.
        // Die Kontingentprüfung weiter unten ist sonst wirkungslos: Ein reiner
        // SELECT COUNT(*) liest unter InnoDB den Snapshot vom Transaktionsbeginn,
        // sodass gleichzeitige Buchungen alle denselben Stand sehen, alle die
        // Prüfung bestehen und das Kontingent gemeinsam überschreiten. Anders als
        // beim Sitzplan gibt es hier kein Netz darunter – Freitickets haben
        // seat_id NULL und fallen damit nicht unter den Eindeutigkeitsschlüssel.
        if (hasRole('admin')) {
            $stmtEv = $pdo->prepare("SELECT id, name, datum, event_typ, max_gaeste, preis, status FROM events WHERE id = ? AND status != 'abgerechnet' FOR UPDATE");
        } else {
            $stmtEv = $pdo->prepare("SELECT id, name, datum, event_typ, max_gaeste, preis, status FROM events WHERE id = ? AND status = 'aktiv' FOR UPDATE");
        }
        $stmtEv->execute([$eventId]);
        $event = $stmtEv->fetch();

        if (!$event || $event['event_typ'] !== 'freie_tickets') {
            $pdo->rollBack();
            setFlash('error', 'Dieses Event ist nicht als Freiticket-Veranstaltung verfügbar.');
            redirect('/pages/events.php');
        }

        // Kontingent prüfen (wenn max_gaeste gesetzt)
        if ($event['max_gaeste'] !== null) {
            $stmtCount = $pdo->prepare(
                "SELECT COUNT(*) FROM reservations WHERE event_id = ? AND status NOT IN ('abgerechnet','storniert')"
            );
            $stmtCount->execute([$eventId]);
            $verkauft = (int)$stmtCount->fetchColumn();
            if ($verkauft + $anzahl > $event['max_gaeste']) {
                $pdo->rollBack();
                $rest = max(0, $event['max_gaeste'] - $verkauft);
                setFlash('error', "Leider sind nur noch {$rest} Ticket(s) verfügbar.");
                redirect('/pages/tischplan.php?event_id=' . $eventId);
            }
        }

        $eventPreis = (float)($event['preis'] ?: TICKET_PREIS);

        // Zahlungsart des Benutzers
        $stmtUser = $pdo->prepare('SELECT zahlungsart FROM users WHERE id = ?');
        $stmtUser->execute([$userId]);
        $zahlungsart = $stmtUser->fetchColumn() ?: 'bar';

        $stmtRes = $pdo->prepare(
            'INSERT INTO reservations (user_id, event_id, seat_id, buchungsnummer, preis) VALUES (?,?,NULL,?,?)'
        );
        $stmtPay = $pdo->prepare(
            'INSERT INTO payments (reservation_id, zahlungsart, betrag, status) VALUES (?,?,?,"offen")'
        );

        $buchungsnummern = [];
        for ($i = 0; $i < $anzahl; $i++) {
            $bn = generateBuchungsnummer();
            $stmtRes->execute([$userId, $eventId, $bn, $eventPreis]);
            $rid = (int)$pdo->lastInsertId();
            $stmtPay->execute([$rid, $zahlungsart, $eventPreis]);
            $buchungsnummern[] = $bn;
            logAudit('RESERVIERUNG', 'reservations', $rid, "Freiticket: {$bn}, Event: {$eventId}");
        }

        $pdo->commit();

        // Wer gebucht hat, wartet nicht mehr.
        $pdo->prepare('DELETE FROM waitlist WHERE user_id = ? AND event_id = ?')->execute([$userId, $eventId]);

        // Bestätigungs-E-Mail mit einem QR-Code je Person
        sendReservierungsbestaetigung($userId, $eventId, $buchungsnummern);

        setFlash('success', '✓ ' . $anzahl . ' Ticket(s) reserviert. Ihre Tickets mit QR-Code finden Sie unten und per E-Mail.');
        redirect('/pages/meine_reservierungen.php');

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Freiticket-Reservierung Fehler: ' . $e->getMessage());
        setFlash('error', 'Technischer Fehler. Bitte erneut versuchen.');
        redirect('/pages/tischplan.php?event_id=' . $eventId);
    }
}

// Stornierung
if ($action === 'cancel') {
    $reservationId = (int)($_POST['reservation_id'] ?? 0);
    $seatId        = (int)($_POST['seat_ids'] ?? 0);

    try {
        $pdo->beginTransaction();

        $isAdmin = hasRole('admin');

        if ($reservationId) {
            // Über Reservation-ID stornieren
            if ($isAdmin) {
                $stmt = $pdo->prepare(
                    'SELECT r.id, r.user_id, r.seat_id, r.event_id, r.buchungsnummer FROM reservations r
                     WHERE r.id = ? AND r.status = "geplant"'
                );
                $stmt->execute([$reservationId]);
            } else {
                $stmt = $pdo->prepare(
                    'SELECT r.id, r.user_id, r.seat_id, r.event_id, r.buchungsnummer FROM reservations r
                     WHERE r.id = ? AND r.user_id = ? AND r.status = "geplant"'
                );
                $stmt->execute([$reservationId, $userId]);
            }
        } else {
            // Über Seat-ID stornieren
            if ($isAdmin) {
                $stmt = $pdo->prepare(
                    'SELECT r.id, r.user_id, r.seat_id, r.event_id, r.buchungsnummer FROM reservations r
                     WHERE r.seat_id = ? AND r.status = "geplant"'
                );
                $stmt->execute([$seatId]);
            } else {
                $stmt = $pdo->prepare(
                    'SELECT r.id, r.user_id, r.seat_id, r.event_id, r.buchungsnummer FROM reservations r
                     WHERE r.seat_id = ? AND r.user_id = ? AND r.status = "geplant"'
                );
                $stmt->execute([$seatId, $userId]);
            }
        }

        $reservation = $stmt->fetch();
        if (!$reservation) {
            $pdo->rollBack();
            setFlash('error', 'Reservierung nicht gefunden oder kann nicht storniert werden.');
            redirect('/pages/meine_reservierungen.php');
        }

        // Reservierung stornieren
        $pdo->prepare('UPDATE reservations SET status = "storniert" WHERE id = ?')
            ->execute([$reservation['id']]);
        // Sitzplatz freigeben
        $pdo->prepare('UPDATE seats SET status = "verfuegbar" WHERE id = ?')
            ->execute([$reservation['seat_id']]);
        // Zahlung stornieren
        $pdo->prepare('UPDATE payments SET status = "storniert" WHERE reservation_id = ?')
            ->execute([$reservation['id']]);

        logAudit('STORNIERUNG', 'reservations', $reservation['id'], "Stornierung durch Benutzer");

        // Stornierungsbestätigung per E-Mail.
        // Empfänger ist der Gast, dem die Reservierung gehört – nicht der
        // Angemeldete. Storniert ein Admin eine fremde Buchung, ging die
        // Bestätigung sonst an den Admin, und der Gast erfuhr nichts davon.
        $stmtUserInfo = $pdo->prepare('SELECT email, vorname FROM users WHERE id = ?');
        $stmtUserInfo->execute([(int)($reservation['user_id'] ?? $userId)]);
        $ui = $stmtUserInfo->fetch();
        $stmtEvtInfo  = $pdo->prepare('SELECT name FROM events WHERE id = ?');
        $stmtEvtInfo->execute([$reservation['event_id']]);
        $ei = $stmtEvtInfo->fetch();
        if ($ui && $ei) {
            sendStornierungsbestaetigung($ui['email'], $ui['vorname'], $reservation['buchungsnummer'] ?? '', $ei['name']);
        }

        $pdo->commit();

        // Ein Platz ist frei: nächste Person auf der Warteliste benachrichtigen
        benachrichtigeWarteliste((int)$reservation['event_id']);

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

// Event-spezifischen Preis laden (mit Fallback)
try {
    $stmtP = $pdo->prepare('SELECT COALESCE(preis, ?) FROM events WHERE id = ?');
    $stmtP->execute([(float)TICKET_PREIS, $eventId]);
    $eventPreis = (float)($stmtP->fetchColumn() ?: TICKET_PREIS);
} catch (PDOException $e) {
    $eventPreis = (float)TICKET_PREIS;
}

// seat_ids[] Array (Checkbox-Formular) oder kommagetrennt (Legacy)
$rawIds = $_POST['seat_ids'] ?? '';
if (is_array($rawIds)) {
    $seatIds = array_values(array_filter(array_map('intval', $rawIds), fn($id) => $id > 0));
} else {
    $seatIdsRaw = trim($rawIds);
    if (empty($seatIdsRaw)) {
        setFlash('error', 'Bitte wählen Sie mindestens einen Sitzplatz aus.');
        redirect('/pages/tischplan.php?event_id=' . $eventId);
    }
    $seatIds = array_values(array_filter(
        array_map('intval', explode(',', $seatIdsRaw)),
        fn($id) => $id > 0
    ));
}

if (empty($seatIds)) {
    setFlash('error', 'Bitte wählen Sie mindestens einen Sitzplatz aus.');
    redirect('/pages/tischplan.php?event_id=' . $eventId);
}

$seatIds = array_unique($seatIds);

if (count($seatIds) > 10) {
    setFlash('error', 'Ungültige Sitzplatz-Auswahl (max. 10 Plätze pro Buchung).');
    redirect('/pages/tischplan.php?event_id=' . $eventId);
}

try {
    $pdo->beginTransaction();

    // Event prüfen (Admins dürfen auf allen nicht-abgerechneten Events reservieren)
    if (hasRole('admin')) {
        $stmtEvent = $pdo->prepare("SELECT id, status FROM events WHERE id = ? AND status != 'abgerechnet'");
    } else {
        $stmtEvent = $pdo->prepare("SELECT id, status FROM events WHERE id = ? AND status = 'aktiv'");
    }
    $stmtEvent->execute([$eventId]);
    if (!$stmtEvent->fetch()) {
        $pdo->rollBack();
        setFlash('error', 'Dieses Event ist nicht verfügbar.');
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
        $stmtRes->execute([$userId, $eventId, $seatId, $buchungsnummer, $eventPreis]);
        $reservationId = (int)$pdo->lastInsertId();

        $payStatus = 'offen';
        $stmtPay->execute([$reservationId, $userZahlungsart, $eventPreis, $payStatus]);
        $stmtSeat->execute([$seatId]);

        $buchungsnummern[] = $buchungsnummer;
        logAudit('RESERVIERUNG', 'reservations', $reservationId,
            "Buchung: {$buchungsnummer}, Event: {$eventId}, Sitz: {$seatId}");
    }

    $pdo->commit();

    // Wer gebucht hat, wartet nicht mehr.
    $pdo->prepare('DELETE FROM waitlist WHERE user_id = ? AND event_id = ?')->execute([$userId, $eventId]);

    // Bestätigungs-E-Mail mit einem QR-Code je Person
    sendReservierungsbestaetigung($userId, $eventId, $buchungsnummern);

    $anzahl = count($buchungsnummern);
    setFlash('success', "✓ {$anzahl} Platz/Plätze reserviert. Ihre Tickets mit QR-Code finden Sie unten und per E-Mail.");
    redirect('/pages/meine_reservierungen.php');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Reservierung Fehler: ' . $e->getMessage());
    setFlash('error', 'Technischer Fehler bei der Reservierung. Bitte erneut versuchen.');
    redirect('/pages/tischplan.php?event_id=' . $eventId);
}

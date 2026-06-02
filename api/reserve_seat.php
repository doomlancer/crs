<?php
/**
 * API: Sitzplatz reservieren oder stornieren
 * POST: seat_ids (kommagetrennte IDs), event_id, csrf_token, [action=cancel]
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

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
                'SELECT r.id, r.seat_id, r.event_id FROM reservations r
                 WHERE r.id = ? AND r.user_id = ? AND r.status = "geplant"'
            );
            $stmt->execute([$reservationId, $userId]);
        } else {
            // Über Seat-ID stornieren
            $stmt = $pdo->prepare(
                'SELECT r.id, r.seat_id, r.event_id FROM reservations r
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

        // Reservierung löschen (Sitz wird via FK-Cascade-Free freigegeben nach Seat-Update)
        // Buchungsdetails für E-Mail vor dem Löschen laden
        $stmtDetail = $pdo->prepare(
            'SELECT r.buchungsnummer, r.preis, u.vorname, u.email,
                    e.name AS event_name, e.datum AS event_datum, e.id AS event_id_detail,
                    p.betrag
             FROM reservations r
             JOIN users  u ON r.user_id  = u.id
             JOIN events e ON r.event_id = e.id
             LEFT JOIN payments p ON p.reservation_id = r.id
             WHERE r.id = ?'
        );
        $stmtDetail->execute([$reservation['id']]);
        $resDetail = $stmtDetail->fetch();

        $pdo->prepare('UPDATE seats SET status = "verfuegbar" WHERE id = ?')
            ->execute([$reservation['seat_id']]);
        $pdo->prepare('DELETE FROM payments WHERE reservation_id = ?')
            ->execute([$reservation['id']]);
        $pdo->prepare('DELETE FROM reservations WHERE id = ?')
            ->execute([$reservation['id']]);

        logAudit('STORNIERUNG', 'reservations', $reservation['id'], "Stornierung durch Benutzer");
        $pdo->commit();

        // Storno-E-Mails versenden (asynchron, Fehler ignorieren)
        if ($resDetail) {
            $eventIdForWl = $resDetail['event_id_detail'] ?? $reservation['event_id'];
            // Gast-E-Mail
            sendMail($resDetail['email'], 'Stornierungsbestätigung – ' . $resDetail['buchungsnummer'], 'storno_bestaetigung', [
                'vorname'       => $resDetail['vorname'],
                'buchungsnummer'=> $resDetail['buchungsnummer'],
                'event_name'    => $resDetail['event_name'],
                'event_datum'   => formatDatum($resDetail['event_datum']),
                'betrag'        => formatBetrag((float)($resDetail['betrag'] ?? $resDetail['preis'] ?? 0)),
            ]);
            // Admin/Kassierer-Info
            notifyAdminStorno([
                'gast_name'      => $resDetail['vorname'],
                'gast_email'     => $resDetail['email'],
                'buchungsnummer' => $resDetail['buchungsnummer'],
                'event_name'     => $resDetail['event_name'],
                'event_datum'    => formatDatum($resDetail['event_datum']),
                'betrag'         => $resDetail['betrag'] ?? $resDetail['preis'] ?? 0,
            ], 'Gast selbst');
            // Nächsten auf Warteliste benachrichtigen
            notifyNextWaitingUser((int)$eventIdForWl);
        }

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

// Accept both: seat_ids[] checkbox array (new) and seat_ids comma-string (legacy)
if (isset($_POST['seat_ids']) && is_array($_POST['seat_ids'])) {
    $seatIds = array_values(array_unique(array_filter(
        array_map('intval', $_POST['seat_ids']),
        fn($id) => $id > 0
    )));
} else {
    $seatIds = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)($_POST['seat_ids'] ?? ''))),
        fn($id) => $id > 0
    )));
}

if (empty($seatIds)) {
    setFlash('error', 'Bitte wählen Sie mindestens einen Sitzplatz aus.');
    redirect('/pages/tischplan.php?event_id=' . $eventId);
}

if (empty($seatIds) || count($seatIds) > 10) {
    setFlash('error', 'Ungültige Sitzplatz-Auswahl (max. 10 Plätze pro Buchung).');
    redirect('/pages/tischplan.php?event_id=' . $eventId);
}

try {
    $pdo->beginTransaction();

    // Event prüfen (existiert, ist aktiv) und Preis laden
    $stmtEvent = $pdo->prepare("SELECT id, status, preis FROM events WHERE id = ? AND status = 'aktiv'");
    $stmtEvent->execute([$eventId]);
    $eventData = $stmtEvent->fetch();
    if (!$eventData) {
        $pdo->rollBack();
        setFlash('error', 'Dieses Event ist nicht mehr verfügbar.');
        redirect('/pages/events.php');
    }
    $ticketPreis = (float)($eventData['preis'] ?? TICKET_PREIS);

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
        $stmtRes->execute([$userId, $eventId, $seatId, $buchungsnummer, $ticketPreis]);
        $reservationId = (int)$pdo->lastInsertId();

        $stmtPay->execute([$reservationId, $userZahlungsart, $ticketPreis, 'offen']);
        $stmtSeat->execute([$seatId]);

        $buchungsnummern[] = $buchungsnummer;
        logAudit('RESERVIERUNG', 'reservations', $reservationId,
            "Buchung: {$buchungsnummer}, Event: {$eventId}, Sitz: {$seatId}");
    }

    $pdo->commit();

    // Buchungsbestätigungs-E-Mail senden
    try {
        $stmtUser2 = $pdo->prepare('SELECT vorname, email FROM users WHERE id = ?');
        $stmtUser2->execute([$userId]);
        $userInfo  = $stmtUser2->fetch();

        $stmtEv = $pdo->prepare('SELECT name, datum FROM events WHERE id = ?');
        $stmtEv->execute([$eventId]);
        $evInfo = $stmtEv->fetch();

        if ($userInfo && $evInfo) {
            $ticketUrl = APP_URL . '/pages/buchung_detail.php?buchungsnummer=' . urlencode($buchungsnummern[0]);
            sendMail($userInfo['email'], 'Buchungsbestätigung – ' . implode(', ', $buchungsnummern), 'buchungsbestaetigung', [
                'vorname'        => $userInfo['vorname'],
                'buchungsnummern'=> $buchungsnummern,
                'event_name'     => $evInfo['name'],
                'event_datum'    => formatDatum($evInfo['datum']),
                'anzahl'         => count($buchungsnummern),
                'betrag_gesamt'  => formatBetrag(count($buchungsnummern) * $ticketPreis),
                'zahlungsart'    => zahlungsartLabel($userZahlungsart),
                'ticket_url'     => $ticketUrl,
            ]);
        }
    } catch (Exception $e) {
        error_log('Buchungsbestätigungs-Mail Fehler: ' . $e->getMessage());
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

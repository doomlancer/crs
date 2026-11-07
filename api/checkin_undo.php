<?php
/**
 * API: Check-in rückgängig machen (falscher Gast angeklickt, Doppelscan …).
 *
 * POST: reservation_id, csrf_token
 *
 * Kassierer/Admin dürfen jeden Check-in zurücknehmen. Ein Einlass-Zugang nur
 * seine eigenen und nur innerhalb von 15 Minuten – genug für einen sofort
 * bemerkten Fehlgriff, ohne dass ein Gerät fremde Einlässe ändern kann.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

function undoAntwort(bool $ok, string $msg, ?array $data = null, int $status = 200): void {
    if (wantsJson()) {
        jsonResponse(['success' => $ok, 'message' => $msg, 'data' => $data], $status);
    }
    setFlash($ok ? 'success' : 'error', $msg);
    redirect(safeRedirectTarget($_POST['redirect'] ?? null, '/pages/kassierer_guestlist.php'));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    undoAntwort(false, 'Nur POST erlaubt.', null, 405);
}

requireRole('kassierer', 'admin', 'einlass');

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    undoAntwort(false, 'Sicherheitsfehler. Bitte Seite neu laden.', null, 403);
}

$reservationId = (int)($_POST['reservation_id'] ?? 0);
if ($reservationId < 1) {
    undoAntwort(false, 'Kein Ticket angegeben.', null, 400);
}

$pdo = getDB();
ensureCheckinColumns();

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'SELECT r.id, r.status, r.seat_id, r.event_id, r.buchungsnummer, r.eingecheckt_am,
                r.eingecheckt_von_einlass_id, u.vorname, u.nachname
         FROM reservations r JOIN users u ON u.id = r.user_id
         WHERE r.id = ? FOR UPDATE'
    );
    $stmt->execute([$reservationId]);
    $res = $stmt->fetch();

    if (!$res || $res['status'] !== 'eingecheckt') {
        $pdo->rollBack();
        undoAntwort(false, 'Dieses Ticket ist nicht eingecheckt.', null, 409);
    }

    if (hasRole('einlass')) {
        $eigen   = (int)$res['eingecheckt_von_einlass_id'] === (int)($_SESSION['einlass_id'] ?? 0);
        $frisch  = $res['eingecheckt_am'] && (time() - strtotime($res['eingecheckt_am'])) <= 900;
        if (!$eigen || !$frisch || (int)$res['event_id'] !== (int)($_SESSION['einlass_event_id'] ?? 0)) {
            $pdo->rollBack();
            undoAntwort(false, 'Nur eigene Check-ins der letzten 15 Minuten lassen sich hier zurücknehmen. Bitte an die Kasse wenden.', null, 403);
        }
    }

    $pdo->prepare(
        "UPDATE reservations SET status = 'geplant', eingecheckt_am = NULL,
                eingecheckt_von = NULL, eingecheckt_von_einlass_id = NULL
         WHERE id = ?"
    )->execute([$reservationId]);
    if (!empty($res['seat_id'])) {
        $pdo->prepare("UPDATE seats SET status = 'reserviert' WHERE id = ?")->execute([$res['seat_id']]);
    }
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Check-in rückgängig Fehler: ' . $e->getMessage());
    undoAntwort(false, 'Technischer Fehler.', null, 500);
}

$gast = trim($res['vorname'] . ' ' . $res['nachname']);
logAudit('CHECK_IN_ZURUECK', 'reservations', $reservationId,
    'Check-in zurückgenommen: ' . $res['buchungsnummer'] . ' (' . $gast . ')' . auditActorLabel());

undoAntwort(true, 'Check-in von ' . $gast . ' wurde zurückgenommen.', ['reservation_id' => $reservationId]);

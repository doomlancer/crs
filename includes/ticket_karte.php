<?php
/**
 * Bausteine für die Ticket-Anzeige (eine Karte je Person mit großem QR-Code)
 * und den Zahlungshinweis. Genutzt von pages/ticket.php (Link ohne Login)
 * und pages/meine_reservierungen.php.
 */
require_once __DIR__ . '/qrcode.php';

/**
 * @param array $t buchungsnummer, status, seat_id, tischnummer, sitzplatznummer,
 *                 zahl_status, betrag/preis
 */
function renderTicketKarte(array $t, int $nr = 0, int $gesamt = 0, bool $mitWeitergabeLink = false): string {
    $e  = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $bn = $t['buchungsnummer'];
    $platz = !empty($t['seat_id'])
        ? sprintf(__('ticket.seat'), (int)$t['tischnummer'], (int)$t['sitzplatznummer'])
        : __('ticket.free_seating');
    $eingecheckt = ($t['status'] ?? '') === 'eingecheckt';
    $betrag = (float)($t['betrag'] ?? $t['preis'] ?? 0);

    $html  = '<div class="ticket-card" id="ticket-' . $e($bn) . '">';
    if ($gesamt > 1) {
        $html .= '<div class="ticket-nr">' . $e(sprintf(__('ticket.n_of'), $nr, $gesamt)) . '</div>';
    }
    $uri = qrCodeDataUri(ticketPayload($bn));
    if ($uri !== null) {
        $html .= '<img class="ticket-qr' . ($eingecheckt ? ' ticket-qr-used' : '') . '" src="' . $uri . '"'
               . ' alt="' . $e(sprintf(__('res.qr_alt'), $bn)) . '">';
    }
    $html .= '<div class="ticket-bn">' . $e($bn) . '</div>';
    $html .= '<div class="ticket-seat">' . $e($platz) . '</div>';
    if ($eingecheckt) {
        $html .= '<div class="ticket-state ticket-state-in"><i class="bi bi-check-circle-fill me-1"></i>' . $e(__('ticket.checked_in')) . '</div>';
    } elseif (($t['zahl_status'] ?? 'offen') === 'bezahlt') {
        $html .= '<div class="ticket-state ticket-state-paid"><i class="bi bi-check2 me-1"></i>' . $e(__('ticket.paid')) . '</div>';
    } else {
        $html .= '<div class="ticket-state ticket-state-open">' . $e(sprintf(__('ticket.payment_open'), formatBetrag($betrag))) . '</div>';
    }
    if ($mitWeitergabeLink && !$eingecheckt) {
        $html .= '<a class="ticket-forward" href="' . $e(ticketEinzelLinkUrl($bn)) . '">'
               . '<i class="bi bi-share me-1"></i>' . $e(__('ticket.forward')) . '</a>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Zahlungshinweis für einen offenen Betrag – bar am Einlass oder Überweisung
 * mit Bankverbindung aus den Einstellungen.
 */
function renderZahlungshinweis(string $zahlungsart, float $offen, array $buchungsnummern, string $nachname): string {
    if ($offen <= 0) return '';
    $e      = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $betrag = formatBetrag($offen);

    if ($zahlungsart !== 'ueberweisung') {
        return '<div class="alert alert-warning py-2 mb-0"><i class="bi bi-cash-coin me-1"></i>'
             . $e(sprintf(__('pay.cash_text'), $betrag)) . '</div>';
    }

    $zweck = verwendungszweck($buchungsnummern, $nachname);
    $bank  = bankverbindung();
    if ($bank['iban'] === '') {
        return '<div class="alert alert-warning py-2 mb-0"><i class="bi bi-bank me-1"></i>'
             . $e(sprintf(__('pay.transfer_text_nobank'), $betrag, $zweck)) . '</div>';
    }
    $zeilen = '';
    foreach ([
        'pay.holder'  => $bank['inhaber'],
        'pay.iban'    => $bank['iban'],
        'pay.bic'     => $bank['bic'],
        'pay.bank'    => $bank['bank'],
        'pay.purpose' => $zweck,
        'pay.amount'  => $betrag,
    ] as $label => $wert) {
        if ($wert === '') continue;
        $zeilen .= '<dt class="col-5 col-sm-4 fw-normal text-muted">' . $e(__($label)) . '</dt>'
                 . '<dd class="col-7 col-sm-8 mb-1 fw-semibold text-break">' . $e($wert) . '</dd>';
    }
    return '<div class="alert alert-warning py-2 mb-0"><div class="mb-1"><i class="bi bi-bank me-1"></i>'
         . $e(sprintf(__('pay.transfer_text'), $betrag)) . '</div><dl class="row mb-0 small">' . $zeilen . '</dl></div>';
}

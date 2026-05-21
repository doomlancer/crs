<?php
/**
 * QR-Code-Hilfsfunktionen
 * Kein Composer nötig – Rendering erfolgt im Browser via qrcode.js (CDN)
 */

/**
 * Gibt einen <div>-Platzhalter aus, den qrcode.js im Browser befüllt.
 * qrcode.js muss auf der Seite eingebunden sein (siehe footer.php / meine_reservierungen.php).
 */
function qrCodeImg(string $inhalt, int $groesse = 160, string $altText = ''): string {
    $data = htmlspecialchars($inhalt, ENT_QUOTES, 'UTF-8');
    $alt  = htmlspecialchars($altText ?: $inhalt, ENT_QUOTES, 'UTF-8');
    return "<div class=\"qr-placeholder\" data-content=\"{$data}\" data-size=\"{$groesse}\" title=\"{$alt}\" style=\"width:{$groesse}px;height:{$groesse}px;display:inline-flex;align-items:center;justify-content:center\"></div>";
}

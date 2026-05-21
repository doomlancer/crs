<?php
/**
 * QR-Code-Hilfsfunktionen
 * Benötigt: endroid/qr-code (via Composer)
 */

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;

/**
 * Erzeugt einen QR-Code als Base64-kodiertes PNG-Bild
 *
 * @param string $inhalt  Zu kodierender Text (z. B. Buchungsnummer)
 * @param int    $groesse Bildgröße in Pixeln
 * @return string         Base64-String (direkt in <img src="data:image/png;base64,..."> nutzbar)
 *                        oder leerer String wenn Bibliothek nicht vorhanden
 */
function generateQrCodeBase64(string $inhalt, int $groesse = 200): string {
    if (!class_exists(QrCode::class)) {
        return '';
    }

    try {
        $qrCode = QrCode::create($inhalt)
            ->setEncoding(new Encoding('UTF-8'))
            ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
            ->setSize($groesse)
            ->setMargin(8)
            ->setForegroundColor(new Color(0, 0, 0))
            ->setBackgroundColor(new Color(255, 255, 255));

        $writer = new PngWriter();
        $result = $writer->write($qrCode);
        return base64_encode($result->getString());
    } catch (\Exception $e) {
        error_log('QR-Code Fehler: ' . $e->getMessage());
        return '';
    }
}

/**
 * Gibt ein <img>-Tag mit dem QR-Code zurück oder einen Fallback-Text
 */
function qrCodeImg(string $inhalt, int $groesse = 120, string $altText = ''): string {
    $base64 = generateQrCodeBase64($inhalt, $groesse);
    if (empty($base64)) {
        return '<span class="text-muted small">' . htmlspecialchars($inhalt) . '</span>';
    }
    $alt = htmlspecialchars($altText ?: $inhalt);
    return '<img src="data:image/png;base64,' . $base64 . '" alt="' . $alt . '" width="' . $groesse . '" height="' . $groesse . '" class="img-fluid">';
}

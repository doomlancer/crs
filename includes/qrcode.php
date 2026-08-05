<?php
/**
 * QR-Code-Erzeugung – reines PHP, keine externe Bibliothek, kein Composer, kein GD.
 *
 * Erzeugt vollwertige QR-Codes (Byte-Modus, Fehlerkorrektur-Level M, Version 1–10)
 * und gibt sie als SVG bzw. als <img>-Tag mit data:-URI aus.
 *
 * Warum serverseitig? Die frühere Lösung rendert QR-Codes im Browser per
 * CDN-JavaScript. Das schlug fehl, sobald JavaScript blockiert war oder keine
 * Internetverbindung bestand. Serverseitig erzeugt funktioniert der Code immer –
 * auch in E-Mails, im Ausdruck und ohne Netz am Veranstaltungsort.
 */

// ─────────────────────────────────────────────────────────────────────────────
// Öffentliche API
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Gibt einen fertigen <img>-Tag mit eingebettetem QR-Code zurück.
 */
function qrCodeImg(string $inhalt, int $groesse = 160, string $altText = ''): string {
    $alt = htmlspecialchars($altText ?: $inhalt, ENT_QUOTES, 'UTF-8');
    $uri = qrCodeDataUri($inhalt);
    if ($uri === null) {
        // Fallback: Inhalt wenigstens lesbar anzeigen
        return '<div class="qr-fallback text-center small text-muted">'
             . htmlspecialchars($inhalt, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return sprintf(
        '<img src="%s" alt="%s" width="%d" height="%d" class="qr-code" '
        . 'style="width:%dpx;height:%dpx;image-rendering:pixelated;background:#fff">',
        $uri, $alt, $groesse, $groesse, $groesse, $groesse
    );
}

/**
 * QR-Code als data:-URI (SVG, base64-kodiert).
 */
function qrCodeDataUri(string $inhalt): ?string {
    $svg = qrCodeSvg($inhalt);
    if ($svg === null) return null;
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/**
 * QR-Code als SVG-Quelltext.
 */
function qrCodeSvg(string $inhalt, int $quietZone = 4): ?string {
    $matrix = qrBuildMatrix($inhalt);
    if ($matrix === null) return null;

    $n     = count($matrix);
    $total = $n + 2 * $quietZone;

    $rects = '';
    for ($y = 0; $y < $n; $y++) {
        $x = 0;
        while ($x < $n) {
            if (!$matrix[$y][$x]) { $x++; continue; }
            // Zusammenhängende dunkle Module einer Zeile zu einem Rechteck bündeln
            $start = $x;
            while ($x < $n && $matrix[$y][$x]) $x++;
            $rects .= sprintf('<rect x="%d" y="%d" width="%d" height="1"/>',
                $start + $quietZone, $y + $quietZone, $x - $start);
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '" '
         . 'shape-rendering="crispEdges">'
         . '<rect width="' . $total . '" height="' . $total . '" fill="#fff"/>'
         . '<g fill="#000">' . $rects . '</g>'
         . '</svg>';
}

// ─────────────────────────────────────────────────────────────────────────────
// Kodierung
// ─────────────────────────────────────────────────────────────────────────────

/** Kapazität in Datenbytes je Version (Level M), Index = Version. */
function qrCapacityTable(): array {
    // [Daten-Codewords, EC-Codewords je Block, Blöcke Gruppe 1, Daten je Block G1,
    //  Blöcke Gruppe 2, Daten je Block G2]
    return [
        1  => [16,  10, 1, 16, 0, 0],
        2  => [28,  16, 1, 28, 0, 0],
        3  => [44,  26, 1, 44, 0, 0],
        4  => [64,  18, 2, 32, 0, 0],
        5  => [86,  24, 2, 43, 0, 0],
        6  => [108, 16, 4, 27, 0, 0],
        7  => [124, 18, 4, 31, 0, 0],
        8  => [154, 22, 2, 38, 2, 39],
        9  => [182, 22, 3, 36, 2, 37],
        10 => [216, 26, 4, 43, 1, 44],
    ];
}

/** Positionen der Ausrichtungsmuster je Version. */
function qrAlignmentPositions(int $version): array {
    $table = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];
    return $table[$version] ?? [];
}

/**
 * Baut die vollständige QR-Matrix (true = dunkel).
 */
function qrBuildMatrix(string $data, ?int $forceMask = null): ?array {
    $len  = strlen($data);
    $caps = qrCapacityTable();

    // Kleinste passende Version wählen (4 Bit Modus + 8 Bit Länge = 1,5 Bytes Overhead)
    $version = null;
    foreach ($caps as $v => $c) {
        if ($len + 2 <= $c[0]) { $version = $v; break; }
    }
    if ($version === null) return null; // Inhalt zu lang

    [$dataCw, $ecPerBlock, $g1Blocks, $g1Size, $g2Blocks, $g2Size] = $caps[$version];

    // ── Bitstrom aufbauen ────────────────────────────────────────────────
    $bits = '0100';                                    // Byte-Modus
    $bits .= str_pad(decbin($len), 8, '0', STR_PAD_LEFT); // Längenfeld (V1–9: 8 Bit)
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }

    $capacityBits = $dataCw * 8;
    // Abschlusszeichen (max. 4 Bit)
    $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));
    // Auf volle Bytes auffüllen
    if (strlen($bits) % 8 !== 0) {
        $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
    }
    // Mit den vorgeschriebenen Füllbytes auffüllen
    $pad = ['11101100', '00010001'];
    $i = 0;
    while (strlen($bits) < $capacityBits) {
        $bits .= $pad[$i++ % 2];
    }

    // Bitstrom -> Datenbytes
    $dataBytes = [];
    for ($i = 0; $i < $capacityBits; $i += 8) {
        $dataBytes[] = bindec(substr($bits, $i, 8));
    }

    // ── In Blöcke aufteilen und Fehlerkorrektur berechnen ────────────────
    $blocks   = [];
    $ecBlocks = [];
    $offset   = 0;
    foreach ([[$g1Blocks, $g1Size], [$g2Blocks, $g2Size]] as [$count, $size]) {
        for ($b = 0; $b < $count; $b++) {
            $block      = array_slice($dataBytes, $offset, $size);
            $offset    += $size;
            $blocks[]   = $block;
            $ecBlocks[] = qrReedSolomon($block, $ecPerBlock);
        }
    }

    // ── Blöcke verschränken (Interleaving) ───────────────────────────────
    $final   = [];
    $maxData = max(array_map('count', $blocks));
    for ($i = 0; $i < $maxData; $i++) {
        foreach ($blocks as $block) {
            if (isset($block[$i])) $final[] = $block[$i];
        }
    }
    for ($i = 0; $i < $ecPerBlock; $i++) {
        foreach ($ecBlocks as $block) {
            if (isset($block[$i])) $final[] = $block[$i];
        }
    }

    // Finaler Bitstrom
    $finalBits = '';
    foreach ($final as $byte) {
        $finalBits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
    }

    // ── Matrix aufbauen ──────────────────────────────────────────────────
    $size     = 17 + 4 * $version;
    $matrix   = array_fill(0, $size, array_fill(0, $size, false));
    $reserved = array_fill(0, $size, array_fill(0, $size, false));

    qrPlaceFinders($matrix, $reserved, $size);
    qrPlaceTiming($matrix, $reserved, $size);
    qrPlaceAlignment($matrix, $reserved, $version, $size);
    qrReserveFormat($reserved, $size, $version);

    // Dunkles Modul (immer gesetzt)
    $matrix[4 * $version + 9][8] = true;
    $reserved[4 * $version + 9][8] = true;

    qrPlaceData($matrix, $reserved, $finalBits, $size);

    // ── Beste Maske wählen ───────────────────────────────────────────────
    $best = null; $bestPenalty = PHP_INT_MAX;
    $maskRange = $forceMask === null ? range(0, 7) : [$forceMask];
    foreach ($maskRange as $mask) {
        $candidate = qrApplyMask($matrix, $reserved, $mask, $size);
        qrPlaceFormat($candidate, $mask, $size, $version);
        $penalty = qrPenalty($candidate, $size);
        if ($penalty < $bestPenalty) {
            $bestPenalty = $penalty;
            $best = $candidate;
        }
    }

    return $best;
}

// ─────────────────────────────────────────────────────────────────────────────
// Reed-Solomon-Fehlerkorrektur (GF(256), Primpolynom 0x11D)
// ─────────────────────────────────────────────────────────────────────────────

function qrGfTables(): array {
    static $exp = null, $log = null;
    if ($exp === null) {
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) $x ^= 0x11D;
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
    }
    return [$exp, $log];
}

function qrGfMul(int $a, int $b): int {
    if ($a === 0 || $b === 0) return 0;
    [$exp, $log] = qrGfTables();
    return $exp[$log[$a] + $log[$b]];
}

/** Erzeugt das Generatorpolynom für n EC-Codewords. */
function qrGeneratorPoly(int $n): array {
    [$exp, ] = qrGfTables();
    // Koeffizienten höchster Grad zuerst: poly[0] ist der Leitkoeffizient.
    // Multiplikation mit (x + a^i):  r[j] = p[j] XOR p[j-1]*a^i
    $poly = [1];
    for ($i = 0; $i < $n; $i++) {
        $next = array_fill(0, count($poly) + 1, 0);
        foreach ($poly as $j => $coef) {
            $next[$j]     ^= $coef;
            $next[$j + 1] ^= qrGfMul($coef, $exp[$i]);
        }
        $poly = $next;
    }
    return $poly;
}

/** Berechnet die EC-Codewords für einen Datenblock. */
function qrReedSolomon(array $data, int $ecLen): array {
    $gen  = qrGeneratorPoly($ecLen);
    $rest = array_merge($data, array_fill(0, $ecLen, 0));

    for ($i = 0; $i < count($data); $i++) {
        $factor = $rest[$i];
        if ($factor === 0) continue;
        for ($j = 0; $j < count($gen); $j++) {
            $rest[$i + $j] ^= qrGfMul($gen[$j], $factor);
        }
    }
    return array_slice($rest, count($data), $ecLen);
}

// ─────────────────────────────────────────────────────────────────────────────
// Muster platzieren
// ─────────────────────────────────────────────────────────────────────────────

function qrPlaceFinders(array &$m, array &$r, int $size): void {
    $positions = [[0, 0], [$size - 7, 0], [0, $size - 7]];
    foreach ($positions as [$col, $row]) {
        for ($y = -1; $y <= 7; $y++) {
            for ($x = -1; $x <= 7; $x++) {
                $ry = $row + $y; $rx = $col + $x;
                if ($ry < 0 || $ry >= $size || $rx < 0 || $rx >= $size) continue;
                $inRing   = ($x >= 0 && $x <= 6 && ($y === 0 || $y === 6))
                         || ($y >= 0 && $y <= 6 && ($x === 0 || $x === 6));
                $inCenter = ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4);
                $m[$ry][$rx] = ($inRing || $inCenter);
                $r[$ry][$rx] = true;
            }
        }
    }
}

function qrPlaceTiming(array &$m, array &$r, int $size): void {
    for ($i = 8; $i < $size - 8; $i++) {
        $dark = ($i % 2 === 0);
        $m[6][$i] = $dark; $r[6][$i] = true;
        $m[$i][6] = $dark; $r[$i][6] = true;
    }
}

function qrPlaceAlignment(array &$m, array &$r, int $version, int $size): void {
    $pos = qrAlignmentPositions($version);
    foreach ($pos as $py) {
        foreach ($pos as $px) {
            // Überlappung mit den Suchmustern auslassen
            if (($px === 6 && $py === 6)
             || ($px === 6 && $py === $size - 7)
             || ($px === $size - 7 && $py === 6)) continue;

            for ($y = -2; $y <= 2; $y++) {
                for ($x = -2; $x <= 2; $x++) {
                    $ry = $py + $y; $rx = $px + $x;
                    if ($ry < 0 || $ry >= $size || $rx < 0 || $rx >= $size) continue;
                    $m[$ry][$rx] = (max(abs($x), abs($y)) !== 1);
                    $r[$ry][$rx] = true;
                }
            }
        }
    }
}

function qrReserveFormat(array &$r, int $size, int $version): void {
    for ($i = 0; $i < 9; $i++) {
        if ($i !== 6) { $r[8][$i] = true; $r[$i][8] = true; }
    }
    for ($i = 0; $i < 8; $i++) {
        $r[8][$size - 1 - $i] = true;
        $r[$size - 1 - $i][8] = true;
    }
    $r[8][6] = true; $r[6][8] = true;

    // Versionsinformation (ab Version 7)
    if ($version >= 7) {
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $r[$i][$size - 11 + $j] = true;
                $r[$size - 11 + $j][$i] = true;
            }
        }
    }
}

function qrPlaceData(array &$m, array $r, string $bits, int $size): void {
    $len = strlen($bits);
    $idx = 0;
    $up  = true;

    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) $col--; // Spalte 6 ist Timing-Pattern
        for ($i = 0; $i < $size; $i++) {
            $row = $up ? ($size - 1 - $i) : $i;
            for ($c = 0; $c < 2; $c++) {
                $x = $col - $c;
                if ($r[$row][$x]) continue;
                $m[$row][$x] = $idx < $len && $bits[$idx] === '1';
                $idx++;
            }
        }
        $up = !$up;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Maskierung & Bewertung
// ─────────────────────────────────────────────────────────────────────────────

function qrApplyMask(array $m, array $r, int $mask, int $size): array {
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($r[$y][$x]) continue;
            $flip = match ($mask) {
                0 => ($y + $x) % 2 === 0,
                1 => $y % 2 === 0,
                2 => $x % 3 === 0,
                3 => ($y + $x) % 3 === 0,
                4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                5 => (($y * $x) % 2 + ($y * $x) % 3) === 0,
                6 => ((($y * $x) % 2 + ($y * $x) % 3) % 2) === 0,
                7 => ((($y + $x) % 2 + ($y * $x) % 3) % 2) === 0,
                default => false,
            };
            if ($flip) $m[$y][$x] = !$m[$y][$x];
        }
    }
    return $m;
}

function qrPlaceFormat(array &$m, int $mask, int $size, int $version): void {
    // Level M = 00, gefolgt von 3 Maskenbits
    $data = (0b00 << 3) | $mask;
    $rem  = $data;
    for ($i = 0; $i < 10; $i++) {
        $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
    }
    $format = (($data << 10) | $rem) ^ 0x5412;

    // Bit 0 = LSB. Reihenfolge nach ISO/IEC 18004:
    // Kopie 1 läuft von (0,8) aufwärts und dann in Zeile 8 nach links,
    // Kopie 2 von (8,size-1) nach links bzw. von unten nach oben in Spalte 8.
    for ($i = 0; $i < 15; $i++) {
        $bit = (($format >> $i) & 1) === 1;

        // Kopie 1 – um das linke obere Suchmuster
        if ($i < 6)       { $m[$i][8] = $bit; }
        elseif ($i === 6) { $m[7][8] = $bit; }
        elseif ($i === 7) { $m[8][8] = $bit; }
        elseif ($i === 8) { $m[8][7] = $bit; }
        else              { $m[8][14 - $i] = $bit; }

        // Kopie 2 – rechts oben / links unten
        if ($i < 8) { $m[8][$size - 1 - $i] = $bit; }
        else        { $m[$size - 15 + $i][8] = $bit; }
    }

    // Versionsinformation (ab Version 7)
    if ($version >= 7) {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
        }
        $vinfo = ($version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $bit = (($vinfo >> $i) & 1) === 1;
            $a = intdiv($i, 3);
            $b = $size - 11 + ($i % 3);
            $m[$a][$b] = $bit;
            $m[$b][$a] = $bit;
        }
    }
}

/** Bewertet eine maskierte Matrix nach den vier Standardregeln (kleiner = besser). */
function qrPenalty(array $m, int $size): int {
    $penalty = 0;

    // Regel 1: fünf oder mehr gleiche Module in Folge
    for ($i = 0; $i < $size; $i++) {
        for ($dir = 0; $dir < 2; $dir++) {
            $run = 1;
            for ($j = 1; $j < $size; $j++) {
                $cur  = $dir === 0 ? $m[$i][$j]     : $m[$j][$i];
                $prev = $dir === 0 ? $m[$i][$j - 1] : $m[$j - 1][$i];
                if ($cur === $prev) {
                    $run++;
                } else {
                    if ($run >= 5) $penalty += 3 + ($run - 5);
                    $run = 1;
                }
            }
            if ($run >= 5) $penalty += 3 + ($run - 5);
        }
    }

    // Regel 2: gleichfarbige 2x2-Blöcke
    for ($y = 0; $y < $size - 1; $y++) {
        for ($x = 0; $x < $size - 1; $x++) {
            $v = $m[$y][$x];
            if ($v === $m[$y][$x + 1] && $v === $m[$y + 1][$x] && $v === $m[$y + 1][$x + 1]) {
                $penalty += 3;
            }
        }
    }

    // Regel 3: Muster 1:1:3:1:1 (ähnelt dem Suchmuster)
    $p1 = [true, false, true, true, true, false, true, false, false, false, false];
    $p2 = [false, false, false, false, true, false, true, true, true, false, true];
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size - 10; $x++) {
            $row = array_slice($m[$y], $x, 11);
            if ($row === $p1 || $row === $p2) $penalty += 40;

            $col = [];
            for ($k = 0; $k < 11; $k++) {
                if ($x + $k >= $size) break;
                $col[] = $m[$x + $k][$y];
            }
            if (count($col) === 11 && ($col === $p1 || $col === $p2)) $penalty += 40;
        }
    }

    // Regel 4: Abweichung vom 50%-Verhältnis dunkler Module
    $dark = 0;
    foreach ($m as $row) {
        foreach ($row as $v) if ($v) $dark++;
    }
    $ratio = ($dark * 100) / ($size * $size);
    $penalty += ((int)(abs($ratio - 50) / 5)) * 10;

    return $penalty;
}

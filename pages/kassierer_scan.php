<?php
/**
 * QR-Scanner für den Einlass (Kassierer/Admin).
 *
 * Nutzt bevorzugt die native BarcodeDetector-API des Browsers und fällt
 * andernfalls auf die lokal eingebundene jsQR-Bibliothek zurück – beides
 * ohne Internetverbindung nutzbar.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('kassierer', 'admin');

$pdo = getDB();

// Auswählbare Veranstaltungen
$events = $pdo->query(
    "SELECT id, name, datum FROM events
     WHERE status IN ('aktiv','planung')
     ORDER BY datum ASC"
)->fetchAll();

$selectedEventId = (int)($_GET['event_id'] ?? ($events[0]['id'] ?? 0));

$pageTitle  = 'QR-Scanner';
$csrfToken  = generateCsrfToken();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="container py-3" style="max-width:620px;">

    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <h1 class="h4 fw-bold mb-0">
            <i class="bi bi-qr-code-scan text-warning me-2"></i>Einlass-Scanner
        </h1>
        <a href="/pages/kassierer_dashboard.php<?= $selectedEventId ? '?event_id=' . $selectedEventId : '' ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
    </div>

    <?= getFlash() ?>

    <!-- Veranstaltung wählen -->
    <?php if (count($events) > 1): ?>
    <form method="GET" class="mb-3">
        <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
            <select name="event_id" class="form-select" data-autosubmit>
                <?php foreach ($events as $ev): ?>
                <option value="<?= $ev['id'] ?>" <?= $ev['id'] == $selectedEventId ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ev['name']) ?> – <?= formatDatum($ev['datum']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-secondary btn-sm" type="submit">Wechseln</button></noscript>
        </div>
    </form>
    <?php endif; ?>

    <!-- Ergebnisanzeige -->
    <div id="result-box" class="alert d-none mb-3 text-center rounded-3 py-3" role="status" aria-live="assertive">
        <div id="result-icon" class="fs-1 lh-1 mb-1"></div>
        <div id="result-name" class="fw-bold fs-4"></div>
        <div id="result-detail" class="small"></div>
    </div>

    <!-- Live-Zähler -->
    <div class="d-flex justify-content-center gap-4 mb-3 text-center">
        <div>
            <div class="fs-3 fw-bold text-success" id="cnt-ok">0</div>
            <small class="text-muted">Eingecheckt</small>
        </div>
        <div>
            <div class="fs-3 fw-bold text-danger" id="cnt-fail">0</div>
            <small class="text-muted">Abgewiesen</small>
        </div>
    </div>

    <!-- Kamerabild -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-2">
            <div id="camera-wrap" class="position-relative bg-dark rounded overflow-hidden"
                 style="aspect-ratio:1/1;">
                <video id="video" playsinline muted
                       style="width:100%;height:100%;object-fit:cover;"></video>
                <canvas id="canvas" class="d-none"></canvas>
                <!-- Zielrahmen -->
                <div class="position-absolute top-50 start-50 translate-middle border border-3 border-warning rounded-3"
                     style="width:65%;aspect-ratio:1/1;pointer-events:none;opacity:.85"></div>
            </div>
            <div id="cam-status" class="small text-muted text-center mt-2">Kamera wird gestartet …</div>
            <div class="d-grid gap-2 mt-2">
                <button type="button" id="btn-start" class="btn btn-warning fw-bold d-none">
                    <i class="bi bi-camera-video me-1"></i>Kamera starten
                </button>
                <button type="button" id="btn-switch" class="btn btn-outline-secondary btn-sm d-none">
                    <i class="bi bi-arrow-repeat me-1"></i>Kamera wechseln
                </button>
            </div>
        </div>
    </div>

    <!-- Manuelle Eingabe (Fallback bei beschädigtem Code) -->
    <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold py-2">
            <i class="bi bi-keyboard me-2"></i>Manuelle Eingabe
        </div>
        <div class="card-body">
            <form method="POST" action="/api/checkin_gast.php" id="manual-form" class="row g-2">
                <?= csrfField() ?>
                <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                <input type="hidden" name="redirect" value="/pages/kassierer_scan.php?event_id=<?= $selectedEventId ?>">
                <div class="col-8">
                    <input type="text" name="buchungsnummer" id="manual-input" class="form-control text-uppercase"
                           placeholder="KARN-2026-XXXXXX" pattern="[Kk][Aa][Rr][Nn]-\d{4}-[0-9A-Fa-f]{6}"
                           autocomplete="off">
                </div>
                <div class="col-4 d-grid">
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-check2 me-1"></i>Einchecken
                    </button>
                </div>
            </form>
            <div class="form-text mt-2">
                Nur verwenden, wenn der QR-Code nicht lesbar ist – wird gesondert protokolliert.
            </div>
        </div>
    </div>

    <!-- Letzte Scans -->
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-header fw-semibold py-2">
            <i class="bi bi-clock-history me-2"></i>Letzte Scans
        </div>
        <ul class="list-group list-group-flush" id="scan-log">
            <li class="list-group-item text-muted small">Noch keine Scans.</li>
        </ul>
    </div>

</main>

<?php
$jsCsrf    = json_encode($csrfToken, JSON_UNESCAPED_SLASHES);
$jsEventId = json_encode($selectedEventId);

// WICHTIG: Nowdoc (<<<'JS'), damit PHP nichts interpoliert.
// Die beiden dynamischen Werte werden danach gezielt ersetzt.
$extraScripts = <<<'JS'
<script src="/assets/vendor/js/jsqr.min.js"></script>
<script>
(function () {
    'use strict';

    var CSRF     = __CSRF__;
    var EVENT_ID = __EVENT_ID__;

    var video    = document.getElementById('video');
    var canvas   = document.getElementById('canvas');
    var ctx      = canvas.getContext('2d', { willReadFrequently: true });
    var status   = document.getElementById('cam-status');
    var btnStart = document.getElementById('btn-start');
    var btnSwitch= document.getElementById('btn-switch');

    var box    = document.getElementById('result-box');
    var icon   = document.getElementById('result-icon');
    var name   = document.getElementById('result-name');
    var detail = document.getElementById('result-detail');

    var okCount = 0, failCount = 0;
    var lastCode = '', lastTime = 0;
    var busy = false;
    var stream = null;
    var facing = 'environment';
    var detector = null;

    // ─── Event-Auswahl ohne Inline-Handler ───────────────────────────────
    document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
        el.addEventListener('change', function () { el.form.submit(); });
    });

    // ─── Ergebnis anzeigen ───────────────────────────────────────────────
    function show(kind, titleText, detailText) {
        box.className = 'alert mb-3 text-center rounded-3 py-3 alert-' +
            (kind === 'ok' ? 'success' : (kind === 'warn' ? 'warning' : 'danger'));
        icon.textContent   = kind === 'ok' ? '✓' : (kind === 'warn' ? '!' : '✕');
        name.textContent   = titleText;
        detail.textContent = detailText || '';
        box.classList.remove('d-none');

        if (navigator.vibrate) navigator.vibrate(kind === 'ok' ? 120 : [80, 60, 80]);
        beep(kind === 'ok');
    }

    // Kurzer Signalton – ohne Audiodatei, damit nichts nachgeladen werden muss
    function beep(good) {
        try {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            var ac = new AC();
            var osc = ac.createOscillator(), gain = ac.createGain();
            osc.connect(gain); gain.connect(ac.destination);
            osc.frequency.value = good ? 880 : 220;
            gain.gain.setValueAtTime(0.15, ac.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + 0.25);
            osc.start(); osc.stop(ac.currentTime + 0.25);
        } catch (e) { /* Ton ist optional */ }
    }

    function addLog(kind, text, time) {
        var list = document.getElementById('scan-log');
        if (list.children.length === 1 && list.children[0].textContent.indexOf('Noch keine') === 0) {
            list.innerHTML = '';
        }
        var li = document.createElement('li');
        li.className = 'list-group-item d-flex justify-content-between align-items-center py-2';
        var span = document.createElement('span');
        span.className = 'small';
        span.textContent = text;
        var badge = document.createElement('span');
        badge.className = 'badge bg-' + (kind === 'ok' ? 'success' : 'danger');
        badge.textContent = time;
        li.appendChild(span); li.appendChild(badge);
        list.insertBefore(li, list.firstChild);
        while (list.children.length > 10) list.removeChild(list.lastChild);
    }

    // ─── Check-in senden ─────────────────────────────────────────────────
    function doCheckin(payload) {
        if (busy) return;
        busy = true;

        var fd = new FormData();
        fd.append('payload', payload);
        fd.append('csrf_token', CSRF);
        fd.append('event_id', EVENT_ID);
        fd.append('format', 'json');

        fetch('/api/checkin_gast.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (res) {
            var t = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
            if (!res) { show('err', 'Serverfehler', 'Antwort nicht lesbar'); failCount++; }
            else if (res.success) {
                var d = res.data || {};
                okCount++;
                var zusatz = d.platz || '';
                if (d.zahl_status && d.zahl_status !== 'bezahlt') {
                    show('warn', d.gast || 'Eingecheckt', 'ZAHLUNG OFFEN · ' + zusatz);
                } else {
                    show('ok', d.gast || 'Eingecheckt', zusatz);
                }
                addLog('ok', (d.gast || payload), t);
            } else {
                failCount++;
                show('err', res.message || 'Abgelehnt', (res.data && res.data.gast) ? res.data.gast : '');
                addLog('fail', (res.message || 'Abgelehnt'), t);
            }
            document.getElementById('cnt-ok').textContent = okCount;
            document.getElementById('cnt-fail').textContent = failCount;
        })
        .catch(function (e) {
            failCount++;
            show('err', 'Netzwerkfehler', e.message);
            document.getElementById('cnt-fail').textContent = failCount;
        })
        .then(function () {
            // Kurze Sperre, damit derselbe Code nicht mehrfach gesendet wird
            setTimeout(function () { busy = false; }, 1200);
        });
    }

    function handleCode(text) {
        if (!text) return;
        var now = Date.now();
        if (text === lastCode && now - lastTime < 2500) return;  // Doppelscan unterdrücken
        lastCode = text; lastTime = now;
        doCheckin(text);
    }

    // ─── Kamera ──────────────────────────────────────────────────────────
    function stopCam() {
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    }

    function startCam() {
        stopCam();
        status.textContent = 'Kamera wird gestartet …';

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            status.textContent = 'Dieser Browser unterstützt keinen Kamerazugriff. Bitte manuelle Eingabe nutzen.';
            btnStart.classList.add('d-none');
            return;
        }

        navigator.mediaDevices.getUserMedia({
            video: { facingMode: facing, width: { ideal: 1280 }, height: { ideal: 720 } },
            audio: false
        })
        .then(function (s) {
            stream = s;
            video.srcObject = s;
            return video.play();
        })
        .then(function () {
            status.textContent = 'Bereit – QR-Code in den Rahmen halten.';
            btnStart.classList.add('d-none');
            btnSwitch.classList.remove('d-none');
            scanLoop();
        })
        .catch(function (err) {
            var msg = 'Kamera nicht verfügbar: ' + (err && err.name ? err.name : 'Fehler');
            if (err && err.name === 'NotAllowedError') {
                msg = 'Kamerazugriff wurde abgelehnt. Bitte im Browser erlauben und neu starten.';
            } else if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
                msg = 'Kamera benötigt eine HTTPS-Verbindung. Bitte die Seite über https:// aufrufen '
                    + 'oder die manuelle Eingabe nutzen.';
            }
            status.textContent = msg;
            btnStart.classList.remove('d-none');
        });
    }

    // ─── Scan-Schleife ───────────────────────────────────────────────────
    function scanLoop() {
        if (!stream) return;

        if (video.readyState === video.HAVE_ENOUGH_DATA) {
            var w = video.videoWidth, h = video.videoHeight;
            if (w && h) {
                canvas.width = w; canvas.height = h;
                ctx.drawImage(video, 0, 0, w, h);

                if (detector) {
                    detector.detect(canvas)
                        .then(function (codes) {
                            if (codes && codes.length) handleCode(codes[0].rawValue);
                        })
                        .catch(function () { /* einzelne Frames dürfen fehlschlagen */ });
                } else if (window.jsQR) {
                    var img = ctx.getImageData(0, 0, w, h);
                    var code = window.jsQR(img.data, w, h, { inversionAttempts: 'dontInvert' });
                    if (code && code.data) handleCode(code.data);
                }
            }
        }
        requestAnimationFrame(scanLoop);
    }

    // Native Erkennung bevorzugen (schneller, akkuschonender)
    if ('BarcodeDetector' in window) {
        try {
            detector = new window.BarcodeDetector({ formats: ['qr_code'] });
        } catch (e) { detector = null; }
    }

    btnStart.addEventListener('click', startCam);
    btnSwitch.addEventListener('click', function () {
        facing = (facing === 'environment') ? 'user' : 'environment';
        startCam();
    });

    // Manuelle Eingabe per AJAX, damit die Seite nicht neu lädt
    document.getElementById('manual-form').addEventListener('submit', function (e) {
        var input = document.getElementById('manual-input');
        var val = (input.value || '').trim().toUpperCase();
        if (!val) return;
        e.preventDefault();

        var fd = new FormData();
        fd.append('buchungsnummer', val);
        fd.append('csrf_token', CSRF);
        fd.append('event_id', EVENT_ID);
        fd.append('format', 'json');

        fetch('/api/checkin_gast.php', {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            var t = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
            if (res.success) {
                okCount++;
                show('ok', (res.data && res.data.gast) || 'Eingecheckt', (res.data && res.data.platz) || '');
                addLog('ok', (res.data && res.data.gast) || val, t);
                input.value = '';
            } else {
                failCount++;
                show('err', res.message || 'Abgelehnt', '');
                addLog('fail', res.message || 'Abgelehnt', t);
            }
            document.getElementById('cnt-ok').textContent = okCount;
            document.getElementById('cnt-fail').textContent = failCount;
        })
        .catch(function () { show('err', 'Netzwerkfehler', ''); });
    });

    window.addEventListener('pagehide', stopCam);

    startCam();
})();
</script>
JS;

$extraScripts = str_replace(
    ['__CSRF__', '__EVENT_ID__'],
    [$jsCsrf, $jsEventId],
    $extraScripts
);

include __DIR__ . '/../includes/footer.php';
?>

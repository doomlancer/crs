<?php
/**
 * Einlass-Scan: schlanke Scan-Seite für den Einlass-Zugang (Kurzcode-Login,
 * kein volles Kassierer-Konto). Gebaut für ein einfaches Handy: dunkles,
 * reduziertes Layout, große Bedienelemente, kein Navbar-Ballast.
 *
 * Kamera-/Foto-/jsQR-Logik ist identisch zu kassierer_scan.php (bewährter
 * Code, dieselben DOM-IDs) – hier nur das Drumherum vereinfacht und das
 * Event fest aus der Einlass-Session gebunden statt wählbar.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    redirect('/pages/einlass_login.php');
}
if (!hasRole('einlass', 'kassierer', 'admin')) {
    http_response_code(403);
    include __DIR__ . '/error_403.php';
    exit;
}

$pdo = getDB();
$isEinlass = hasRole('einlass');

if ($isEinlass) {
    $selectedEventId = (int)($_SESSION['einlass_event_id'] ?? 0);
    $helferLabel      = $_SESSION['einlass_label'] ?? 'Einlass';
    $logoutUrl        = '/includes/auth.php?action=einlass_logout';
} else {
    // Kassierer/Admin können dieselbe Seite testweise mit Eventauswahl nutzen.
    $events = $pdo->query(
        "SELECT id, name, datum FROM events WHERE status IN ('aktiv','planung') ORDER BY datum ASC"
    )->fetchAll();
    $selectedEventId = (int)($_GET['event_id'] ?? ($events[0]['id'] ?? 0));
    $helferLabel      = trim(($_SESSION['vorname'] ?? '') . ' ' . ($_SESSION['nachname'] ?? '')) ?: 'Kassierer';
    $logoutUrl        = '/includes/auth.php?action=logout';
}

$currentEvent = null;
if ($selectedEventId) {
    $stmt = $pdo->prepare('SELECT id, name, datum FROM events WHERE id = ?');
    $stmt->execute([$selectedEventId]);
    $currentEvent = $stmt->fetch() ?: null;
}

$pageTitle = 'Einlass-Scan';
$bodyClass = 'bg-dark';
$csrfToken = generateCsrfToken();

$extraHead = '
<style>
body.bg-dark { background:#0f0f0f !important; }
.es-wrap { max-width: 480px; margin: 0 auto; color:#fff; }
.es-topbar { display:flex; align-items:center; justify-content:space-between; padding: 14px 16px 6px; }
.es-topbar .label { font-size:.72rem; color:#9a948d; }
.es-counts { display:flex; gap:10px; padding: 0 16px 12px; }
.es-count-box { flex:1; background:#1e1e1e; border-radius:10px; padding:10px 12px; }
.es-count-box .n { font-size:1.3rem; font-weight:700; }
#camera-wrap { border-radius:16px; }
.es-fallback-btn { background:#242424; color:#fff; border:none; border-radius:12px; padding:14px 8px;
    font-size:.8rem; font-weight:700; display:flex; flex-direction:column; align-items:center; gap:6px; }
.es-fallback-btn:hover, .es-fallback-btn:focus { background:#2d2d2d; color:#fff; }
#manual-form input.form-control { background:#1e1e1e; border-color:#3a3a3a; color:#fff; }
#manual-form input.form-control::placeholder { color:#6b6763; }
#scan-log { background:#161616; }
#scan-log .list-group-item { background:#161616; color:#e5e2dd; border-color:#2a2a2a; }
</style>';

include __DIR__ . '/../includes/header.php';
?>

<div class="es-wrap">

    <div class="es-topbar">
        <div>
            <div class="fw-bold">Einlass-Scan</div>
            <div class="label"><?= htmlspecialchars($helferLabel) ?><?php if ($currentEvent): ?> &middot; <?= htmlspecialchars($currentEvent['name']) ?><?php endif; ?></div>
        </div>
        <a href="<?= $logoutUrl ?>" class="text-white-50 small text-decoration-none">Abmelden</a>
    </div>

    <?= getFlash() ?>

    <?php if (!$currentEvent): ?>
    <div class="alert alert-warning mx-3">
        <i class="bi bi-exclamation-triangle me-2"></i>Kein Event zugeordnet. Bitte Orga-Team kontaktieren.
    </div>
    <?php else: ?>

    <!-- Ergebnisanzeige -->
    <div class="px-3">
        <div id="result-box" class="alert d-none mb-3 text-center rounded-3 py-3" role="status" aria-live="assertive">
            <div id="result-icon" class="fs-1 lh-1 mb-1"></div>
            <div id="result-name" class="fw-bold fs-4"></div>
            <div id="result-detail" class="small"></div>
        </div>
    </div>

    <!-- Live-Zähler -->
    <div class="es-counts">
        <div class="es-count-box text-center">
            <div class="n text-success" id="cnt-ok">0</div>
            <div class="small text-white-50">Eingecheckt</div>
        </div>
        <div class="es-count-box text-center">
            <div class="n text-danger" id="cnt-fail">0</div>
            <div class="small text-white-50">Abgewiesen</div>
        </div>
    </div>

    <!-- Kamerabild -->
    <div class="px-3 mb-3">
        <div id="camera-wrap" class="position-relative bg-black overflow-hidden" style="aspect-ratio:1/1;">
            <video id="video" playsinline muted style="width:100%;height:100%;object-fit:cover;"></video>
            <canvas id="canvas" class="d-none"></canvas>
            <div class="position-absolute top-50 start-50 translate-middle border border-3 border-danger rounded-3"
                 style="width:65%;aspect-ratio:1/1;pointer-events:none;opacity:.85"></div>
        </div>
        <div id="cam-status" class="small text-white-50 text-center mt-2">Kamera wird gestartet …</div>
        <div class="d-grid gap-2 mt-2">
            <button type="button" id="btn-start" class="btn btn-danger fw-bold d-none">
                <i class="bi bi-camera-video me-1"></i>Kamera starten
            </button>
            <button type="button" id="btn-switch" class="btn btn-outline-light btn-sm d-none">
                <i class="bi bi-arrow-repeat me-1"></i>Kamera wechseln
            </button>
        </div>
    </div>

    <!-- Fallback-Aktionen -->
    <div class="px-3 mb-3 d-flex gap-2">
        <label for="photo-input" class="es-fallback-btn flex-fill mb-0" style="cursor:pointer;">
            <i class="bi bi-camera fs-5"></i>Foto aufnehmen
        </label>
        <input type="file" id="photo-input" accept="image/*" capture="environment" class="d-none">
        <button type="button" class="es-fallback-btn flex-fill" data-bs-toggle="collapse" data-bs-target="#manualBox">
            <i class="bi bi-keyboard fs-5"></i>Nummer eingeben
        </button>
    </div>

    <!-- Manuelle Eingabe (eingeklappt) -->
    <div class="collapse px-3 mb-3" id="manualBox">
        <div class="card border-0" style="background:#161616;">
            <div class="card-body">
                <form method="POST" action="/api/checkin_gast.php" id="manual-form" class="row g-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="event_id" value="<?= $selectedEventId ?>">
                    <input type="hidden" name="redirect" value="/pages/einlass_scan.php">
                    <div class="col-8">
                        <input type="text" name="buchungsnummer" id="manual-input" class="form-control text-uppercase"
                               placeholder="KARN-2026-XXXXXX" pattern="[Kk][Aa][Rr][Nn]-\d{4}-[0-9A-Fa-f]{6}"
                               autocomplete="off">
                    </div>
                    <div class="col-4 d-grid">
                        <button type="submit" class="btn btn-outline-light">Einchecken</button>
                    </div>
                </form>
                <div class="form-text text-white-50 mt-2">
                    Nur wenn der QR-Code nicht lesbar ist – wird gesondert protokolliert.
                </div>
            </div>
        </div>
    </div>

    <!-- Letzte Scans -->
    <div class="px-3 mb-4">
        <div class="small text-white-50 fw-semibold mb-1">Letzte Scans</div>
        <ul class="list-group list-group-flush rounded-3" id="scan-log">
            <li class="list-group-item text-white-50 small">Noch keine Scans.</li>
        </ul>
    </div>

    <?php endif; // currentEvent ?>

</div>

<?php
$jsCsrf    = json_encode($csrfToken, JSON_UNESCAPED_SLASHES);
$jsEventId = json_encode($selectedEventId);

// Identisch zur Scan-Logik aus kassierer_scan.php (Kamera, Foto-Fallback,
// BarcodeDetector/jsQR, manuelle Eingabe) – nur das Drumherum ist anders.
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

    var audioCtx = null;
    function beep(good) {
        try {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            if (!audioCtx) audioCtx = new AC();
            var ac = audioCtx;
            if (ac.state === 'suspended' && ac.resume) ac.resume();
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
            setTimeout(function () { busy = false; }, 1200);
        });
    }

    function handleCode(text) {
        if (!text) return;
        var now = Date.now();
        if (text === lastCode && now - lastTime < 2500) return;
        lastCode = text; lastTime = now;
        doCheckin(text);
    }

    function stopCam() {
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    }

    function startCam() {
        stopCam();

        if (!window.isSecureContext) {
            status.textContent = 'Live-Scan benötigt eine sichere Verbindung (https://). '
                + 'Diese Seite läuft über http:// – bitte „Foto aufnehmen“ oder die '
                + 'manuelle Eingabe nutzen.';
            btnStart.classList.add('d-none');
            return;
        }

        status.textContent = 'Kamera wird gestartet …';

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            status.textContent = 'Dieser Browser unterstützt keinen Live-Kamerazugriff. '
                + 'Bitte „Foto aufnehmen“ oder die manuelle Eingabe nutzen.';
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
            var name = err && err.name;
            var msg;
            if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
                msg = 'Kamerazugriff wurde abgelehnt. Bitte in den Browser-/Website-'
                    + 'Einstellungen erlauben und die Seite neu laden – oder „Foto '
                    + 'aufnehmen“ nutzen.';
            } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
                msg = 'Keine Kamera gefunden. Bitte „Foto aufnehmen“ oder die manuelle '
                    + 'Eingabe nutzen.';
            } else if (name === 'NotReadableError' || name === 'TrackStartError') {
                msg = 'Die Kamera wird gerade von einer anderen App verwendet. Diese '
                    + 'schließen und erneut versuchen.';
            } else if (name === 'OverconstrainedError') {
                msg = 'Diese Kamera unterstützt die angeforderte Auflösung nicht. '
                    + 'Bitte „Kamera wechseln“ oder „Foto aufnehmen“ versuchen.';
            } else {
                msg = 'Kamera nicht verfügbar (' + (name || 'unbekannter Fehler') + '). '
                    + 'Bitte „Foto aufnehmen“ nutzen.';
            }
            status.textContent = msg;
            btnStart.classList.remove('d-none');
        });
    }

    var photoInput = document.getElementById('photo-input');

    photoInput.addEventListener('change', function () {
        var file = photoInput.files && photoInput.files[0];
        photoInput.value = '';
        if (file) decodePhoto(file);
    });

    function decodePhoto(file) {
        status.textContent = 'Foto wird ausgewertet …';
        loadImageRespectingOrientation(file)
            .then(function (img) {
                var w = img.naturalWidth  || img.width;
                var h = img.naturalHeight || img.height;
                if (!w || !h) throw new Error('Bild ohne Abmessungen');

                var maxDim = 1600;
                var scale  = Math.min(1, maxDim / Math.max(w, h));
                var cw = Math.max(1, Math.round(w * scale));
                var ch = Math.max(1, Math.round(h * scale));

                canvas.width  = cw;
                canvas.height = ch;
                ctx.drawImage(img, 0, 0, cw, ch);

                if (detector) {
                    return detector.detect(canvas)
                        .then(function (codes) { return (codes && codes.length) ? codes[0].rawValue : null; })
                        .catch(function () { return decodeWithJsQR(cw, ch); });
                }
                return decodeWithJsQR(cw, ch);
            })
            .then(function (text) {
                if (text) {
                    handleCode(text);
                } else {
                    status.textContent = 'Kein QR-Code im Foto erkannt. Bitte näher '
                        + 'heran, gut ausleuchten und erneut versuchen – oder manuell '
                        + 'eingeben.';
                }
            })
            .catch(function () {
                status.textContent = 'Foto konnte nicht gelesen werden. Bitte erneut '
                    + 'versuchen.';
            });
    }

    function decodeWithJsQR(w, h) {
        if (!window.jsQR) return null;
        var imgData = ctx.getImageData(0, 0, w, h);
        var code = window.jsQR(imgData.data, w, h, { inversionAttempts: 'attemptBoth' });
        return code ? code.data : null;
    }

    function loadImageRespectingOrientation(file) {
        if (window.createImageBitmap) {
            return createImageBitmap(file, { imageOrientation: 'from-image' })
                .catch(function () { return createImageBitmap(file); })
                .catch(function () { return loadViaImgElement(file); });
        }
        return loadViaImgElement(file);
    }

    function loadViaImgElement(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload  = function () { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('Bild konnte nicht geladen werden')); };
            img.src = url;
        });
    }

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

    var manualForm = document.getElementById('manual-form');
    if (manualForm) {
        manualForm.addEventListener('submit', function (e) {
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
    }

    window.addEventListener('pagehide', stopCam);

    if (video) startCam();
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

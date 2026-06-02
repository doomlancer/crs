<?php
/**
 * QR-Code Scanner – Kassierer/Admin
 * Scannt Ticket-QR-Codes per Kamera und checkt Gäste ein.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('kassierer', 'admin');

$pageTitle = 'QR-Scanner';
$csrfToken = generateCsrfToken();
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="py-4">
    <div class="container" style="max-width: 600px;">
        <?= getFlash() ?>

        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h2 class="fw-bold mb-0">
                    <i class="bi bi-qr-code-scan text-warning me-2"></i>QR-Scanner
                </h2>
                <p class="text-muted mb-0 small">Ticket scannen → automatisch einchecken</p>
            </div>
            <a href="/pages/kassierer_dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Zurück
            </a>
        </div>

        <!-- Ergebnis-Box -->
        <div id="result-box" class="alert d-none mb-3 text-center fw-bold fs-5 rounded" role="alert"></div>

        <!-- Scanner -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div id="reader" class="w-100"></div>
            </div>
        </div>

        <!-- Manuelle Eingabe als Fallback -->
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body">
                <p class="fw-semibold mb-2"><i class="bi bi-keyboard me-1"></i>Buchungsnummer manuell eingeben</p>
                <form id="manualForm" class="d-flex gap-2">
                    <input type="text" id="manualInput" class="form-control"
                           placeholder="KARN-2026-XXXXXX" autocomplete="off" autocorrect="off"
                           autocapitalize="characters" spellcheck="false">
                    <button type="submit" class="btn btn-warning text-nowrap">
                        <i class="bi bi-person-check me-1"></i>Check-in
                    </button>
                </form>
            </div>
        </div>

        <!-- Legende -->
        <div class="mt-3 small text-muted text-center">
            <span class="me-3"><span class="badge bg-success">Grün</span> Erfolgreich eingecheckt</span>
            <span><span class="badge bg-danger">Rot</span> Fehler / bereits eingecheckt</span>
        </div>
    </div>
</main>

<?php
$extraScripts = <<<HTML
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function () {
    var CSRF = <?= json_encode($csrfToken) ?>;
    var lastScanned = '';
    var scanning = true;

    var resultBox = document.getElementById('result-box');

    function showResult(success, message) {
        resultBox.className = 'alert mb-3 text-center fw-bold fs-5 rounded ' +
            (success ? 'alert-success' : 'alert-danger');
        resultBox.textContent = (success ? '✓ ' : '✗ ') + message;
        resultBox.classList.remove('d-none');
    }

    function doCheckin(buchungsnummer) {
        var fd = new FormData();
        fd.append('buchungsnummer', buchungsnummer);
        fd.append('csrf_token', CSRF);

        fetch('/api/checkin_gast.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                showResult(data.success, data.message);
                if (data.success && navigator.vibrate) navigator.vibrate(200);
            })
            .catch(function(err) {
                showResult(false, 'Netzwerkfehler: ' + err.message);
            });
    }

    // QR-Scanner starten
    var scanner = new Html5Qrcode('reader');
    scanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 250, height: 250 } },
        function (qrText) {
            if (!scanning || lastScanned === qrText) return;
            lastScanned = qrText;
            scanning = false;
            doCheckin(qrText);
            setTimeout(function() {
                lastScanned = '';
                scanning = true;
            }, 3000);
        },
        function () { /* scan error – ignorieren */ }
    ).catch(function(err) {
        showResult(false, 'Kamera konnte nicht geöffnet werden: ' + err);
    });

    // Manuelle Eingabe
    document.getElementById('manualForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var val = document.getElementById('manualInput').value.trim().toUpperCase();
        if (!val) return;
        doCheckin(val);
        document.getElementById('manualInput').value = '';
    });
})();
</script>
HTML;
include __DIR__ . '/../includes/footer.php';
?>

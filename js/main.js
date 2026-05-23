/**
 * Kameruner-Tickets – Client-seitige Logik
 */

'use strict';

// =====================
// Initialisierung
// =====================
document.addEventListener('DOMContentLoaded', () => {
    initTooltips();
    initPasswordToggles();
    initAutoFlashDismiss();
    initFormValidation();
    initConfirmDialogs();
    initTableFilter();
});

// =====================
// Bootstrap Tooltips
// =====================
function initTooltips() {
    const tooltipEls = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipEls.forEach(el => new bootstrap.Tooltip(el));
}

// =====================
// Passwort anzeigen/verbergen
// =====================
function initPasswordToggles() {
    document.querySelectorAll('.password-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = document.querySelector(btn.dataset.target);
            if (!target) return;
            const isPassword = target.type === 'password';
            target.type = isPassword ? 'text' : 'password';
            const icon = btn.querySelector('i');
            if (icon) {
                icon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
            }
        });
    });
}

// =====================
// Flash-Nachrichten nach 5s ausblenden
// =====================
function initAutoFlashDismiss() {
    document.querySelectorAll('.alert:not(.alert-permanent)').forEach(alert => {
        setTimeout(() => {
            if (alert && alert.parentNode) {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                bsAlert?.close();
            }
        }, 5000);
    });
}

// =====================
// Formular-Validierung (Bootstrap)
// =====================
function initFormValidation() {
    document.querySelectorAll('form.needs-validation').forEach(form => {
        form.addEventListener('submit', e => {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });
}

// =====================
// Bestätigungs-Dialoge
// =====================
function initConfirmDialogs() {
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', e => {
            const msg = el.dataset.confirm || 'Sind Sie sicher?';
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    });
}

// =====================
// Live-Tabellenfilter
// =====================
function initTableFilter() {
    const searchInput = document.getElementById('tableSearchInput');
    if (!searchInput) return;

    const tableId = searchInput.dataset.table || 'filterTable';
    const table   = document.getElementById(tableId);
    if (!table) return;

    const rows = table.querySelectorAll('tbody tr:not(.no-filter)');

    searchInput.addEventListener('input', () => {
        const query = searchInput.value.toLowerCase().trim();
        let visible = 0;
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const show = !query || text.includes(query);
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        // Leere Tabelle Hinweis
        const emptyRow = table.querySelector('.empty-row');
        if (emptyRow) emptyRow.style.display = visible === 0 ? '' : 'none';
    });
}

// =====================
// Passwort-Stärke-Anzeige
// =====================
function checkPasswordStrength(password, barEl, labelEl) {
    let score = 0;
    let label = '';
    let color = '';

    if (password.length >= 8)  score++;
    if (password.length >= 12) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;

    switch (true) {
        case score <= 1: label = 'Sehr schwach'; color = '#ef4444'; break;
        case score === 2: label = 'Schwach';      color = '#f97316'; break;
        case score === 3: label = 'Mittel';       color = '#eab308'; break;
        case score === 4: label = 'Stark';        color = '#22c55e'; break;
        default:          label = 'Sehr stark';   color = '#16a34a';
    }

    if (barEl) {
        barEl.style.width = (score / 5 * 100) + '%';
        barEl.style.background = color;
    }
    if (labelEl) {
        labelEl.textContent = password ? label : '';
        labelEl.style.color = color;
    }
    return score;
}

// =====================
// Passwort-Match-Prüfung
// =====================
function checkPasswordMatch(pw1El, pw2El, feedbackEl) {
    if (!pw1El || !pw2El) return true;
    const match = pw1El.value === pw2El.value;
    if (pw2El.value) {
        pw2El.classList.toggle('is-invalid', !match);
        pw2El.classList.toggle('is-valid', match);
        if (feedbackEl) feedbackEl.textContent = match ? '' : 'Passwörter stimmen nicht überein.';
    }
    return match;
}

// =====================
// Tischplan Echtzeit-Update (Polling)
// =====================
let tischplanPolling = null;
let lastTimestamp    = 0;

function startTischplanPolling(eventId, intervalMs = 30000) {
    if (tischplanPolling) clearInterval(tischplanPolling);
    tischplanPolling = setInterval(() => updateTischplan(eventId), intervalMs);
}

async function updateTischplan(eventId) {
    try {
        const resp = await fetch(`/api/get_tischplan.php?event_id=${eventId}&ts=${Date.now()}`);
        if (!resp.ok) return;
        const data = await resp.json();

        const payload = data.data ?? data;
        if (payload.timestamp === lastTimestamp) return;
        lastTimestamp = payload.timestamp;

        // Nur Sitze aktualisieren, die sich geändert haben
        payload.tische?.forEach(tisch => {
            tisch.sitze?.forEach(sitz => {
                const btn = document.querySelector(`.seat-btn[data-seat-id="${sitz.id}"]`);
                if (!btn) return;
                const currentClass = [...btn.classList].find(c =>
                    ['verfuegbar','reserviert','besetzt','mein_platz','ausgewaehlt'].includes(c)
                );
                const newClass = sitz.status === 'mein_platz' ? 'mein-platz' : sitz.status;
                if (currentClass === newClass) return;

                // Nicht überschreiben wenn lokal ausgewählt
                if (btn.classList.contains('ausgewaehlt')) return;

                btn.classList.remove('verfuegbar', 'reserviert', 'besetzt', 'mein-platz');
                btn.classList.add(newClass);
                btn.disabled = newClass !== 'verfuegbar';
            });
        });

        // Auslastung aktualisieren
        if (payload.statistik) {
            const el = document.getElementById('auslastungProzent');
            if (el) el.textContent = payload.statistik.prozent + '%';
        }
    } catch (err) {
        console.warn('Tischplan-Update fehlgeschlagen:', err);
    }
}

// =====================
// AJAX: Check-in Button
// =====================
async function ajaxCheckin(reservationId, csrfToken, btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    try {
        const formData = new FormData();
        formData.append('reservation_id', reservationId);
        formData.append('csrf_token', csrfToken);

        const resp = await fetch('/api/checkin_gast.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const data = await resp.json();

        if (data.success) {
            const row = btn.closest('tr');
            if (row) {
                row.classList.add('table-success');
                const statusCell = row.querySelector('.status-cell');
                if (statusCell) {
                    statusCell.innerHTML = '<span class="badge bg-success">Eingecheckt</span>';
                }
            }
            btn.remove();
            showToast('success', data.message);
        } else {
            showToast('error', data.error || 'Fehler beim Check-in');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Check-in';
        }
    } catch (err) {
        showToast('error', 'Verbindungsfehler.');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Check-in';
    }
}

// =====================
// Toast-Nachrichten
// =====================
function showToast(type, message) {
    const container = document.getElementById('toastContainer') || createToastContainer();
    const id = 'toast_' + Date.now();
    const bgClass = type === 'success' ? 'bg-success' : type === 'error' ? 'bg-danger' : 'bg-warning';
    const icon    = type === 'success' ? 'bi-check-circle' : type === 'error' ? 'bi-x-circle' : 'bi-exclamation-triangle';

    const html = `
        <div id="${id}" class="toast align-items-center text-white ${bgClass} border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi ${icon} me-2"></i>${escapeHtml(message)}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>`;

    container.insertAdjacentHTML('beforeend', html);
    const toastEl = document.getElementById(id);
    const toast = new bootstrap.Toast(toastEl, { delay: 4000 });
    toast.show();
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

function createToastContainer() {
    const div = document.createElement('div');
    div.id = 'toastContainer';
    div.className = 'toast-container position-fixed bottom-0 end-0 p-3';
    div.style.zIndex = '9999';
    document.body.appendChild(div);
    return div;
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// =====================
// Hilfsfunktionen
// =====================
function formatBetrag(value) {
    return new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: 'EUR'
    }).format(value);
}

// Chart.js Standard-Konfiguration
if (typeof Chart !== 'undefined') {
    Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
    Chart.defaults.color = '#64748b';
    Chart.defaults.plugins.legend.position = 'bottom';
}

#!/bin/bash
set -e

APP_DIR="/var/www/html"
cd "$APP_DIR"

echo "──────────────────────────────────────────────"
echo " Kameruner-Tickets – Container-Start"
echo "──────────────────────────────────────────────"

# ── 1) msmtp (SMTP-Relay) konfigurieren, falls SMTP_HOST gesetzt ───────────
if [ -n "${SMTP_HOST}" ]; then
    echo "→ Konfiguriere msmtp (SMTP-Relay: ${SMTP_HOST}:${SMTP_PORT:-587})"
    cat > /etc/msmtprc <<EOF
defaults
auth           on
tls            ${SMTP_TLS:-on}
tls_starttls   ${SMTP_STARTTLS:-on}
tls_trust_file /etc/ssl/certs/ca-certificates.crt
logfile        /var/www/html/logs/msmtp.log

account        default
host           ${SMTP_HOST}
port           ${SMTP_PORT:-587}
from           ${SMTP_USER:-noreply@localhost}
user           ${SMTP_USER}
password       ${SMTP_PASS}
EOF
    chmod 600 /etc/msmtprc
    chown www-data:www-data /etc/msmtprc /var/www/html/logs 2>/dev/null || true
else
    echo "→ SMTP_HOST nicht gesetzt – E-Mail-Versand deaktiviert."
fi

# ── 2) Auf die Datenbank warten ────────────────────────────────────────────
DB_HOST="${DB_HOST:-db}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
echo "→ Warte auf Datenbank (${DB_HOST}) ..."
tries=0
until mysqladmin ping -h"${DB_HOST}" -u"${DB_USER}" -p"${DB_PASS}" --silent 2>/dev/null; do
    tries=$((tries+1))
    if [ "$tries" -ge 60 ]; then
        echo "✗ Datenbank nach 60 Versuchen nicht erreichbar – breche ab."
        exit 1
    fi
    sleep 2
done
echo "✓ Datenbank erreichbar."

# ── 3) Migrationen ausführen ───────────────────────────────────────────────
echo "→ Führe Datenbank-Migrationen aus ..."
php "${APP_DIR}/migrate.php" || {
    echo "✗ Migration fehlgeschlagen."; exit 1;
}

# ── 4) Admin-Benutzer anlegen (nur wenn noch keiner existiert) ─────────────
if [ -n "${ADMIN_EMAIL}" ] && [ -n "${ADMIN_PASSWORD}" ]; then
    echo "→ Prüfe Admin-Benutzer ..."
    php "${APP_DIR}/docker/create_admin.php" || echo "  (Admin-Erstellung übersprungen)"
fi

# ── 5) Rechte sicherstellen ────────────────────────────────────────────────
chown -R www-data:www-data "${APP_DIR}/uploads" "${APP_DIR}/logs" 2>/dev/null || true

echo "✓ Bereit. Starte Apache."
echo "──────────────────────────────────────────────"

exec "$@"

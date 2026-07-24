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
# Prüfung über PHP/PDO – exakt die Verbindung, die die App braucht (inkl.
# Zugangsdaten und Datenbankname). Unabhängig von mysqladmin.
echo "→ Warte auf Datenbank (${DB_HOST:-db}) ..."
tries=0
until php -r '
    $h = getenv("DB_HOST") ?: "db";
    $n = getenv("DB_NAME") ?: "crs";
    $u = getenv("DB_USER") ?: "root";
    $p = getenv("DB_PASS") ?: "";
    try { new PDO("mysql:host=$h;dbname=$n;charset=utf8mb4", $u, $p, [PDO::ATTR_TIMEOUT => 3]); exit(0); }
    catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
' 2>/tmp/dbwait.err; do
    tries=$((tries+1))
    if [ "$tries" -ge 90 ]; then
        echo "✗ Datenbank nach 90 Versuchen nicht erreichbar – breche ab."
        echo "  Letzter Fehler: $(cat /tmp/dbwait.err 2>/dev/null)"
        exit 1
    fi
    if [ $((tries % 5)) -eq 0 ]; then
        echo "  … noch nicht bereit (Versuch ${tries}): $(cat /tmp/dbwait.err 2>/dev/null)"
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

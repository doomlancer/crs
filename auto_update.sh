#!/bin/bash
# Kameruner-Tickets – Auto-Update für den Zeitplan (Unraid User Scripts).
# Läuft z. B. alle 5 Minuten und baut den Container NUR neu, wenn es
# tatsächlich neue Commits auf dem Branch gibt. Sonst passiert nichts.
set -e

cd "$(dirname "$0")"

ts() { date '+%Y-%m-%d %H:%M:%S'; }

BRANCH="$(git rev-parse --abbrev-ref HEAD)"

# Neueste Infos vom Remote holen (ohne etwas zu verändern)
git fetch origin "$BRANCH" --quiet

LOCAL="$(git rev-parse HEAD)"
REMOTE="$(git rev-parse "origin/${BRANCH}")"

if [ "$LOCAL" = "$REMOTE" ]; then
    echo "$(ts) Keine Änderungen – nichts zu tun."
    exit 0
fi

echo "$(ts) Neue Commits gefunden ($LOCAL → $REMOTE) – aktualisiere ..."
git pull --ff-only origin "$BRANCH"

echo "$(ts) Baue Image und starte Container neu ..."
docker compose up -d --build

echo "$(ts) Räume alte Images auf ..."
docker image prune -f >/dev/null 2>&1 || true

echo "$(ts) ✓ Update fertig."
docker compose ps

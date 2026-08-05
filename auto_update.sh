#!/bin/bash
# Kameruner-Tickets – Notfallpfad: Update direkt aus dem Git-Quellcode.
#
# WIRD IM NORMALBETRIEB NICHT BENÖTIGT.
# Standardmäßig baut GitHub Actions das Image und Watchtower zieht es
# automatisch – ganz ohne Zutun auf dem Server.
#
# Dieses Skript ist die Rückfallebene, falls die Registry einmal nicht
# erreichbar ist. Es prüft, ob es neue Commits gibt, und baut das Image dann
# lokal aus dem Quellcode. Ohne Änderungen passiert nichts.
#
# Für einen Zeitplan (Unraid „User Scripts"), z. B. alle 5 Minuten:
#   */5 * * * *
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

echo "$(ts) Baue Image lokal und starte Container neu ..."
docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --build

echo "$(ts) Räume alte Images auf ..."
docker image prune -f >/dev/null 2>&1 || true

echo "$(ts) ✓ Update fertig."
docker compose ps

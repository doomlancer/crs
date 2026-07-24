#!/bin/bash
# Kameruner-Tickets – Update-Helfer
# Holt die neuesten Änderungen, baut das Image neu, startet neu und räumt auf.
set -e

cd "$(dirname "$0")"

echo "──────────────────────────────────────────────"
echo " Kameruner-Tickets – Update"
echo "──────────────────────────────────────────────"

echo "→ Hole neueste Änderungen (git pull) ..."
git pull

echo "→ Baue Image und starte Container neu ..."
docker compose up -d --build

echo "→ Räume alte, ungenutzte Images auf ..."
docker image prune -f >/dev/null 2>&1 || true

echo "→ Aktueller Status:"
docker compose ps

echo ""
echo "✓ Fertig. Live-Log ansehen mit:"
echo "    docker compose logs -f app"

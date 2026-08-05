#!/bin/bash
# Kameruner-Tickets – manuelles Update.
#
# Im Normalbetrieb wird dieses Skript NICHT gebraucht: GitHub Actions baut das
# Image bei jedem Push, Watchtower holt es sich automatisch. Das hier ist der
# Weg, wenn du nicht auf den nächsten Watchtower-Durchlauf warten willst.
#
#   ./update.sh           neuestes Image aus der Registry holen (schnell)
#   ./update.sh --local   stattdessen lokal aus dem Quellcode bauen
set -e

cd "$(dirname "$0")"

echo "──────────────────────────────────────────────"
echo " Kameruner-Tickets – Update"
echo "──────────────────────────────────────────────"

if [ "${1:-}" = "--local" ]; then
    echo "→ Hole neuesten Quellcode (git pull) ..."
    git pull --ff-only

    echo "→ Baue Image lokal und starte neu ..."
    docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --build
else
    echo "→ Hole neuestes Image aus der Registry ..."
    docker compose pull app

    echo "→ Starte Container mit der neuen Version ..."
    docker compose up -d
fi

echo "→ Räume alte, ungenutzte Images auf ..."
docker image prune -f >/dev/null 2>&1 || true

echo "→ Aktueller Status:"
docker compose ps

echo ""
echo "✓ Fertig. Live-Log ansehen mit:"
echo "    docker compose logs -f app"

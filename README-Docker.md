# Kameruner-Tickets – Docker / Unraid

Vollständiger Stack aus **App-Container** (PHP 8.2 + Apache) und **MariaDB**.
Schema und Migrationen werden beim ersten Start automatisch eingespielt, ein
Admin-Benutzer wird automatisch angelegt.

---

## Schnellstart (jede Docker-Umgebung)

```bash
cp .env.example .env
nano .env            # Passwörter, APP_URL, Admin-Zugang, GHCR_TOKEN eintragen
docker compose up -d
```

Das App-Image kommt fertig aus der GitHub-Registry — es muss nicht gebaut werden.
Dafür ist einmalig ein Zugangstoken nötig, siehe **Updaten → Einmalige
Einrichtung**. Wer lieber selbst baut, nutzt:
```bash
docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --build
```

Danach im Browser: **http://SERVER-IP:8080** → mit dem in `.env` gesetzten
`ADMIN_EMAIL` / `ADMIN_PASSWORD` anmelden.

---

## Auf Unraid betreiben

Es gibt zwei Wege. **Weg A** (Compose) ist am einfachsten, weil er beide
Container zusammen verwaltet.

### Weg A – Compose Manager Plugin (empfohlen)

1. In den **Apps** (Community Applications) das Plugin **„Compose Manager"** installieren.
2. **Add New Stack** → Name z. B. `kameruner-tickets`.
3. Den Inhalt von `docker-compose.yml` einfügen.
4. Über **„Edit .env"** (oder eine Datei `.env` neben dem Stack) die Werte aus
   `.env.example` eintragen — besonders:
   - `DB_PASS`, `DB_ROOT_PASS` – sichere Passwörter
   - `APP_URL` – die Adresse, unter der die App später läuft
   - `ADMIN_EMAIL`, `ADMIN_PASSWORD` – dein erster Login
   - `FORCE_HTTPS` – siehe unten
5. **Compose Up**. Beim ersten Start baut Unraid das Image und startet beide Container.

Das App-Image wird aus der GitHub-Registry geladen — der Quellcode muss dafür
**nicht** auf dem Server liegen. Wer trotzdem lokal bauen möchte (Reserve-Weg,
siehe `docker-compose.build.yml`), klont das Repository z. B. nach
`/mnt/user/appdata/kameruner-tickets`.

### Weg B – Zwei einzelne Container (ohne Compose)

Wenn du kein Compose nutzen willst:

1. **MariaDB-Container** anlegen (offizielles `mariadb:10.11` oder der
   `MariaDB`-Eintrag aus den Community Apps):
   - `MARIADB_DATABASE=crs`, `MARIADB_USER=crs`, `MARIADB_PASSWORD=…`, `MARIADB_ROOT_PASSWORD=…`
   - Datenpfad z. B. `/mnt/user/appdata/kameruner-db` → `/var/lib/mysql`
2. **App-Container** aus diesem Image (vorher `docker build -t kameruner-tickets .`):
   - Port `8080` → `80`
   - `DB_HOST` = Name/IP des MariaDB-Containers, plus `DB_NAME/DB_USER/DB_PASS`
   - restliche Variablen aus `.env.example`
   - Volumes: `…/uploads` → `/var/www/html/uploads`, `…/logs` → `/var/www/html/logs`

---

## Wichtige Einstellungen

### HTTPS / Reverse-Proxy (`FORCE_HTTPS`)

Der Stack unterstützt **beide** Betriebsarten:

| Szenario | `FORCE_HTTPS` | `APP_URL` |
|---|---|---|
| **Nur lokal** (`http://server:8080`) | `false` | `http://SERVER-IP:8080` |
| **Hinter Reverse-Proxy** (SWAG / Nginx Proxy Manager / Cloudflare) | `true` | `https://tickets.deine-domain.de` |

Bei `true` erkennt die App automatisch den `X-Forwarded-Proto`-Header des
Proxys – es entsteht **keine Redirect-Schleife**. Der Container selbst liefert
immer HTTP auf Port 80; TLS macht der Proxy.

> Beim Nginx Proxy Manager: „Websockets Support" nicht nötig, aber unter
> *Advanced* sicherstellen, dass `X-Forwarded-Proto $scheme` weitergegeben wird
> (Standard bei NPM/SWAG).

### E-Mail (optional)

Ohne SMTP-Daten funktioniert alles außer dem Mailversand
(Buchungsbestätigung, Passwort-Reset, Warteliste). Zum Aktivieren in `.env`:

```
SMTP_HOST=smtp.deinprovider.de
SMTP_PORT=587
SMTP_USER=Kassierer@die-kameruner.de
SMTP_PASS=dein-passwort
SMTP_STARTTLS=on
```

Der Container richtet damit automatisch `msmtp` ein und leitet PHP-`mail()`
über diesen Server.

### Persistente Daten

Drei Volumes sichern alles Wichtige über Neustarts/Updates hinweg:

- `db_data` – die Datenbank
- `app_uploads` – hochgeladene Logos/Favicons
- `app_logs` – Fehler- und Mail-Logs

**Backup:** diese drei Volumes bzw. appdata-Pfade sichern.

---

## Updaten – läuft automatisch

Der Normalfall braucht **keinen einzigen Befehl auf dem Server**:

```
Änderung wird nach GitHub gepusht
        ↓
GitHub Actions baut das Image        (.github/workflows/docker-publish.yml)
        ↓
Image liegt in ghcr.io/doomlancer/crs:latest
        ↓
Watchtower auf dem Server holt es    (Standard: alle 5 Minuten)
        ↓
Container startet neu, Migrationen laufen automatisch
        ↓
Änderung ist live
```

Neue Migrationen laufen bei jedem Start automatisch; bereits eingespielte werden
übersprungen (Tracking-Tabelle `migrations`).

### Einmalige Einrichtung

Weil das Image **privat** ist, braucht der Server einmalig Zugangsdaten.

**1. GitHub-Token anlegen**
GitHub → *Settings* → *Developer settings* → *Personal access tokens (classic)* →
*Generate new token*. Als Berechtigung genügt **`read:packages`**.
Den Token in die `.env` eintragen:
```ini
GHCR_USER=doomlancer
GHCR_TOKEN=ghp_dein_token
```

**2. Einmalig auf dem Server anmelden**
```bash
echo "ghp_dein_token" | docker login ghcr.io -u doomlancer --password-stdin
```

**3. Paket auf privat stellen**
Das Repository ist öffentlich, daher wird das Image beim ersten Push ebenfalls
öffentlich veröffentlicht. Nach dem ersten erfolgreichen Workflow-Lauf hier
umstellen:
`github.com/users/doomlancer/packages/container/crs/settings` → *Change visibility*
→ **Private**.

**4. Stack einmal neu starten**, damit Watchtower dazukommt:
```bash
cd /mnt/user/appdata/kameruner-tickets/crs
git pull
docker compose up -d
```

Ab jetzt läuft alles von selbst.

### Kontrollieren

```bash
docker logs -f kameruner-tickets-watchtower    # sieht Watchtower neue Versionen?
docker compose ps                              # laufen alle drei Container?
```

### Sofort aktualisieren (ohne auf Watchtower zu warten)

```bash
./update.sh            # neuestes Image holen und starten
./update.sh --local    # stattdessen lokal aus dem Quellcode bauen
```

### An Veranstaltungstagen

Ein Update startet den Container neu — das würde den Einlass kurz unterbrechen.
Zwei Möglichkeiten:

```bash
docker stop kameruner-tickets-watchtower       # Updates pausieren
docker start kameruner-tickets-watchtower      # danach wieder aktivieren
```

Oder dauerhaft auf nächtliche Updates umstellen (in `.env`):
```ini
WATCHTOWER_SCHEDULE=0 0 4 * * *    # täglich 4 Uhr statt alle 5 Minuten
```

### Auf eine ältere Version zurück

Jeder Build wird zusätzlich mit dem Commit-Kürzel getaggt. Zum Zurückrollen in
der `.env` das Image festnageln und neu starten:
```ini
APP_IMAGE=ghcr.io/doomlancer/crs:sha-abc1234
```
```bash
docker compose up -d
```
Die verfügbaren Tags stehen auf GitHub unter *Packages*.

> **Hinweis:** Watchtower aktualisiert ausschließlich den App-Container – er
> trägt dafür das Label `com.centurylinklabs.watchtower.enable`. Die Datenbank
> und alle übrigen Container auf deinem Unraid werden nicht angefasst.

---

## Fehlersuche

```bash
docker compose logs -f app     # App-/Startlog (inkl. Migrationen)
docker compose logs -f db      # Datenbank
docker compose exec app php migrate.php --status   # Migrations-Status
```

- **Login klappt nicht über HTTP:** `FORCE_HTTPS=false` setzen (sonst wird das
  Session-Cookie nur über HTTPS gesendet).
- **Redirect-Schleife hinter Proxy:** `FORCE_HTTPS=true` **und** sicherstellen,
  dass der Proxy `X-Forwarded-Proto: https` sendet.
- **„Datenbank nicht erreichbar":** `DB_HOST` muss auf den DB-Container zeigen
  (bei Compose: `db`).

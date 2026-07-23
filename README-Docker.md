# Kameruner-Tickets – Docker / Unraid

Vollständiger Stack aus **App-Container** (PHP 8.2 + Apache) und **MariaDB**.
Schema und Migrationen werden beim ersten Start automatisch eingespielt, ein
Admin-Benutzer wird automatisch angelegt.

---

## Schnellstart (jede Docker-Umgebung)

```bash
cp .env.example .env
nano .env            # Passwörter, APP_URL, Admin-Zugang eintragen
docker compose up -d --build
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

Der Code muss dafür auf dem Server liegen (z. B. per `git clone` in einen
Share wie `/mnt/user/appdata/kameruner-tickets`), da das Image lokal gebaut wird.

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

## Updaten

```bash
git pull
docker compose up -d --build
```

Neue Migrationen laufen beim Start automatisch; bereits eingespielte werden
übersprungen (Tracking-Tabelle `migrations`).

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

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

CRS (Card Reservating System, branded "Kameruner-Tickets") is a German-language PHP web application for Karneval (carnival) event ticketing: event creation, seating plans, free-seating ticket sales, per-person QR tickets, door check-in, payment tracking, waitlist and role-based dashboards. Typical event: 10–12 tables with 18–28 seats each (200–250 guests).

## Deployment

**Primary: Docker on Unraid** (see `README-Docker.md`).
- `Dockerfile` (php:8.2-apache, pdo_mysql, msmtp) + `docker-compose.yml` (app, MariaDB 10.11, Watchtower).
- Every push to `main` builds `ghcr.io/doomlancer/crs:latest` via `.github/workflows/docker-publish.yml`; Watchtower on the server pulls it automatically. **Pushing to `main` deploys to production.**
- `docker/entrypoint.sh` waits for the DB, runs `php migrate.php`, creates the first admin (`docker/create_admin.php`) and starts Apache.
- Timezone is `Europe/Berlin` (PHP ini, `TZ` env, and `config.php` sets the MariaDB session `time_zone` to match).

**Alternative: classic hosting (Plesk/FTP)**: copy `.env.example` → `.env`, import `setup_plesk.sql`, upload files, run `/migrate_web.php` once as admin, then delete it. `uploads/` and `logs/` must be writable.

No Composer, no `vendor/`. Everything is plain PHP: QR codes are generated in `includes/qrcode.php` (pure PHP, SVG and PNG), mail goes through msmtp in Docker: either `SMTP_HOST` in `.env` (entrypoint writes `/etc/msmtprc`, PHP `mail()`) or the SMTP access entered under Design & Einstellungen (stored in `GEHEIM_DIR`, msmtp called via `proc_open` so the server's error is shown); classic hosting uses the server's `mail()`.

### Frontend (optional)
```
npm install && npm run build   # outputs to dist/
```
The app works without a build — `header.php`/`footer.php` fall back to `css/style.css` and `js/main.js`. Bootstrap, icons, Chart.js and jsQR are served locally from `assets/vendor/`.

## Architecture

```
index.php           ← landing page (no router; every page is called directly)
config.php          ← loads .env/env vars, timezone, session + CSP, PDO singleton getDB(),
                      constants (TICKET_PREIS, MAX_PLAETZE_PRO_TISCH, SESSION_TIMEOUT,
                      EINLASS_SESSION_TIMEOUT), ticket signing secret
functions.php       ← shared helpers: CSRF, auth (currentIdentity/currentEinlassIdentity,
                      hasRole/requireRole, wantsJson), i18n __(), check-in logic,
                      live grid, ticket links, waitlist notification, Einlass scope guard
includes/
  auth.php          ← loginUser(), loginEinlass(), logout*, registerUser()
  header.php/footer.php/navbar.php
  mailer.php        ← sendMail() + transport choice (mailWeg()), SMTP settings, test mail;
                      confirmation mail embeds one QR PNG per ticket (cid:)
  qrcode.php        ← pure-PHP QR encoder (versions 1–9, level M), SVG + PNG output
  ticket_karte.php  ← ticket card + payment hint rendering
pages/              ← one file per view, accessed directly (/pages/xyz.php)
api/                ← AJAX/form endpoints; JSON shape {success, message, data}
lang/de.php, en.php ← translations (guest pages only; staff/admin pages are German)
migrations/         ← versioned SQL files, applied by migrate.php (keep them re-runnable)
```

### Roles

`users.rolle`: `user`, `kassierer`, `admin`. Plus the pseudo-role **`einlass`**: short-code login for door helpers without an account (`einlass_zugaenge`, codes bound to one event, managed in admin_events.php → "Tische verwalten"). Einlass sessions are restricted to `pages/einlass_scan.php` and the APIs it needs (guard at the end of `functions.php`).

Use `requireRole('admin')` / `requireRole('kassierer', 'admin')` at the top of restricted pages. For fetch()-based APIs, `requireLogin()`/`requireRole()` answer with JSON 401/403 when the request wants JSON.

### Check-in

Single code path: `checkinReservation()` / `checkinByPayload()` in `functions.php`, called via `api/checkin_gast.php` from the scanners, guest list and dashboards. QR payload: `KARN-YYYY-XXXXXX.<16-hex HMAC>`. Undo: `api/checkin_undo.php`. Pay at the door: `api/update_payment.php`.

### Tickets

One reservation = one seat = one person = one QR code. Guests get their tickets by mail (inline PNGs) and via a signed link without login (`pages/ticket.php`, see `ticketLinkUrl()`/`ticketEinzelLinkUrl()`), and under "Meine Tickets" (`pages/meine_reservierungen.php`).

### Key tables

| Table | Purpose |
|---|---|
| `users` | Accounts; `rolle`, `zahlungsart` (`bar`/`ueberweisung`; `paypal` only in old data) |
| `events` | Events; `event_typ` (`tischplan`/`freie_tickets`), `preis`, `max_gaeste`, `status` (`planung`/`aktiv`/`abgerechnet`) |
| `tables`, `seats` | Seating per event; seat status `verfuegbar`/`reserviert`/`besetzt`; `pos_x`/`pos_y` for the floor-plan editor |
| `reservations` | One per seat/ticket; status `geplant`/`eingecheckt`/`abgerechnet`/`storniert` |
| `payments` | Payment per reservation (`offen`/`bezahlt`/`storniert`) |
| `waitlist` | Waitlist; `benachrichtigt_am` marks who was already notified |
| `einlass_zugaenge` | Door helper short codes |
| `settings` | Design settings and bank details (`bank_*`) |
| `translation_overrides`, `rate_limits`, `password_resets`, `audit_log`, `migrations` | as named |

### Migrations

Add files to `migrations/` numbered sequentially (`016_…sql`). Write them re-runnable (`IF NOT EXISTS` etc.). `php migrate.php` applies pending ones, `php migrate.php --status` lists them.

## Security patterns

- PDO prepared statements everywhere; output escaped with `htmlspecialchars`/`sanitize()`.
- CSRF tokens on all forms and POST APIs.
- bcrypt passwords; login rate-limited (5 failures → 15 min lock, plus per-IP limit); Einlass codes rate-limited per IP.
- Sessions regenerate on login; role and account status re-checked against the DB on every request.
- CSP with nonces (set in `config.php`); `.htaccess` blocks dot-paths, `.sql/.log/.env/...` and PHP in `uploads/`.
- Ticket QR codes and ticket links are HMAC-signed with `TICKET_SECRET` (from `.env`, else a file in `GEHEIM_DIR` = `/var/www/crs-secrets`, volume `app_secrets`; renewable in Design & Einstellungen). Secrets are written with `geheimDateiSchreiben()` (0600, atomic).

## CI

`.github/workflows/docker-publish.yml` builds and publishes the Docker image on every push to `main`.

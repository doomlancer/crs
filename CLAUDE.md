# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

CRS (Card Reservating System) is a German-language web application for managing Karneval (Carnival) event seat reservations. It handles event creation, seating plans, guest check-in, payment tracking, and role-based dashboards.

## Setup

### PHP / Apache
1. Copy `.env.example` to `.env` and fill in `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, and SMTP settings.
2. Run `composer install` to install PHP dependencies (phpdotenv, PHPMailer, endroid/qr-code).
3. Run migrations: `php migrate.php`
4. Deploy to Apache with PHP 7.4+. The `.htaccess` at the root configures routing, security headers, and CSP.
5. Ensure `uploads/` and `logs/` are writable by the web server.

### Frontend (optional, for minified assets)
```
npm install
npm run build     # outputs to dist/
npm run dev       # watch mode
```
The app works without a build — `header.php` and `footer.php` fall back to unminified `css/style.css` and `js/main.js` if `dist/` doesn't exist.

## Architecture

```
index.php           ← landing page / router
config.php          ← loads .env, PDO singleton (getDB()), app constants
functions.php       ← shared helpers: sanitize, CSRF, auth checks,
                      i18n __(), email wrappers, validation
includes/
  auth.php          ← loginUser(), logoutUser(), registerUser()
  header.php        ← HTML <head>; uses dist/ if built, else css/style.css
  footer.php        ← Bootstrap JS, Chart.js, main.js (or dist/js/main.js)
  navbar.php        ← role-aware navigation + DE/EN language switcher
  mailer.php        ← PHPMailer wrappers (password reset, reservation, etc.)
  qrcode.php        ← QR-code generation (endroid/qr-code)
pages/              ← one file per view (included by index.php via ?page=)
api/                ← AJAX/form endpoints; all return {success, message, data}
lang/
  de.php            ← German translations
  en.php            ← English translations
migrations/         ← versioned SQL files; run via migrate.php
src/entry.js        ← Vite entry point (imports css/style.css + js/main.js)
dist/               ← built assets (git-ignored); created by npm run build
```

### Request flow

`index.php` reads `?page=` and includes the matching file from `pages/`. API calls hit `api/` directly. There is no front controller beyond this routing.

### Role system

Three roles enforced server-side via `$_SESSION['rolle']`:
- `user` — browse events, reserve seats, join waitlist, view own bookings
- `kassierer` — check-in guests, update payment status, export guest lists
- `admin` — full access including event/user/table management and audit log

Use `requireRole('admin')` / `requireRole('kassierer')` from `functions.php` at the top of any restricted page.

### API response format

All `api/` endpoints return a unified JSON structure:
```json
{"success": true|false, "message": "...", "data": {...}|null}
```

### Database key tables

| Table | Purpose |
|---|---|
| `users` | Accounts; `rolle` column holds the role string |
| `events` | Carnival events |
| `tables` | Seating tables per event |
| `seats` | Individual seats; status: `available` / `reserviert` / `besetzt` |
| `reservations` | Bookings; booking numbers in `KARN-YYYY-XXXXXX` format |
| `payments` | Payment records per reservation |
| `waitlist` | Waitlist entries; user auto-notified when a seat frees up |
| `password_resets` | Token-based password reset (SHA-256 hashed, 1h TTL) |
| `audit_log` | Admin-visible change history |
| `migrations` | Tracks which SQL migration files have been applied |

### Migrations

Add new SQL files to `migrations/` numbered sequentially (`004_…sql`). Run `php migrate.php` to apply pending ones. `php migrate.php --status` shows which are done.

## i18n

Translations live in `lang/de.php` and `lang/en.php` as flat key→string arrays. Use `__('key')` everywhere in views. The language switcher in `navbar.php` calls `api/set_lang.php?lang=de|en` and stores the choice in session.

## Security Patterns

- All DB queries use PDO prepared statements via `getDB()`.
- User output is sanitized with `sanitize()` before display.
- Forms use CSRF tokens (`generateCsrfToken()` / `validateCsrfToken()`).
- Passwords are bcrypt (cost 12) via `password_hash` / `password_verify`.
- Login is rate-limited: 5 failed attempts triggers a 15-minute lockout.
- Sessions regenerate ID on privilege changes (fixation prevention).
- `.htaccess` blocks direct access to `.sql`, `.log`, `.env`, `.ini` and PHP execution inside `uploads/`.
- Content-Security-Policy restricts scripts/styles to `self` + Bootstrap CDN + Google Fonts.
- Password reset tokens are stored as SHA-256 hashes; raw tokens are only ever sent by email.

## Email

Configure SMTP in `.env` (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`). Emails are sent on: registration, reservation confirmation, cancellation, waitlist notification, password reset. All mail functions fall back to PHP `mail()` if `vendor/` is not present.

## CI

`.github/workflows/claude-code-review.yml` runs an automated Claude review on pull requests.

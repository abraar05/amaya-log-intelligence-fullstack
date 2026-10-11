# Amaya Log Intelligence — Full Stack

Operational reporting portal for multi-department device and IMEI data. PHP 8.1+ and MySQL, built for standard shared hosting.

## Features

- **Authentication** — `password_hash()` credentials, per-account lockout after 5 failures, idle session timeout, CSRF protection on every mutating request
- **Role-based access** — `admin` / `analyst` / `viewer`
- **CSV ingestion** — delimiter auto-detection (`, ; tab |`), BOM and CRLF handling, header auto-mapping across ~50 column aliases, IMEIs preserved as strings so leading zeroes survive
- **IMEI reconciliation** — matched / unmatched / duplicate, with duplicates reported separately so they never deflate the match rate
- **Data quality** — explainable scoring (100 − 6 per issue category), disclosed weighting
- **Google Sheets sync** — CSV export; private sheets via a service-account OAuth token
- **Audit log** — every import, sync, and scan recorded with actor and result

## Requirements

PHP 8.1+ with `pdo`, `json`, `mbstring`, `session`; MySQL 5.7+/MariaDB 10.3+.

## Deploy

1. Upload the repository contents to your web root.
2. Copy `config.example.php` → `public/config.php` and fill in the database credentials. On hosts that inject secrets, set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` instead and leave the file alone.
3. Install the schema — open `/install.php` in a browser, or run:
   ```
   php bin/console.php migrate
   ```
4. Create the first account:
   ```
   php bin/console.php user:create yourname admin
   ```
5. **Delete `public/install.php`.**
6. Confirm the site is served over HTTPS (secure cookies require it).
7. Sign in at `/login.php`.

## Configuration

Everything in `public/config.php` can be overridden by environment variables, which is the safer route on shared hosts that expose secrets in the control panel:

| Variable | Purpose |
|---|---|
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASS` | Database connection |
| `DB_DRIVER=sqlite` | Run against a local SQLite file instead of MySQL |
| `APP_BASE_URL` | Set when the app is not at the document root |
| `APP_DEBUG=true` | Verbose errors — never in production |
| `SESSION_LIFETIME` | Idle timeout in seconds |
| `MAX_LOGIN_ATTEMPTS` `LOCKOUT_SECONDS` | Brute-force thresholds |

## Google Sheets

Public sheets need nothing extra. For private sheets:

1. Create a Google Cloud service account and download its JSON key.
2. Share each private sheet with the service account's `client…@…iam.gserviceaccount.com` address, granting Viewer.
3. Mint an access token and put it in `config.php` under `google.access_token` (or inject it as an environment variable). Tokens expire — for unattended jobs, refresh them from the key file.

```
php bin/console.php preload:sheets   # import every readable registered source
```

Two of the three registered spreadsheets are currently private and are seeded with `last_status = 'private'`.

## Console

```
migrate              create/refresh tables and seed baseline data
user:create <user> [role]
user:list
preload:sheets       import every readable Google Sheets source
preload:links        refresh the system link registry
reconcile            recompute and persist match statuses
stats                row counts
```

## Tests

```
php tests/run.php
```

133 assertions covering IMEI normalisation, CSV parsing, header mapping, reconciliation math, quality scoring, auth, and the database layer. They run against SQLite, so no database server is needed. An end-to-end HTTP suite (`tests/http.sh`) additionally exercises login, CSRF rejection, RBAC, and CSV upload against PHP's built-in server.

## Notes on correctness

- IMEIs are never cast to integers — a 15-digit value with leading zeroes round-trips through the database unchanged.
- The match rate counts only identifiers seen exactly once; duplicates are counted and displayed separately.
- A rejected request (bad CSRF token, insufficient role) terminates immediately and performs no side effect.
- Lockout counters are evaluated in PHP rather than SQL `CASE`, because MySQL and SQLite disagree on whether such an expression sees pre- or post-update values.

## Licence

Internal operational tooling for Amaya Distribution.
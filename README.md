# BaseBase — Multivendor Shop with Dropshipping
Site: https://www.basebase.ru

## Connection map
GitHub (gsmhero/basebase, `main`) → Actions `deploy.yml` → SSH rsync (or FTP via manual run) → `DEPLOY_PATH` on host.
Secrets are rendered into `.env` at deploy time; the app reads them in `src/bootstrap.php`.
`/health.php` returns JSON with DB and SMTP reachability.

Secrets: DB_HOST DB_NAME DB_USER DB_PASS · FTP_HOST FTP_USER FTP_PASS FTP_PATH · SSH_HOST SSH_PORT SSH_USER SSH_PASS · DEPLOY_PATH SITE_URL · SMTP_HOST SMTP_PORT SMTP_USER SMTP_PASS SMTP_FROM

## Setup
1. Import `database/schema.sql` into the DB once (phpMyAdmin).
2. Point the web root of basebase.ru at `DEPLOY_PATH/public` (otherwise the health check falls back to `/public/health.php`).
3. Push to `main` to deploy.

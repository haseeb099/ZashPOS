# Release, update, and rollback

## Release a ZashDine module build

1. Checkout the intended host revision (Phase 5 baseline: `a08b5c589…` or later).
2. `php artisan zash-dine:package --no-interaction`
3. Record **SHA-256**, file size, `nexopos.version`, capabilities, and git SHA in the deployment manifest / release notes.
4. Store the ZIP outside Git (object storage or signed download). Do not commit `storage/app/zashdine-packages`.

## Update an existing client / staging host

1. On SQLite hosts: `php artisan zashpos:backup --label=pre-update`. On MySQL/Postgres: take a real `mysqldump`/`pg_dump` (zashpos:backup refuses non-SQLite).
2. Upload the new ZIP via Dashboard → Modules → Upload (or replace `modules/ZashDine` only when intentionally bypassing Modules UI).
3. `php artisan modules:migrate ZashDine`
4. `php artisan optimize:clear` (and rebuild front-end assets if the **host** changed).
5. Smoke: `/up`, login, Floor, Kitchen, one dine-in pay path, receipt HTML. Confirm `config('broadcasting.default')` is `reverb` when Reverb is required.

## Host (core) update

1. Backup DB + `storage/app` + note current git SHA.
2. Deploy new ZashPOS revision; `composer install --no-dev -o`; `npm ci && npm run build`.
3. `php artisan migrate --force` then module migrations.
4. Confirm `config('nexopos.capabilities')` still satisfies ZashDine `requires-capabilities`.
5. Restart queue workers, scheduler host, and Reverb.

## Rollback

| Layer | Action |
| --- | --- |
| Module only | Re-upload previous ZIP (known SHA-256) or restore `modules/ZashDine` from pre-update backup; re-run module migrations if needed |
| Data | SQLite: `php artisan zashpos:restore {pre-update.zip} --force` on a **stopped** app. MySQL/Postgres: restore the host SQL dump (not zashpos:restore). |
| Host code | `git checkout` / redeploy previous release tag; `composer install`; restart workers |

Never force-push client hosts. Prefer forward fixes when migrations are irreversible; keep pre-update backups until the new build is verified.

## Staging vs live

Local staging (`docs/zashpos/DEPLOYMENT.md`) proves ZIP install + backup restore. A live client deploy still requires provisioned SSL, DNS, and operator approval — out of scope until explicitly requested.

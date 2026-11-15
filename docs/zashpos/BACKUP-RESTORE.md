# Backup and restore

## Supported scope (fail-closed)

`php artisan zashpos:backup` / `zashpos:restore` support **SQLite only**.

| Driver | Behaviour |
| --- | --- |
| `sqlite` | Snapshot via `PRAGMA wal_checkpoint(FULL)` + `VACUUM INTO` (includes committed WAL data), plus a `storage/app` mirror |
| `mysql` / `pgsql` / other | **Rejected** — command exits non-zero. Use host `mysqldump` / `pg_dump` (examples below). Never invents a placeholder SQL dump. |

Archives never include `.env`. Restore rejects archives that contain `.env`, placeholder `sql_dump`, or lack `database.sqlite` + `manifest.json` **before** modifying restore targets. After extract, restore opens the snapshot and requires `PRAGMA integrity_check = ok` (rejects truncated/corrupt DB files that still carry a valid SQLite header). `File::copyDirectory` failures abort backup/restore (non-zero exit; incomplete ZIP is discarded).

## What is backed up

`php artisan zashpos:backup` writes `storage/app/zashpos-backups/zashpos-backup-{stamp}[-label].zip` plus a sibling `.sha256` file.

| Included | Excluded |
| --- | --- |
| SQLite snapshot (`database.sqlite`) | **`.env` and all secrets** |
| Mirror of `storage/app` (except nested `zashpos-backups`) | `vendor/`, `node_modules/` |
| `manifest.json` (`db_connection=sqlite`, `sqlite_snapshot=vacuum-into`) | Incomplete archives (missing DB → hard fail) |

Options:

```powershell
php artisan zashpos:backup --path=storage/app/zashpos-backups --label=pre-update
# Tests / proofs can isolate the storage mirror:
php artisan zashpos:backup --storage-app=path\to\storage-app-subset
```

## MySQL / MariaDB (actual production/staging engine)

`zashpos:backup` **rejects** non-SQLite drivers. On MySQL/MariaDB hosts use a real dump:

```bash
export MYSQL_PWD='…'   # from secret store — never commit
mysqldump --single-transaction --routines --triggers \
  -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" "$DB_DATABASE" \
  > "/var/backups/zashpos/db-$(date +%Y%m%d%H%M%S).sql"
unset MYSQL_PWD
# Also archive storage/app (uploads) separately — dumps do not include files.
```

Restore into an **isolated** schema (never overwrite the live schema for a drill):

```bash
mysql -e "CREATE DATABASE zashpos_restore_drill CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql zashpos_restore_drill < /var/backups/zashpos/db-….sql
# Copy storage/app mirror into a disposable directory; assert source files unchanged.
```

Automated proof script (requires `mysqldump`/`mysql` + env vars; writes gitignored evidence):

```bash
# On the staging host or a workstation with DB access:
export MYSQL_HOST=… MYSQL_USER=… MYSQL_PASSWORD=… 
export MYSQL_SOURCE_DATABASE=zashpos_staging
export MYSQL_TARGET_DATABASE=zashpos_restore_drill
./scripts/proof-mysql-backup-restore.sh
```

Evidence: `storage/logs/phase5-mysql-backup-restore-proof.json`. Asserts restored proof **row** + uploaded **file** bytes; source DB row count and source marker file unchanged.

### Secret / APP_KEY recovery (document only — no secrets in Git)

| Item | Where it lives | Recovery |
| --- | --- | --- |
| `APP_KEY` | Server `.env` only (never in backup ZIP/SQL by design) | Restore from the ops secret manager / sealed backup of `.env`. If lost: `php artisan key:generate` **invalidates** encrypted data and all sessions — treat as incident. |
| DB password | Secret manager + DB user | Rotate DB user; update `.env`; restart php-fpm/queue/reverb |
| Reverb app secret | `.env` `REVERB_APP_*` | Rotate; rebuild front-end if `VITE_REVERB_*` baked; restart Reverb |
| Admin password | User table (hashed) | Password reset flow or `php artisan tinker` on the host — never default `admin`/`password` on staging |

SQL dumps and `zashpos` archives must **not** be treated as a substitute for `.env` recovery.

## SQLite restore into a disposable instance

```powershell
php artisan zashpos:restore path\to\zashpos-backup-….zip --force `
  --database=D:\path\disposable\database\restored.sqlite `
  --storage-app=D:\path\disposable\storage\app
```

`--force` is required. The command validates the ZIP first (including `PRAGMA integrity_check`), then writes only the given `--database` / `--storage-app` destinations (defaults: configured SQLite + `storage/app`). It never writes `.env`.

Automated SQLite proof (source hashes unchanged; restored probe bytes + SQLite tables asserted):

```powershell
.\scripts\prove-backup-restore.ps1
```

Evidence: `storage/logs/phase5-backup-restore-proof.json`.

## Operational cadence

- Daily DB dump + weekly full archive before module ZIP updates
- Keep at least one restore drill per release candidate (disposable instance, not the live client)
- Rotate backup encryption keys / disk ACLs on the host — not stored in this repository

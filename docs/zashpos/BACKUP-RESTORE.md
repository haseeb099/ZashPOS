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

Production MySQL (separate from `zashpos:backup`):

```bash
mysqldump --single-transaction --routines -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
  > "/var/backups/zashpos/db-$(date +%Y%m%d%H%M%S).sql"
```

## Restore into a disposable instance

```powershell
php artisan zashpos:restore path\to\zashpos-backup-….zip --force `
  --database=D:\path\disposable\database\restored.sqlite `
  --storage-app=D:\path\disposable\storage\app
```

`--force` is required. The command validates the ZIP first, then writes only the given `--database` / `--storage-app` destinations (defaults: configured SQLite + `storage/app`). It never writes `.env`.

Automated proof (source hashes unchanged; restored probe bytes + SQLite tables asserted):

```powershell
.\scripts\prove-backup-restore.ps1
```

Evidence: `storage/logs/phase5-backup-restore-proof.json`.

## Operational cadence

- Daily DB dump + weekly full archive before module ZIP updates
- Keep at least one restore drill per release candidate (disposable instance, not the live client)
- Rotate backup encryption keys / disk ACLs on the host — not stored in this repository

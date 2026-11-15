# Backup and restore

## What is backed up

`php artisan zashpos:backup` writes `storage/app/zashpos-backups/zashpos-backup-{stamp}[-label].zip` plus a sibling `.sha256` file.

| Included | Excluded |
| --- | --- |
| SQLite file (when `DB_CONNECTION=sqlite`) | **`.env` and all secrets** |
| Mirror of `storage/app` (except nested `zashpos-backups`) | `vendor/`, `node_modules/` |
| `manifest.json` (host revision, core version, connection) | Live MySQL dump bytes on non-SQLite (placeholder only — use `mysqldump`) |

Production MySQL example (run on the server; keep dumps off Git):

```bash
mysqldump --single-transaction --routines -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
  > "/var/backups/zashpos/db-$(date +%Y%m%d%H%M%S).sql"
```

## Restore into a disposable instance

```powershell
# On the disposable tree (own .env + vendor already installed):
php artisan zashpos:restore path\to\zashpos-backup-….zip --force --database=database/restored.sqlite
```

`--force` is required. The command never writes `.env`. Point `DB_DATABASE` at the restored SQLite (or import SQL) and run `php artisan config:clear`.

Automated proof script:

```powershell
.\scripts\prove-backup-restore.ps1
```

Evidence: `storage/logs/phase5-backup-restore-proof.json`.

## Operational cadence

- Daily DB dump + weekly full archive before module ZIP updates
- Keep at least one restore drill per release candidate (disposable instance, not the live client)
- Rotate backup encryption keys / disk ACLs on the host — not stored in this repository

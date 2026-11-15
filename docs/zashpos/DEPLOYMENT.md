# ZashPOS / ZashDine deployment readiness (Phase 5)

This document prepares a **staging** installation. It does **not** purchase hosting or deploy to a live client.

## Inspected infrastructure (existing repo)

| Artefact | Finding |
| --- | --- |
| `.do/deploy.template.yaml` | Legacy DigitalOcean template for **NexoPOS-4x** / Heroku PHP Apache — **not** usable as-is for ZashPOS Laravel 12 + Reverb |
| `.env.example` | Local defaults (`BROADCAST_DRIVER=log`, `QUEUE_CONNECTION=sync`) — insufficient for production Reverb/queues |
| `.env.production.example` | New committed template (no secrets) for SSL-facing staging/production |
| `routes/console.php` | Scheduler already defines NexoPOS jobs when installed |
| Health | `GET /up` registered in `routes/web.php` |
| Packaging | `php artisan zash-dine:package` + [PACKAGING.md](PACKAGING.md) |

**Access still needed for a remote staging host:** SSH (or panel) to a VPS/PaaS, a DNS name with TLS certificates, outbound SMTP (optional), and firewall rules for HTTPS + Reverb (or a reverse-proxy WebSocket path). Until that exists, complete local staging only.

## Required host revision

| Item | Value |
| --- | --- |
| Git revision (master at Phase 5 packaging merge) | `afca66362f6d6e22a8c2cd85849d9ccec4b94e1c` |
| Core | `nexopos.version` **6.3.0** |
| Host identity | `haseeb099/ZashPOS` |
| Capabilities | `order-product-canonical-unit-price`, `pos-cart-products-mergeable` |

Record each built ZIP SHA-256 in `storage/logs/phase5-deployment-zip-manifest.json` (local evidence; packages themselves stay gitignored).

## Local staging layout

| Role | Path (default) | Notes |
| --- | --- | --- |
| Source / packaging | this checkout | May use a `vendor/` junction for packaging only |
| Staging instance | `D:\Nexo\ZashPOS-staging-deploy` | **Own** `vendor/`, `.env`, SQLite DB — no shared junction |
| Disposable restore target | `D:\Nexo\ZashPOS-restore-proof` | Receives `zashpos:restore` only |

Scripts:

```powershell
.\scripts\setup-staging-instance.ps1
.\scripts\prove-backup-restore.ps1
```

Serve staging (example):

```powershell
cd D:\Nexo\ZashPOS-staging-deploy
php artisan serve --no-reload --host=127.0.0.1 --port=8016
```

Modules upload UI: `http://127.0.0.1:8016/dashboard/modules/upload` (admin credentials from setup script).

## Production configuration checklist (secrets stay off Git)

Copy `.env.production.example` → server `.env`, then set:

1. **SSL / URL** — `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`
2. **Database** — MySQL/MariaDB credentials; never commit them
3. **Queue workers** — `QUEUE_CONNECTION=database` (or Redis) + `php artisan queue:work --sleep=1 --tries=3` under Supervisor/systemd
4. **Scheduler** — cron `* * * * * php /path/artisan schedule:run`
5. **Reverb** — `BROADCAST_DRIVER=reverb` (this is what `config/broadcasting.php` reads — not `BROADCAST_CONNECTION`), app key/secret, TLS-terminated `REVERB_HOST` / port 443 via reverse proxy; process `php artisan reverb:start`. Confirm with `php artisan tinker --execute "echo config('broadcasting.default');"` → `reverb`
6. **Logging** — `LOG_CHANNEL=stack`, `LOG_LEVEL=info` (or `warning` in steady state); ship `storage/logs/laravel.log`
7. **Health** — probe `GET /up` from the load balancer
8. **Assets** — `composer install --no-dev -o`, `npm ci && npm run build`, `php artisan storage:link`

## Hardware / print gates

See [ZASHDINE.md](ZASHDINE.md) § Receipts. Distinguish:

| Gap | Kind |
| --- | --- |
| No dedicated ESC/POS kitchen driver in ZashDine | **Missing functionality** |
| Thermal receipt via OS/`window.print` on receipt URL | **Hardware pending** (browser dialog exists) |
| Physical tablet at venue DPI | **Hardware pending** (viewport E2E covers layout) |

## Related docs

- [PACKAGING.md](PACKAGING.md) — ZIP build / clean Modules upload
- [BACKUP-RESTORE.md](BACKUP-RESTORE.md) — backup archives + restore proof
- [RELEASE-ROLLBACK.md](RELEASE-ROLLBACK.md) — update and rollback

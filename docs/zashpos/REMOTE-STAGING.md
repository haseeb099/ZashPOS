# Remote staging (Phase 5)

This runbook prepares a **separate staging host**. It does **not** purchase hosting or deploy a live client.

## Access status (this environment)

| Check | Result |
| --- | --- |
| SSH config / keys for a staging host | **Absent** |
| Hosting CLIs (`doctl`, `flyctl`, `heroku`, `aws`, `gcloud`, …) | **Not installed / not authenticated** |
| `ZASHPOS_STAGING_URL` / `ZASHPOS_SSH_HOST` | **Unset** |
| Local staging only | `http://127.0.0.1:8016` (SQLite; see [DEPLOYMENT.md](DEPLOYMENT.md)) |
| Remote staging URL | **Not available until access is provided** |

## Exact access needed (send via a secure channel — not Git, not Notion comments, not chat logs)

Provide **out-of-band** (password manager share, encrypted note, or 1:1 secure message):

1. **SSH** — host DNS or IP, port, username, and either an ed25519 public key already installed on the host **or** a one-time bootstrap path (console password + forced key rotation). Do **not** paste private keys into Cursor/GitHub.
2. **DNS** — staging hostname (e.g. `staging.example.com`) with A/AAAA (or CNAME) pointed at the VPS; ability to issue TLS (Let’s Encrypt or provider certs).
3. **Firewall** — inbound `443/tcp` (HTTPS + WebSocket upgrade); SSH restricted; no public `8080` for Reverb (proxy it).
4. **Database** — MySQL/MariaDB credentials for a **dedicated** staging schema (not client production). Prefer a separate DB user limited to that schema.
5. **Process supervision** — ability to install/configure systemd or Supervisor for `queue:work`, `reverb:start`, and cron/`schedule:run`.
6. **Optional** — SMTP credentials for mail tests; object-storage path for backup archives.

Until those exist, complete only local + documentation preparation in this PR.

## Target staging shape

| Requirement | How |
| --- | --- |
| Own database / env / dependencies | Separate DB schema, server `.env` (never in Git), `composer install --no-dev -o`, `npm ci && npm run build` on the host |
| HTTPS, secure cookies, debug off | `APP_URL=https://…`, `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true` — see `.env.production.example` |
| Reverb via HTTPS/WebSocket proxy | `BROADCAST_DRIVER=reverb`; reverse-proxy `/app` (or chosen path) to `REVERB_SERVER_PORT`; see `deploy/nginx/zashpos-staging.conf.example` |
| Supervised queue + Reverb + scheduler | `deploy/supervisor/*.conf.example` + cron `* * * * * php /path/artisan schedule:run` |
| Health / logs / restart | Probe `GET /up`; ship `storage/logs/laravel.log`; verify `systemctl restart` / Supervisor reread recovers workers |
| No public default admin | Create a unique admin during `ns:setup`; rotate immediately; never publish credentials in PRs or evidence JSON |

Committed templates under `deploy/` contain **placeholders only**.

## Post-access deploy checklist (operator)

1. Provision Ubuntu (or similar) VPS; install PHP 8.4+, Composer, Node 20+, nginx, MySQL/MariaDB, certbot, Supervisor.
2. Clone `haseeb099/ZashPOS` at the intended release SHA; copy `.env.production.example` → `.env`; fill secrets on the host only.
3. `composer install --no-dev -o && npm ci && npm run build && php artisan key:generate && php artisan migrate --force && php artisan storage:link`
4. Install ZashDine ZIP via Modules UI (or validated package path); `php artisan modules:migrate ZashDine --force`
5. Enable Supervisor programs + cron; confirm `php artisan tinker --execute "echo config('broadcasting.default');"` → `reverb`
6. Smoke: `/up`, login, Floor, Kitchen (two browsers), one dine-in pay, receipt HTML
7. Backup drill for the **actual** engine — MySQL: [BACKUP-RESTORE.md](BACKUP-RESTORE.md) § MySQL; run `scripts/proof-mysql-backup-restore.sh` (unique/absent target schema, separate storage tar + manifest hashes). Never claim `zashpos:backup` covers MySQL. Until that host run succeeds, treat **remote MySQL restore as pending**.

## Evidence to record (no secrets)

Write local-only JSON under `storage/logs/` (gitignored) with: git SHA, staging hostname (not passwords), TLS issuer, `GET /up` status, Supervisor unit names, backup proof path, Playwright report path. Never include `APP_KEY`, DB passwords, or admin passwords.

Local prep evidence may record Playwright + MySQL **input-safety regressions** only; do not claim a completed remote MySQL restore until the proof script exits 0 against the staging engine.

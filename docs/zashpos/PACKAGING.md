# ZashDine packaging and clean install (Phase 5)

## Required core version

| Requirement | Value |
| --- | --- |
| ZashPOS / NexoPOS core | **6.3.0** or newer (`config/nexopos.php` → `version`) |
| Declared in module | `modules/ZashDine/config.xml` → `<core min-version="6.3.0" />` |
| PHP | 8.4+ (CI/local also run on 8.5) |
| Laravel | 12.x |

The Modules UI refuses to enable ZashDine when `config('nexopos.version')` is below that minimum.

## Build the installable ZIP

From a checkout that already contains ZashDine (with built Blade assets — ZashDine ships POS/Floor/Kitchen scripts as Blade, not a separate Vite `Public/build` bundle):

```powershell
$env:ZASHPOS_PREFER_LOCAL_APP = '1'   # only when vendor/ is a junction
php artisan zash-dine:package --no-interaction
# or: .\scripts\package-zashdine.ps1
```

Output:

- `storage/app/zashdine-packages/ZashDine-{version}.zip`
- SHA-256 printed on the console
- Same source tree → same SHA-256 (stable ZIP entry order + fixed entry mtimes)

Excluded from the client ZIP (`manifest.json` + `PackageService` defaults): `Tests/**`, `node_modules/**`, `vendor/**`, VCS/editor junk.

## Install on a clean ZashPOS

1. Fresh ZashPOS **6.3.0+** with its **own** `vendor/` (`composer install`), **own** `.env`, and **own** database (SQLite or MySQL).
2. Do **not** copy `modules/ZashDine` from git if you are validating the Modules upload path — remove it first.
3. Dashboard → Modules → Upload → choose `ZashDine-1.0.0.zip`.
4. Enable ZashDine; migrations run on upload and again via `php artisan modules:migrate ZashDine --force`.
5. Confirm permissions `zash.dine.manage` / `waiter` / `chef`, tables `zash_dine_*`, and Blade assets under `modules/ZashDine/Resources/Views/`.

Automated same-tree upload path (moves the module aside, uploads the ZIP, verifies, restores on failure):

```powershell
php artisan zash-dine:validate-package-install --no-interaction
```

Evidence: `storage/logs/phase5-package-install.json`.

Optional second machine / directory with a real `composer install` (slow):

```powershell
.\scripts\validate-zashdine-clean-install.ps1
```

Evidence: `storage/logs/phase5-clean-install.txt`.

## What “built assets” means for ZashDine

ZashDine injects POS/Floor/Kitchen/popup behaviour through Blade scripts (`@module` views via `RenderFooterEvent`). There is no module Vite `Public/build` manifest to compile before packaging. Core dashboard/POS assets still need `npm run build` on the host ZashPOS.

## Out of scope

- Live client deployment
- ZashMarket licence delivery
- Merging this PR without review

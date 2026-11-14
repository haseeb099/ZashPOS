# ZashDine packaging and clean install (Phase 5)

## Required host (not stock NexoPOS alone)

| Requirement | Value |
| --- | --- |
| Numeric core | **6.3.0** or newer (`config/nexopos.php` → `version`) |
| Host | **haseeb099/ZashPOS** (or equivalent fork) **after Phase 4** — ships modifier-price + cart-merge hooks |
| Declared capabilities | `order-product-canonical-unit-price`, `pos-cart-products-mergeable` in `config/nexopos.php` → `capabilities` |
| Declared in module | `config.xml` → `<core min-version="6.3.0" requires-capabilities="…" />` |
| PHP | 8.4+ (CI/local also run on 8.5) |
| Laravel | 12.x |

Enable is refused when the numeric version is too low **or** when any required capability is missing from `config('nexopos.capabilities')`. Stock upstream NexoPOS 6.3.0 does **not** provide those capabilities.

**ZIP-only / older host:** `CoreCompatibilityService` ships inside the module. Even when the receiving core ignores `requires-capabilities`, the provider skips dine-in/modifier hooks, shows a rejection banner/signature, and auto-disables after enable so the dashboard stays up.

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
- Same source tree → same ZIP SHA-256 (stable entry order + `PackageService::ARCHIVE_MTIME` on every entry; PHPUnit rebuilds at different wall-clock times and compares `sha256`, not only `payload_sha256`)

Excluded from the client ZIP (`manifest.json` + `PackageService` defaults): `Tests/**`, `node_modules/**`, `vendor/**`, VCS/editor junk.

## Install on a clean ZashPOS

1. Fresh **ZashPOS** host that declares the required capabilities (Phase 4+), with its **own** `vendor/` (`composer install`), **own** `.env`, and **own** database (SQLite or MySQL).
2. Do **not** copy `modules/ZashDine` from git if you are validating the Modules upload path — remove it first.
3. Dashboard → Modules → Upload → choose `ZashDine-1.0.0.zip`.
4. Enable ZashDine; migrations run on upload and again via `php artisan modules:migrate ZashDine --force`.
5. Confirm permissions `zash.dine.manage` / `waiter` / `chef`, tables `zash_dine_*`, and Blade assets under `modules/ZashDine/Resources/Views/`.

Automated same-tree upload path (moves the module aside, uploads the ZIP, verifies, **restores the complete original directory on any failure** — including after a partial upload):

```powershell
php artisan zash-dine:validate-package-install --no-interaction
# restore regression: --force-fail-after=upload
```

Evidence: `storage/logs/phase5-package-install.json`.

Optional second machine / directory with a real `composer install` (slow). The script runs **`ns:setup` on an empty SQLite file** (never copies `tests/database.sqlite`), asserts ZashDine tables/permissions/migration rows are **absent before** upload, and **fails** on every missing post-install requirement:

```powershell
.\scripts\validate-zashdine-clean-install.ps1
```

Evidence: `storage/logs/phase5-clean-install.txt` and the clean tree’s `storage/logs/phase5-clean-verify.json`.

## What “built assets” means for ZashDine

ZashDine injects POS/Floor/Kitchen/popup behaviour through Blade scripts (`@module` views via `RenderFooterEvent`). There is no module Vite `Public/build` manifest to compile before packaging. Core dashboard/POS assets still need `npm run build` on the host ZashPOS.

## Out of scope

- Live client deployment
- ZashMarket licence delivery
- Merging this PR without review

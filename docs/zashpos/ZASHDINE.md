# ZashDine (restaurant module)

## Identity
Path modules/ZashDine, namespace Modules\ZashDine, route prefix zash-dine, permissions zash.dine.*, tables zash_dine_*.

## Permissions
zash.dine.manage, zash.dine.waiter, zash.dine.chef.

## Tables
areas, tables, kitchens, kitchen_categories, order_items, modifier_groups, modifiers (all prefixed zash_dine_).

`zash_dine_tables` has a unique index on `(area_id, name)`. Duplicate names through CRUD return a friendly 403. Occupancy is system-managed (not editable on the Tables form). A table with an active order cannot be deleted. An area with occupied tables cannot be disabled or deleted.

## Dashboard
- Areas CRUD: `/dashboard/zash-dine/areas` (`zash.dine.manage`)
- Tables CRUD: `/dashboard/zash-dine/tables` (`zash.dine.manage`)
- Floor view: `/dashboard/zash-dine/floor` (`zash.dine.waiter` **or** `zash.dine.manage`; polls `/api/zash-dine/floor` every 5 seconds; waiters can cancel a meal / add a note)
- Settings: existing ZashDine settings page

## POS dine-in
- Order type identifier: `dine-in` (enabled in `ns_pos_order_types`; core defaults are `takeaway` + `delivery`)
- Requires `zash_dine_table_id` on the order payload
- Pre-check + locked assign: occupancy is rejected in `OrderAfterCheckPerformedEvent` and again under `lockForUpdate` inside `OrdersService::create()`'s transaction (rejection rolls back the order and stock)
- On create: sets `current_order_id` + `occupied`, writes `zash_dine_order_items`
- On update (`OrderAfterUpdatedEvent`): new products get pending `zash_dine_order_items`; removed products mark their row cancelled (already-cancelled rows stay cancelled)
- Rejects a second order on an occupied table
- Frees the table when the order is paid, voided, or deleted (partially paid keeps the table occupied)

## Waiter APIs
- `POST /api/zash-dine/order-items/{id}/cancel` — cancels the kitchen row **and** removes the product from an unpaid/active order inside one `DB::transaction` (total via `OrdersService::refreshOrder`, stock return via `ProductService::stockAdjustment`, same path as order-update `__deleteUntrackedProducts`). Blocked when the order is no longer on the floor (paid/void/freed), and blocked with “use a refund instead” when the order is partially paid or already has payments (notes still allowed). Note: core `OrdersService::deleteOrderProduct` is currently unusable (`OrderBeforeDeleteProductEvent` uses Bus `Dispatchable`).
- `PUT /api/zash-dine/order-items/{id}/note` body `{ "note": "..." }` — active-order guard only (payments do not block notes)
- `GET /api/zash-dine/tables/available`

## Scope by phase
- Phase 1: skeleton, permissions, migrations, menu, settings page, smoke test
- Phase 2a: areas and tables CRUD, unique table name per area, floor view with polling
- Phase 2b: dine-in POS order type, table lock/occupy/free, waiter cancel/note actions
- Phase 3: category to kitchen routing, kitchen screen, Reverb live updates with polling fallback
- Phase 4: modifier groups and modifiers with stock
- Phase 5: manual QA, packaging as an installable zip

## Test
```bash
php artisan test --compact --testsuite=ZashDine
php artisan test --compact modules/ZashDine/Tests
php artisan test --compact modules/ZashDine/Tests/Feature/ZashDineModuleSmokeTest.php
php artisan test --compact modules/ZashDine/Tests/Feature/ZashDineAreasTablesCrudTest.php
php artisan test --compact modules/ZashDine/Tests/Feature/ZashDineDineInOrderTest.php
```

## Playwright
Browser coverage lives in `e2e/` with its own `package.json` (root `package.json` is not a Playwright dependency). Credentials stay in `e2e/.env` (see `e2e/.env.example`). The Laravel app for that suite uses `.env.e2e` (`DB_DATABASE=database/e2e.sqlite`), which is not committed.

```bash
cd e2e
npm install
npx playwright install chromium
node scripts/setup.mjs
cd ..
php artisan serve --env=e2e --no-reload --host=127.0.0.1 --port=8001
cd e2e
npm test
npm run report
```

`E2E_BASE_URL` must use the same host as `SESSION_DOMAIN` in `.env.e2e` (this repo uses `127.0.0.1`). `SANCTUM_STATEFUL_DOMAINS` must include that host and port (`127.0.0.1:8001`), or dashboard API calls return Unauthenticated. `setup.mjs` writes both when it creates `.env.e2e`. `setup.mjs` installs ZashPOS, enables ZashDine, migrates it, generates 4 categories and 30 products, turns stock management on, sets every unit quantity to 100, creates a walk-in customer, and creates a waiter (store cashier permissions plus `zash.dine.waiter`) and a cashier user. `--no-reload` is required so the PHP server keeps the variables from `.env.e2e`. Without it, `artisan serve` clears them and the worker boots the default `.env`. Run that server from the project root while Vite is running if the dashboard loads assets from the dev server. Paying a reopened order re-selects the customer in the POS first: the loaded order omits `customer.group`, and the core payment popup reads `customer.group.minimal_credit_payment` (`resources/ts/popups/ns-pos-payment-popup.vue`). That core file is not changed here.

The host `phpunit.xml` includes a `ZashDine` testsuite (`modules/ZashDine/Tests`), so a plain `php artisan test --compact` run also executes the module tests.

## Status
Phase 2b in progress (dine-in POS + waiter meal actions).

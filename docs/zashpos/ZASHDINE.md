# ZashDine (restaurant module)

## Identity
Path modules/ZashDine, namespace Modules\ZashDine, route prefix zash-dine, permissions zash.dine.*, tables zash_dine_*.

## Permissions
zash.dine.manage, zash.dine.waiter, zash.dine.chef.

## Tables
areas, tables, kitchens, kitchen_categories, order_items, modifier_groups, modifiers, product_modifier_groups, order_product_modifiers (all prefixed zash_dine_).

`zash_dine_tables` has a unique index on `(area_id, name)`. Duplicate names through CRUD return a friendly 403. Occupancy is system-managed (not editable on the Tables form). A table with an active order cannot be deleted. An area with occupied tables cannot be disabled or deleted.

## Dashboard
- Areas CRUD: `/dashboard/zash-dine/areas` (`zash.dine.manage`)
- Tables CRUD: `/dashboard/zash-dine/tables` (`zash.dine.manage`)
- Kitchens CRUD: `/dashboard/zash-dine/kitchens` (`zash.dine.manage`; assign product categories; one kitchen per category)
- Modifier groups CRUD: `/dashboard/zash-dine/modifier-groups` (`zash.dine.manage`; single/multiple, min/max, allow quantity)
- Modifiers CRUD: `/dashboard/zash-dine/modifiers` (`zash.dine.manage`; price, optional ingredient product, enabled/disabled)
- Floor view: `/dashboard/zash-dine/floor` (`zash.dine.waiter` **or** `zash.dine.manage`; live updates on private `zash-dine.floor` when Reverb/Echo is available; polls `/api/zash-dine/floor` every 5 seconds as fallback; waiters can cancel a meal / add a note; ready-meal snackbars for `Order.author_id`)
- Kitchen screen: `/dashboard/zash-dine/kitchen` (`zash.dine.chef` **or** `zash.dine.manage`; live updates on private `zash-dine.kitchen` when Reverb/Echo is available; polls `/api/zash-dine/kitchen/tickets` every 5 seconds as fallback; preparing/ready/rejected actions)
- Settings: existing ZashDine settings page

## POS dine-in
- Order type identifier: `dine-in` (enabled in `ns_pos_order_types`; core defaults are `takeaway` + `delivery`)
- Requires `zash_dine_table_id` on the order payload
- Pre-check + locked assign: occupancy is rejected in `OrderAfterCheckPerformedEvent` and again under `lockForUpdate` inside `OrdersService::create()`'s transaction (rejection rolls back the order and stock)
- On create: sets `current_order_id` + `occupied`, writes `zash_dine_order_items` with `kitchen_id` from category routing (null when unassigned/disabled kitchen)
- On update (`OrderAfterUpdatedEvent`): new products get pending `zash_dine_order_items` once; existing pending rows re-resolve `kitchen_id` from the current product (same-line Grill→Bar / Unassigned replacements stay correctly filtered); started meals keep status/`kitchen_id`; removed products mark their row cancelled (already-cancelled rows stay cancelled). If the order leaves dine-in after a permitted type change, remaining kitchen rows are cancelled and the table is released so occupancy is not stranded
- Rejects a second order on an occupied table
- Frees the table when the order is paid, voided, or deleted (partially paid keeps the table occupied)

## Modifiers (Phase 4)
Pricing (D-017): `unit_price = catalog base + Σ(modifier.price × modifier line quantity)`. Catalog base is `sale_price_edit`, or `wholesale_price_edit` in wholesale mode. Core still computes `line_total = (unit_price − discount) × meal_qty` and tax from that unit price. Modifier amounts are not stored in `line_extra`.

- Product form tab `modifiers` (filter `ns-products-crud-form`, under `variations[0].tabs`) assigns groups through pivot `zash_dine_product_modifier_groups`. Save goes through `ns-create-products-inputs` / `ns-update-products-inputs`.
- POS `GET /api/zash-dine/pos/products/{id}/modifiers`. If the product has groups, `ZashDineModifierPopup` collects the selection, stores `zash_dine_modifiers` and a `zash_dine_modifiers_key` fingerprint on the cart line, and `ns-pos-product-unit-price` adds the surcharge. The cart product row lists the chosen modifiers. Before submit, the baked unit price is written onto the product.
- Cart merge uses `ns-pos-cart-products-mergeable`. ZashDine returns false when the fingerprints differ, so two meals with different modifiers stay separate lines.
- Server check in `OrderAfterCheckPerformedEvent`: groups allowed for the product, modifier ids belong to those groups, single/multiple min/max (count of chosen modifiers, not the sum of quantities), `allow_quantity`, enabled status, and submitted `unit_price` within 0.0001 of the expected baked price. Tampered payloads throw `NotAllowedException`. Ingredient availability is checked in the same request so a shortage rolls the order transaction back.
- Persistence: `zash_dine_order_product_modifiers` snapshots name and price, plus quantity, ingredient `product_id`, and `stock_applied`. Sync runs on order-product created/updated when `zash_dine_modifiers` is present on the product data. A missing key does not wipe saved rows.
- Stock: required = modifier quantity × meal quantity. Only the difference from `stock_applied` is posted (`sold` on the first deduction, `sale-adjustment` when it increases, `return-adjustment` when it decreases). An unchanged resave posts nothing. Deleting the order product returns `stock_applied` and deletes the rows. Kitchen rejection does not return modifier stock. Hold orders keep the snapshot and leave `stock_applied` at 0 until a later non-hold save includes the modifiers.
- Protected meals: waiters cannot change the modifier fingerprint (sorted `modifier_id:qty`) once the kitchen status is preparing, ready, or rejected. `zash.dine.manage` can.
- Kitchen tickets include modifiers and the kitchen screen renders them under the meal name.
- Reopening a POS order (`GET /api/pos/orders/{id}`) attaches `zash_dine_modifiers` from the snapshot table onto each product.

## Waiter APIs
- `POST /api/zash-dine/order-items/{id}/cancel` — locks `order → order_item → order_product`, re-checks status/permissions/active-order/payments on the locked rows, then cancels the kitchen row **and** removes the product from an unpaid/active order (total via `OrdersService::refreshOrder`, stock return via `ProductService::stockAdjustment`). Blocked when the order is no longer on the floor (paid/void/freed), blocked with “use a refund instead” when the order is partially paid or already has payments, and blocked for waiters after kitchen status is `preparing`/`ready`/`rejected` (manager/`zash.dine.manage` required). The same manage check also applies on order updates that would remove, replace, reduce, or identity-swap those protected meals (`OrderAfterCheckPerformedEvent`). Protection is based on the persisted dine-in order and kitchen rows (not the incoming type): waiters cannot bypass by submitting takeaway/delivery. Guards resolve identity like core (`product_id` or `sku`, catalog→custom), require `unit_quantity_id` to belong to that product, and evaluate statuses from locked `order → order_item` rows. Leaving dine-in with only pending meals is allowed; kitchen rows are cancelled and the table freed afterward. Notes still allowed under the active-order guard. Note: core `OrdersService::deleteOrderProduct` is currently unusable (`OrderBeforeDeleteProductEvent` uses Bus `Dispatchable`).
- `PUT /api/zash-dine/order-items/{id}/note` body `{ "note": "..." }` — active-order guard only (payments do not block notes)
- `GET /api/zash-dine/tables/available`

## Kitchen APIs (Phase 3a)
- `GET /api/zash-dine/kitchen/tickets?kitchen_id=` — chef/manage; optional filter `all` / kitchen id / `unassigned`
- `POST /api/zash-dine/order-items/{id}/kitchen-status` body `{ "status": "preparing"|"ready"|"rejected" }` — transitions under `lockForUpdate` inside a transaction. Rejection does **not** remove charges or return stock.
- Status machine: `pending → preparing → ready`, or `pending|preparing → rejected`. Ready/rejected are terminal for kitchen actions.
- Polling remains the authoritative fallback; Phase 3b adds Reverb private-channel push after successful commit

## Realtime (Phase 3b)
- Private channels (server-authorized): `zash-dine.kitchen` (chef/manage), `zash-dine.floor` (waiter/manage); `App.User.{id}` is owner-only
- Board event: `zash-dine.board-updated` (`ZashDineBoardUpdated`, after commit). Clients refresh from the API; duplicate `event_id`s are ignored; reconnect triggers a refresh; Kitchen/Floor wait for Echo init (before or after `DOMContentLoaded`) and clear wait/retry handlers on unmount
- Ready alert recipient rule: **`Order.author_id`** (responsible waiter / order owner). `OrderItem.author_id` is ignored for alerts. After a successful kitchen commit, emits durable notification + `zash-dine.meal-ready` on `App.User.{author_id}` (notification create is also deferred until commit; core notification broadcast events are after-commit)
- Floor cleanup removes only the meal-ready listener (`stopListening`); it does not leave `App.User.*`, so the dashboard notification subscriber keeps working
- Requires `BROADCAST_DRIVER=reverb` (and Reverb env) for WebSocket delivery; without it, 5s polling still works. A Reverb outage after commit is logged and does not turn a valid kitchen status write into an HTTP error; durable ready notifications still persist when NotificationCreatedEvent cannot publish
- Playwright: `e2e/specs/realtime-reverb.spec.ts` (set `E2E_REVERB=1` with Reverb on the e2e host/port for the live WebSocket proof; browser polling-fallback coverage is a separate case that forces Echo unavailable in-page). PHPUnit covers unavailable-Reverb broadcaster failure on the ready path separately from that browser case

## Scope by phase
- Phase 1: skeleton, permissions, migrations, menu, settings page, smoke test
- Phase 2a: areas and tables CRUD, unique table name per area, floor view with polling
- Phase 2b: dine-in POS order type, table lock/occupy/free, waiter cancel/note actions
- Phase 2c: Vue table picker + Floor note/cancel popups (core `Popup.show`)
- Phase 3a: category to kitchen routing, kitchen screen (preparing/ready/rejected), polling
- Phase 3b: Reverb live updates + waiter-ready notifications (polling fallback retained)
- Phase 4: modifier groups, POS modifier popup, unit-price surcharge, ingredient stock deltas, protected-meal modifier edits (see Modifiers)
- Phase 5: manual QA, packaging as an installable zip

## Test
```bash
php artisan test --compact --testsuite=ZashDine
php artisan test --compact modules/ZashDine/Tests
php artisan test --compact modules/ZashDine/Tests/Feature/ZashDineModuleSmokeTest.php
php artisan test --compact modules/ZashDine/Tests/Feature/ZashDineAreasTablesCrudTest.php
php artisan test --compact modules/ZashDine/Tests/Feature/ZashDineDineInOrderTest.php
php artisan test --compact modules/ZashDine/Tests/Feature/ZashDineModifiersTest.php
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

`E2E_BASE_URL` must use the same host as `SESSION_DOMAIN` in `.env.e2e` (this repo uses `127.0.0.1`). `SANCTUM_STATEFUL_DOMAINS` must include that host and port (`127.0.0.1:8001`), or dashboard API calls return Unauthenticated. `setup.mjs` writes both when it creates `.env.e2e`. `setup.mjs` installs ZashPOS, enables ZashDine, migrates it, generates 4 categories and 30 products, turns stock management on, sets every unit quantity to 100, creates a walk-in customer, and creates a waiter (store cashier permissions plus `zash.dine.waiter`) and a cashier user. `--no-reload` is required so the PHP server keeps the variables from `.env.e2e`. Without it, `artisan serve` clears them and the worker boots the default `.env`. Run that server from the project root while Vite is running if the dashboard loads assets from the dev server. Reopened POS orders include `customer.group` (eager-loaded in `OrdersService::getOrder`). Payment and layaway validate `minimal_credit_payment` fail-closed (finite percentage `>= 0` from a number or nonblank numeric string, including valid `0`; blank/nonnumeric/nonfinite values and booleans/arrays/objects block with an actionable error and keep the current customer). Do not reselect the customer before paying a reopened order.

The host `phpunit.xml` includes a `ZashDine` testsuite (`modules/ZashDine/Tests`), so a plain `php artisan test --compact` run also executes the module tests.

## Status
Phase 2c merged (`3b2ac5e2b`). Phase 3a kitchen routing/workflow on `phase-3a-kitchen-routing`. Phase 4 modifiers are implemented on `phase-4-modifiers` and are not merged.

POS dine-in opens `#zash-dine-table-picker` when the cart has no table yet; reopened/held dine-in orders keep `zash_dine_table_id` and skip the picker. Confirm re-checks `/api/zash-dine/tables/available` before resolve; a race clears the selection, keeps a visible notice while the list refreshes, and requires choosing an available table again. Floor note/cancel use `#zash-dine-note-popup` / `#zash-dine-cancel-meal-popup` and close only after a successful API response (errors stay in the popup). Popup shells are capped to the viewport (`min(36rem, 100vw-2rem)` / `min(80vh, 100vh-2rem)`) so tablet footers stay reachable.

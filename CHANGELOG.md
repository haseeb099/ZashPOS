# Changelog

Tracks ZashPOS changes only, in Keep a Changelog style. Upstream NexoPOS notes stay in the changelogs/ folder.

## [Unreleased]

### Added
- ZashDine Phase 3a: manager Kitchens CRUD with exclusive category routing, chef Kitchen screen (5s polling, filter including Unassigned), dine-in meal routing on order save/update without duplicating existing kitchen rows, preparing/ready/rejected transitions with permissions and locking, rejection that keeps charges, and cancel-after-preparing restricted to managers.
- ZashDine Phase 2c: Vue popups via core `Popup.show` for the POS dine-in table picker (tables grouped by area) and Floor meal note / cancel-meal confirmation. Native `window.prompt` / `window.confirm` are removed for those flows.
- ZashDine Phase 2c follow-up: table-picker race notice survives availability refresh (selection cleared until a free table is chosen again), popup shells stay within the viewport on tablet sizes, and Playwright covers empty/retry/race/dismiss/note-cancel error paths.

### Fixed
- ZashDine Phase 3a hardening: order edits cannot bypass kitchen cancel restrictions for preparing/ready/rejected meals; Floor cancel reloads under lock before checks; kitchen CRUD category assignment is transactional (no partial kitchen/pivot writes); POS browser flow covers create/edit meal identity.
- ZashDine Phase 3a order-edit protection: guards use persisted dine-in/kitchen state (type change cannot bypass), compare product identity including catalog→custom swaps, and lock `order → order_item` before reading statuses inside the update transaction; permitted leave-dine-in cleans kitchen rows and frees the table.
- `CreateOrderPaidWithCustomerCredit` no longer attaches catalog `product_id` lines during the credit-limit probe, so depleted stock from earlier suite tests cannot turn the expected 403 into a 500.
- ZashDine Playwright suite in `e2e/` (own package and lockfile): order types, areas/tables, permissions, dine-in POS, held-order edits, waiter note/cancel, concurrent table claims, and tablet screenshots. Setup uses `.env.e2e` and `database/e2e.sqlite`.
- ZashDine Phase 2b: POS order type "Dine-in" that requires a table (locked with `lockForUpdate`), occupies the table and creates `zash_dine_order_items`, frees the table on pay/void/delete, and waiter cancel-meal / add-note APIs with Floor UI actions.
- ZashDine Phase 2b review: pre-create occupancy check (no orphan orders), sync kitchen rows on order update, cancel meal removes the product from unpaid orders (total/stock), safer `ns_pos_order_types` migration that never drops core defaults, and meal actions blocked when the order is no longer active.
- ZashDine Phase 2b review: cancel meal runs in a DB transaction (rolls back on failure), cancel is blocked when the order has payments (use a refund), and `modules/ZashDine/Tests` is included in `phpunit.xml` as the ZashDine suite.
- ZashDine Phase 2b review: restore Areas/Tables/Floor route permission coverage (hard route existence asserts, waiter/manager/neither HTTP checks, static middleware inspection for web routes).
- ZashDine Phase 2a: native CRUDs for areas and tables (permission zash.dine.manage), unique (area_id, name) on tables, dashboard Floor view with 5s polling, and menu entries Areas / Tables / Floor.
- ZashDine module skeleton (Phase 1): permissions zash.dine.manage, zash.dine.waiter, zash.dine.chef; tables zash_dine_areas, _tables, _kitchens, _kitchen_categories, _order_items, _modifier_groups, _modifiers; menu entry, settings page and an enable/disable smoke test.

### Fixed
- Customer-group payment rules now fail closed for blank, nonnumeric, nonfinite, boolean, array and object `minimal_credit_payment` values (valid `0` and numeric strings still work). Layaway E2E covers the real popup minimum, below-minimum rejection, persisted initial-payment state, and malformed-rule blocking without order writes.
- Reopened POS orders now include `customer.group` (and its `minimal_credit_payment` rule). Payment and layaway popups no longer crash when group data is missing; they block instead of silently relaxing the minimum. Playwright no longer reselects a customer before paying a reopened unpaid/held order.
- ZashDine POS script waits for the deferred POS bundle before registering the dine-in table prompt, restores the table id when a held dine-in order is saved again, and sends a typed table id to the server even when that table is no longer in the free list.
- Store timezone settings now offer only canonical IANA Zone identifiers. Obsolescent links such as `Canada/Pacific` are remapped at runtime (e.g. to `America/Vancouver`) so DateService/Carbon work on CI hosts whose tzdata omits backward-compatibility names. Existing saved legacy identifiers are normalized the same way without a database rewrite.

### Changed
- ZashDine Floor is available to `zash.dine.waiter` or `zash.dine.manage`. Tables CRUD no longer lets staff set Occupied manually. Duplicate table names and areas with occupied tables return friendly errors.
- Visible rebrand from NexoPOS to ZashPOS (Phase 0): dashboard header, auth/setup pages, logo, emails, lang files, README, composer.json and package.json names. Footer shows "Based on NexoPOS". Internal identifiers (namespaces, ns.* permissions, config keys) are unchanged.

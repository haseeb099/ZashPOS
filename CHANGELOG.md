# Changelog

Tracks ZashPOS changes only, in Keep a Changelog style. Upstream NexoPOS notes stay in the changelogs/ folder.

## [Unreleased]

### Added
- ZashDine Phase 2b: POS order type "Dine-in" that requires a table (locked with `lockForUpdate`), occupies the table and creates `zash_dine_order_items`, frees the table on pay/void/delete, and waiter cancel-meal / add-note APIs with Floor UI actions.
- ZashDine Phase 2a: native CRUDs for areas and tables (permission zash.dine.manage), unique (area_id, name) on tables, dashboard Floor view with 5s polling, and menu entries Areas / Tables / Floor.
- ZashDine module skeleton (Phase 1): permissions zash.dine.manage, zash.dine.waiter, zash.dine.chef; tables zash_dine_areas, _tables, _kitchens, _kitchen_categories, _order_items, _modifier_groups, _modifiers; menu entry, settings page and an enable/disable smoke test.

### Changed
- ZashDine Floor is available to `zash.dine.waiter` or `zash.dine.manage`. Tables CRUD no longer lets staff set Occupied manually. Duplicate table names and areas with occupied tables return friendly errors.
- Visible rebrand from NexoPOS to ZashPOS (Phase 0): dashboard header, auth/setup pages, logo, emails, lang files, README, composer.json and package.json names. Footer shows "Based on NexoPOS". Internal identifiers (namespaces, ns.* permissions, config keys) are unchanged.

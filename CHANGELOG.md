# Changelog

Tracks ZashPOS changes only, in Keep a Changelog style. Upstream NexoPOS notes stay in the changelogs/ folder.

## [Unreleased]

### Added
- ZashDine module skeleton (Phase 1): permissions zash.dine.manage, zash.dine.waiter, zash.dine.chef; tables zash_dine_areas, _tables, _kitchens, _kitchen_categories, _order_items, _modifier_groups, _modifiers; menu entry, settings page and an enable/disable smoke test.

### Changed
- Visible rebrand from NexoPOS to ZashPOS (Phase 0): dashboard header, auth/setup pages, logo, emails, lang files, README, composer.json and package.json names. Footer shows "Based on NexoPOS". Internal identifiers (namespaces, ns.* permissions, config keys) are unchanged.

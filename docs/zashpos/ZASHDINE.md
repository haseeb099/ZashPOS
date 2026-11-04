# ZashDine (restaurant module)

## Identity
Path modules/ZashDine, namespace Modules\ZashDine, route prefix zash-dine, permissions zash.dine.*, tables zash_dine_*.

## Permissions
zash.dine.manage, zash.dine.waiter, zash.dine.chef.

## Tables
areas, tables, kitchens, kitchen_categories, order_items, modifier_groups, modifiers (all prefixed zash_dine_).

`zash_dine_tables` has a unique index on `(area_id, name)`. A table with status `occupied` or a non-null `current_order_id` cannot be deleted.

## Dashboard
- Areas CRUD: `/dashboard/zash-dine/areas`
- Tables CRUD: `/dashboard/zash-dine/tables`
- Floor view: `/dashboard/zash-dine/floor` (polls `/api/zash-dine/floor` every 5 seconds)
- Settings: existing ZashDine settings page

## Scope by phase
- Phase 1: skeleton, permissions, migrations, menu, settings page, smoke test
- Phase 2a: areas and tables CRUD, unique table name per area, floor view with polling
- Phase 2b: dine-in POS order type, waiter cancel/note actions
- Phase 3: category to kitchen routing, kitchen screen, Reverb live updates with polling fallback
- Phase 4: modifier groups and modifiers with stock
- Phase 5: manual QA, packaging as an installable zip

## Test
```bash
php artisan test --compact modules/ZashDine/Tests
php artisan test --compact modules/ZashDine/Tests/Feature/ZashDineModuleSmokeTest.php
php artisan test --compact modules/ZashDine/Tests/Feature/ZashDineAreasTablesCrudTest.php
```

## Status
Phase 2a in progress (areas/tables CRUD + floor polling).

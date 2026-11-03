# ZashDine (restaurant module)

## Identity
Path modules/ZashDine, namespace Modules\ZashDine, route prefix zash-dine, permissions zash.dine.*, tables zash_dine_*.

## Permissions
zash.dine.manage, zash.dine.waiter, zash.dine.chef.

## Tables
areas, tables, kitchens, kitchen_categories, order_items, modifier_groups, modifiers (all prefixed zash_dine_).

## Scope by phase
- Phase 1: skeleton, permissions, migrations, menu, settings page, smoke test
- Phase 2: areas and tables CRUD, floor view, dine-in order type, cancel meal and notes
- Phase 3: category to kitchen routing, kitchen screen, Reverb live updates with polling fallback
- Phase 4: modifier groups and modifiers with stock
- Phase 5: manual QA, packaging as an installable zip

## Test
php artisan test --compact modules/ZashDine/Tests/Feature/ZashDineModuleSmokeTest.php

## Status
Update this section at the end of every phase.

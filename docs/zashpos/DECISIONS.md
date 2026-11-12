# ZashPOS decisions

| ID | Decision | Reason |
|---|---|---|
| D-001 | Base is the NexoPOS v6.3.0 fork (Laravel 12, Vue 3, Tailwind 4, Reverb) | Reuse a working POS and its module system |
| D-002 | Rebrand visible text only; never rename App\, Ns* classes, ns.* permissions or config keys | Keeps upstream merges and the module system working |
| D-003 | LICENSE (GPL-3.0) stays; footer says "Based on NexoPOS" | Licence compliance |
| D-004 | Revenue model is hosting, support and updates, not code secrecy | GPL: clients receive source. Confirm with a lawyer before selling |
| D-005 | First module is ZashDine (own design, no copied code or assets) | Restaurant vertical |
| D-006 | One instance per client, no multi-tenancy for now | Simplest to ship |
| D-007 | ZashMarket is a separate Laravel app, built after client #1 | Avoid premature work |
| D-008 | Tests only for risky logic; the rest is manual QA | Speed |
| D-009 | Cancel meal removes the product from unpaid dine-in orders via `OrderProduct::delete` + `OrdersService::refreshOrder` + stock return (same as order-update `__deleteUntrackedProducts`), not `deleteOrderProduct` | Core `OrderBeforeDeleteProductEvent` uses Bus `Dispatchable` and fatals; keep honest bill behaviour without a core edit |
| D-010 | `OrdersService::getOrder` eager-loads `customer.group` so reopened POS orders keep `minimal_credit_payment`; payment/layaway UI must error when group rules are missing instead of defaulting to 0 | Reopened carts previously crashed on `order.customer.group.minimal_credit_payment`; reselection was a workaround that could change the customer |
| D-011 | Treat `minimal_credit_payment` as a finite percentage `>= 0` (backend float/integer, `0` means no minimum). Accept only finite numbers or nonblank numeric strings; blank/NaN/nonnumeric values and booleans/arrays/objects are invalid and must fail closed | `Number('')===0`, `Number(false)===0`, `Number([])===0`, `Number(true)===1`, and `NaN >= 0 === false` previously coerced or skipped layaway validation |
| D-012 | Offer and persist only canonical IANA Zone identifiers; remap obsolescent links (e.g. `Canada/Pacific` → `America/Vancouver`) via `TimezoneCatalog` at runtime (no database rewrite of saved settings) | Backward Link names fail on some CI/system tzdata builds while preserving the same DST/offset rules as the Zone target |

## How to add a decision
Add a row with the next ID, the decision and the reason.

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

## How to add a decision
Add a row with the next ID, the decision and the reason.

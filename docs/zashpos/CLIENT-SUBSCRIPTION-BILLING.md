# Next work: client subscription billing and expiry

**Status:** specification only. Do **not** implement guessed commercial rules in this phase. Do **not** start ZashMarket.

## Existing code and decisions (inspected)

| Source | Finding |
| --- | --- |
| [DECISIONS.md](DECISIONS.md) D-004 | Revenue = hosting + support + updates (GPL); lawyer before selling |
| D-006 | One instance per client; no multi-tenancy |
| D-007 | ZashMarket is a **separate** app after client #1 — module marketplace, not host billing |
| Notion Phase 6 | Paddle / Lemon Squeezy mentioned for **add-on** licences — undecided for **hosted instance** subscriptions |
| `App\Services\MarketplaceService` | Upstream NexoPOS marketplace OAuth/licences — **not** ZashPOS client hosting billing |
| Order `billing` fields / customer addresses | POS customer billing address only — unrelated to SaaS subscription |
| ZashDine / ZashPOS modules | **No** `paid_through`, grace period, or subscription gate today |

Conclusion: hosted-client subscription lifecycle is a **new** product surface (likely host-side control plane + light checks inside each client instance). It must not be conflated with ZashMarket add-on licences.

## Goals

For each paid hosted ZashPOS instance:

1. Accept verified, **idempotent** payment events from a billing provider.
2. Maintain a **paid-through** date (and optional configurable **grace** period).
3. After expiry (+ grace): **restrict** operator access while preserving data.
4. Allow **payment recovery** and audited **manual extensions**.
5. Survive billing-service outages without silent data loss or corrupt state.
6. Keep open restaurant orders safe (no mass void on expiry).

## Proposed domain model (host control plane — undecided storage)

Suggested fields (names illustrative):

| Field | Purpose |
| --- | --- |
| `client_instance_id` | Stable id for the hosted install |
| `billing_customer_id` | Provider customer id |
| `subscription_status` | `trialing` / `active` / `past_due` / `grace` / `restricted` / `cancelled` |
| `paid_through_at` | End of paid period (UTC) |
| `grace_days` | Configurable integer; **value undecided** |
| `restricted_at` | When restricted mode began |
| `provider` / `provider_event_id` | Idempotency key for webhooks |
| `manual_extension_audit` | Who/when/why for ops overrides |

Instance-side may mirror a signed entitlement blob or poll the control plane — transport **undecided**.

## Payment events (required behaviour)

- Verify webhook signatures; reject unverified payloads.
- Idempotent processing on `provider_event_id` (replay-safe).
- Map only explicitly allowed event types to state transitions (invoice paid → extend `paid_through_at`; refund/chargeback → policy **undecided**).
- Never trust client-reported payment success alone.

## Access after expiry

**Undecided product choices** (must be decided before coding):

| Topic | Options to decide |
| --- | --- |
| Provider | Paddle vs Lemon Squeezy vs Stripe Billing vs manual invoice |
| Pricing | Monthly/annual amounts, currency, VAT handling for UK hosts |
| Grace period | Days after `paid_through_at` before restriction (0 / 3 / 7 / …) |
| Restriction UX | Read-only POS vs login banner + block mutations vs full lockout except billing/export |
| Open orders | Allow complete-in-progress tickets only vs freeze all writes immediately |
| Export | Who can export, formats, whether export remains available while restricted |

**Recommended defaults for a later implementation discussion (not approved):**

- Grace: configurable, default proposed **7 days** pending owner approval.
- Restriction: block new orders / settings changes; allow view + export + pay-bill recovery URL; do **not** auto-cancel open dine-in orders.
- Preserve all DB rows and `storage/app` uploads.

## Payment recovery

- Restricted instances show a recovery path (hosted billing portal or ops contact).
- Successful verified payment clears restriction and sets new `paid_through_at`.
- Failed recovery leaves prior restriction intact (fail closed).

## Billing-service outages

- Last-known `paid_through_at` + grace remains authoritative offline.
- Do not auto-extend because the provider is unreachable.
- Queue webhook processing; alert ops on sustained verify failures.
- Manual extension path required when provider is down (see audit).

## Audited manual extensions

- Only designated ops roles on the **control plane**.
- Record actor, previous `paid_through_at`, new value, ticket/reason.
- Instance entitlement refresh must be observable (log + optional admin notice).

## Explicit non-goals for the first billing slice

- Building ZashMarket or reusing MarketplaceService for hosting fees.
- Hard DRM that “locks” GPL code.
- Guessing prices, tax regimes, or provider-specific SKUs in application code.
- Multi-tenant single-DB billing.

## Acceptance criteria (when a future PR implements this)

1. Duplicate webhooks do not double-extend entitlement.
2. Clock-based transition into grace then restricted is test-covered with frozen time.
3. Restricted mode blocks agreed mutations; open orders are not mass-deleted.
4. Export/recovery paths work while restricted.
5. Manual extension appears in an audit log and is covered by a feature test.
6. No secrets (provider keys) in Git; staging uses a separate provider account.

## Suggested next implementation order (after decisions)

1. Owner decides provider, price list, grace days, restriction UX.
2. Control-plane schema + webhook receiver + idempotency store.
3. Instance entitlement check middleware (narrow surface).
4. Ops manual extension UI + audit.
5. Runbooks for outage and restore.

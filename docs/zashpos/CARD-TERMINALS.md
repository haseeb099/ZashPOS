# Card terminals (UK shortlist)

ZashPOS base plan includes **standalone PDQ** (`card-standalone-pdq`): staff confirm a physical terminal approval and optionally enter a reference. Optional add-on **ZashPay** adds **integrated PDQ** with durable attempts and settlement only after verified success.

## Simulator vs sandbox vs hardware

| Mode | What it is | Settles order payment? |
| --- | --- | --- |
| **Simulator** (`provider=simulator`) | In-app scripted approve/decline/cancel/timeout/late-success for staging and PHPUnit | Yes, only on verified success |
| **Provider sandbox** | Real vendor test API + Virtual/test terminal | Not wired in this release |
| **Physical hardware** | Merchant PDQ / cloud terminal | Not purchased or required for this phase |

The simulator is **not** a Dojo/SumUp/Worldpay sandbox and must be labelled as such in UI and API responses.

## Provider shortlist (docs only — no connector yet)

| Provider | API shape | Sandbox | Notes |
| --- | --- | --- | --- |
| **Dojo** | Payment intent + terminal session; amounts in minor units; matched refunds | `sk_sandbox_` on same host; `softwareHouse1` / `reseller1` | Strong UK PAC fit; production needs software-house/reseller IDs |
| **SumUp Cloud** | Cloud API → Solo; Virtual Solo for remote tests | Sandbox merchant + Virtual Solo | Affiliate key; good remote POS bridge |
| **Worldpay IPS Cloud** | Direct / SDK UK + ROI | Test terminals via Worldpay RM | Run a validation script before live |

## Before the first real connector

Decide and gather:

1. Chosen provider from the shortlist (or a documented alternative)
2. Sandbox API keys / merchant IDs (encrypted options or env — never log PAN/CVV/PIN/track)
3. Terminal model list and pairing steps for the pilot site
4. Webhook or poll contract for late/duplicate results (must update the same `zash_pay_terminal_attempts` row; never double-settle)

## Identifiers

- `card-standalone-pdq` — base plan; active by default after setup/migration
- `card-integrated-pdq` — ZashPay add-on; inactive until `zash_pay_integrated_enabled=yes`

Subscription billing remains out of scope ([CLIENT-SUBSCRIPTION-BILLING.md](CLIENT-SUBSCRIPTION-BILLING.md), D-021).

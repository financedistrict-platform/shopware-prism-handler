# 0.1.7
State-integrity hardening — closes several settlement state-machine gaps found while triaging a
QA-reported cancel bug. All are the same family: our settlement record desyncing from the base session.

- **Cancel is forward-only.** `POST /ucp/v1/checkout-sessions/{id}/cancel` on a Prism-settled checkout
  now returns a clean **422** instead of flipping the session to `canceled` (the funds moved on-chain
  and the order is placed + paid). For a not-yet-settled session, cancel additionally **drops any
  captured credential** before deferring to the base, so a later `complete` can't settle on-chain
  against a now-canceled checkout (a "paid, no order" desync). [QA-reported]
- **No double-settle under concurrent re-capture.** A credential `update` is now refused (**422**) while
  a settle is in flight (`settling`), not only when already `settled`. Previously a re-capture
  mid-settle reset the row to `pending` and let a second `complete` re-claim and settle **again**.
  Enforced both in the state machine (`mayCapture`) and atomically in the capture SQL.
- **Settlement is only ever completed from `settling`.** `markSettled` is guarded to the `settling`
  state, and `complete` refuses to place an order unless the record actually reached `settled`
  (defense-in-depth — no order is attributed without a confirmed settlement).

# 0.1.6
- Add the plugin icon — replace the blank placeholder `plugin.png` with the FD logo (256×256).
  No code or behavior change.

# 0.1.5
- **Bound the base dependency (F7):** `shopware/agentic-commerce` is now `>=1.0.0 <2.0.0` instead
  of `"*"`, so a future incompatible base major can't silently satisfy the constraint and break only
  at runtime.
- **Clean 422 on a malformed credential (L-1):** a credential missing/`non-object`
  `paymentPayload`/`paymentRequirements` now throws the SDK's `ValidationException` (HTTP **422**)
  instead of a bare `RuntimeException` (generic 500) — consistent with the F0/F2 422 paths.
- **Decouple checkout from Prism availability (F6):** the requirements augmenter wraps the Prism
  quote in a try/catch — on a Prism transport/HTTP error it logs a warning and **omits** our handler
  from the checkout response (the agent proceeds with other methods) rather than failing the whole
  response. Fail-closed is preserved: with no recorded offer, `complete` still won't settle. The
  HTTP timeout is now split — **10s** for the quote (checkout hot path) vs **60s** for the on-chain
  settle (which legitimately takes ~12s).
- **Bound the credential storage (F4):** `payment_payload` / `payment_requirements` go from
  `LONGTEXT` (4 GB) to `VARCHAR(4096)` (additive migration), plus an application-side ~4 KB check at
  the boundary that returns a clean 422 — closing an unbounded agent-controlled write (storage abuse
  / cheap DoS) without any silent-truncation risk. A real credential is ~1 KB.
- **Retention decision (F5):** the settled credential is **deliberately retained** (status/tx/network
  plus the signed payload) as audit/forensic data. There is no security downside — the x402
  authorization is single-use (nonce spent on-chain) and time-boxed (expired), and contains only
  already-public on-chain data.

# 0.1.4
- Mark all internal classes/interfaces `@internal` across `Core/`, `Application/`, and
  `Infrastructure/` (Shopware compliance — they were already `final`; this adds the "do not depend
  on these" signal for third parties). Documentation annotation only: no runtime, API, or behavior
  change, and our own test suite is unaffected (same Composer package). The plugin base class and
  migrations stay unmarked as framework entry points.

# 0.1.3
- Re-home the plugin into **Core / Application / Infrastructure** layers (ports + adapters) so the
  domain logic is unit-testable without a Shopware kernel. Behavior-preserving move; the
  externally-stable UCP id `xyz.fd.prism_payment` and all fixed UUIDs are unchanged.
- **Forward-only settlement (F0/F1):** the settlement record is now a forward-only state machine —
  `settled` is terminal. `capture()` can never revert a settled row, and the settle is claimed
  atomically (`pending → settling`, proceed only if one row transitioned), so a second `update`, a
  concurrent `complete`, or a crash-retry can no longer double-settle. A re-`update` on a paid
  checkout returns a clean **422** ("already paid").
- **Payment binding (F2):** the submitted `paymentRequirements` must be one we offered for the
  session (full-object match). The offer is **cart-driven** — recorded when shown and served
  verbatim while the cart amount/currency is unchanged; a change of amount re-quotes and
  **invalidates the prior signature** (the agent must re-sign). Fail-closed: no recorded offer ⇒ no
  settle.
- **We only answer to the Prism handler (D13):** if our payment was engaged but is no longer valid
  (cart changed, or a settle failed), `complete` **refuses** instead of placing an unpaid order; if
  the agent selects a different payment method, we release the claim and defer to the base flow.
- Add a **schema migration** (`offered_accepts`, nullable credential columns, default status,
  `settling` state) — additive, runs automatically on update.
- Add a **PHPUnit** Core test suite (state machine, accepts matcher, response parser, value objects)
  + CI; the pure suite runs on PHP + autoload alone (no kernel).

# 0.1.2
- Add an explicit `uninstall()` lifecycle hook (Shopware compliance). The payment method is always
  **deactivated, never deleted**, so placed orders keep a resolvable method (order-data integrity).
  The settlement table and order custom-field set are removed only on a hard uninstall — when the
  operator unticks "keep user data" — and preserved otherwise, honoring `keepUserData()`.
- Create the payment method **inactive** on install explicitly (instead of relying on the DB
  default); `activate()` flips it on. `update()` still preserves the operator's active/inactive choice.
- Document `supports()` returning `false` as a deliberate divergence from `AbstractPaymentHandler`'s
  optional capabilities (no refund/recurring — on-chain settlement is final). No behavior change.

# 0.1.1
- Harden the Shopware payment method: `pay()` now declines (throws) instead of being a no-op, so
  the method can never finalize an **unpaid** order if it is ever selected outside the agentic UCP
  flow (e.g. mistakenly assigned to a sales channel). Settlement still happens out-of-band via the
  UCP checkout adapter; the method remains intentionally non-storefront-selectable.
- Add a plugin `update()` lifecycle hook that re-runs the idempotent payment-method and order
  custom-field upserts, so definition changes apply on version upgrades (Shopware does not call
  `install()`/`activate()` on update).

# 0.1.0
- Initial release: Prism/x402 UCP payment handler (`xyz.fd.prism_payment`) — discovery descriptor,
  per-session payment requirements injected into the checkout response, and on-chain settlement
  during the UCP checkout `complete` step (capture credential on update, settle via Prism, mark the
  order transaction paid, record the on-chain reference).

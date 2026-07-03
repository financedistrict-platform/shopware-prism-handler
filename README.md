# FD Prism Payment (`FdPrismPayment`)

[![Package](https://github.com/financedistrict-platform/shopware-prism-handler/actions/workflows/package.yml/badge.svg)](https://github.com/financedistrict-platform/shopware-prism-handler/actions/workflows/package.yml)
[![Test](https://github.com/financedistrict-platform/shopware-prism-handler/actions/workflows/test.yml/badge.svg)](https://github.com/financedistrict-platform/shopware-prism-handler/actions/workflows/test.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

A Shopware 6 plugin that registers a **Prism / x402 [UCP](https://ucp.dev/) payment handler**
into the Agentic Commerce surface, so an AI shopping agent can pay with an **x402 wallet** and have
the order **settled on-chain** through the Prism gateway.

- **Externally-stable UCP handler id:** `xyz.fd.prism_payment` (never changes, even if the plugin
  technical name is renamed).
- **Zero UI:** no admin/storefront JS — settlement happens out-of-band during the UCP checkout flow.

## Why this matters

AI shopping agents are an emerging commerce channel, and they don't browse a storefront — they
speak structured protocols. The [Universal Commerce Protocol (UCP)](https://ucp.dev/) is how an
agent discovers a store, builds a checkout, and pays. This plugin teaches a Shopware store to settle
those agent payments in **stablecoins, on-chain**, via [Prism](https://1stdigital.com) — without
any storefront or admin UI work.

## What you get

| | |
|---|---|
| **Agent-payable checkout** | Advertises `xyz.fd.prism_payment` at `/.well-known/ucp` so agents can discover and select it |
| **x402 settlement** | Captures the x402 credential during UCP checkout and settles on-chain through the Prism gateway |
| **On-chain receipt** | Marks the order transaction paid and writes the on-chain transaction reference + block-explorer link to the order |
| **Zero UI** | No admin/storefront JS; settlement is out-of-band during the UCP flow |
| **Currency- & chain-agnostic** | Prism does all token/chain/x402 math — the plugin only relays |
| **Per-sales-channel config** | One Prism API key per sales channel, plus an env-driven developer mode |

## Documentation

Conceptual reference lives in the [wiki](https://github.com/financedistrict-platform/shopware-prism-handler/wiki):

- **[Architecture](https://github.com/financedistrict-platform/shopware-prism-handler/wiki/Architecture)** — layered design, the four integration seams, the forward-only settlement guards, and the data model.
- **[UCP and x402](https://github.com/financedistrict-platform/shopware-prism-handler/wiki/UCP-and-x402)** — what the handler advertises and accepts, and where it sits in the two protocols.
- **[Authentication](https://github.com/financedistrict-platform/shopware-prism-handler/wiki/Authentication)** — the trust model and every credential in the system.
- **[Shopware Compliance](https://github.com/financedistrict-platform/shopware-prism-handler/wiki/Shopware-Compliance)** — how the plugin meets Shopware's 6.7 plugin and payment-handler standards.

Install, configure, and build are below — this README is the quick start.

## Requirements

| | |
|---|---|
| Shopware | **≥ 6.7.10**, < 7.0 |
| PHP | ≥ 8.2 |
| Base extension | **`shopware/agentic-commerce` (SwagAgenticCommerce) installed *and active* first** |

The plugin decorates the base Agentic Commerce checkout adapter, so the base extension must be
present and active before you install this one.

## Install

**From the packaged zip** (recommended):

1. Get `FdPrismPayment.zip` — from the [Package GitHub Action](.github/workflows/package.yml)
   artifact, a release, or `shopware-cli extension zip .` locally.
2. Admin → **Extensions → My extensions → Upload extension** → choose the zip.
3. **Install**, then **Activate**.

**From the filesystem:**

```bash
cp -r FdPrismPayment <shopware>/custom/plugins/FdPrismPayment
bin/console plugin:refresh
bin/console plugin:install --activate FdPrismPayment
bin/console cache:clear
```

## Configure

Admin → **Extensions → FD Prism Payment → Configure** (set **per sales channel**):

| Field | Notes |
|---|---|
| **Prism API key** | Created in the Prism Console for this store's merchant account. Payments settle via the production Prism gateway at `https://prism-gw.fd.xyz`. |

## How it works

The plugin plugs a Prism/x402 handler into the base Agentic Commerce UCP surface: it advertises
`xyz.fd.prism_payment` at discovery, injects a per-session x402 offer into the checkout response,
captures the agent's signed credential on `update`, and settles it on-chain via Prism on `complete` —
marking the order paid and recording the transaction. No storefront or admin UI.

For the full design — the layered structure, the four integration seams, the forward-only settlement
guards (F0/F1/F2/D13), and the annotated request lifecycle — see the
**[Architecture](https://github.com/financedistrict-platform/shopware-prism-handler/wiki/Architecture)**
wiki page.

### Verify it's registered

```bash
curl -s https://<your-store>/.well-known/ucp | jq '.ucp.payment_handlers'
# -> contains "xyz.fd.prism_payment"
```

## Build / package

```bash
shopware-cli extension validate .          # store-compliance + structure checks
shopware-cli extension zip . --release     # -> FdPrismPayment.zip
```

CI does both on every push (see [`.github/workflows/package.yml`](.github/workflows/package.yml))
and uploads the zip as an artifact; tagging `v*` attaches it to a GitHub Release.

To cut a new version (version bump, changelog, tagging) and how Shopware applies it as an update,
see [`RELEASING.md`](RELEASING.md).

## Uninstall

```bash
bin/console plugin:uninstall FdPrismPayment
```

## Versioning & Releases

Pre-1.0 `0.x` versions. See [`RELEASING.md`](RELEASING.md) for how a version is cut (version bump +
changelog + tag) and how Shopware applies it as an update; release notes are in
[`CHANGELOG.md`](CHANGELOG.md).

## Contributing

Issues and pull requests welcome — see [CONTRIBUTING.md](CONTRIBUTING.md) for build, test, and PR
conventions, and [RELEASING.md](RELEASING.md) for how a version is cut and applied.

## License

[MIT](LICENSE) © Finance District.

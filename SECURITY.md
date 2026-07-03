# Security Policy

This plugin handles **payment settlement** (x402 credentials relayed to the Prism gateway, on-chain
settlement, order payment state). We take security reports seriously.

## Supported versions

The latest released `0.x` version receives security fixes. Pre-1.0, there is no long-term support
branch — please upgrade to the latest version before reporting.

## Reporting a vulnerability

Open an [issue](https://github.com/financedistrict-platform/shopware-prism-handler/issues)
describing the problem, the affected version(s), and a reproduction if you have one. We'll
acknowledge it and work with you on a fix.

## Scope

In scope: this plugin's code (`src/`) — the UCP seams, settlement state machine, credential
capture/relay, and payment-method behavior.

Out of scope: the Prism gateway itself, the base `shopware/agentic-commerce` extension, and
Shopware core — report those to their respective maintainers.

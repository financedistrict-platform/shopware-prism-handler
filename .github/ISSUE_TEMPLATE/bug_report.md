---
name: Bug report
about: Something isn't working as expected
title: ''
labels: bug
assignees: ''
---

## What happened

A clear description of the bug.

## What you expected

What you expected to happen instead.

## Environment

- **Shopware version:** (must be ≥ 6.7.10, < 7.0)
- **PHP version:** (≥ 8.2)
- **Plugin version:** (from `composer.json` / Extensions list)
- **Base extension `shopware/agentic-commerce` installed *and active*?** yes / no
- **Prism gateway URL:** (from the plugin's admin config; default `https://prism-gw.fd.xyz`)

## Reproduction

Steps to reproduce. Include relevant request/response bodies and logs.

```
# e.g. discovery output
curl -s https://<store>/.well-known/ucp | jq '.payment_handlers'
```

## Does it reproduce consistently?

yes / no / intermittent

# Contributing

Thanks for considering a contribution. This is an MIT-licensed Shopware 6 plugin that registers a
Prism / x402 [UCP](https://ucp.dev/) payment handler into the Agentic Commerce surface. Issues and
pull requests are welcome.

## Getting oriented

- **[README.md](README.md)** — what the plugin is, how to install/configure, and the four SDK seams
  it plugs into.
- **[RELEASING.md](RELEASING.md)** — how a version is cut and how Shopware applies it as an update
  (the `update()`-vs-`install()` gotcha, migrations, semver intent).
- **Architecture** — the code is split into `Core/` (pure domain — settlement state machine,
  accepts matcher, response parser, value objects; no Shopware kernel), `Application/` (the UCP
  seams + payment method), and `Infrastructure/` (DBAL, HTTP, system config). The pure Core is
  unit-testable without a kernel.

## Reporting issues

[File an issue](https://github.com/financedistrict-platform/shopware-prism-handler/issues). Useful
info:

- Shopware version (must be **≥ 6.7.10, < 7.0**) and PHP version (**≥ 8.2**).
- Whether the base **`shopware/agentic-commerce`** extension is installed **and active** — this
  plugin decorates its checkout adapter, so it won't function without it.
- What you ran, what you expected, what happened (logs, response bodies, `/.well-known/ucp` output).
- Whether it reproduces consistently.

## Branching

- **`develop` is the default and integration branch — base all work on it.**
- Branch a `feature/<short-name>` off `develop`, then open a PR **into `develop`**. (External
  contributors: fork, branch, PR into `develop`.)
- **`main` is release-only.** It's protected — no direct pushes — and advances only through a
  `develop → main` PR once CI (`core-tests` + `package`) is green. Releases are tagged on `main`
  (see [RELEASING.md](RELEASING.md)).

## Pull requests

Small and focused lands fastest.

- **One concern per PR.** A PR that fixes a bug *and* refactors is hard to review and risky to land.
- **Tests.** The pure Core suite runs on PHP + autoload alone — `composer test` (no
  `composer install` needed; the base extension is a sibling plugin, not a Packagist package). If
  you change behavior in `Core/`, add or extend a test. Infrastructure SQL guards are exercised by
  the e2e regression, not unit tests.
- **Validate the extension.** `shopware-cli extension validate .` must pass — CI runs it, but it's
  faster locally.
- **Add a CHANGELOG entry.** New `# x.y.z` block at the top of [CHANGELOG.md](CHANGELOG.md); the
  Shopware Store reads it and it's the human-facing update note. See [RELEASING.md](RELEASING.md)
  for the bump rules.
- **Never change the externally-stable ids.** The UCP handler id `xyz.fd.prism_payment` and the
  fixed UUIDs are an external contract — they stay constant across every version and any rename of
  the plugin's technical name.

For larger changes, open an issue first to discuss direction. Saves both sides time.

## Local development

```bash
git clone https://github.com/financedistrict-platform/shopware-prism-handler.git
cd shopware-prism-handler
composer install                              # dev deps (phpunit); runtime deps come from the platform
composer test                                 # pure Core unit suite
shopware-cli extension validate .             # store-compliance + structure checks
shopware-cli extension zip .                  # -> FdPrismPayment.zip (git mode: only tracked files)
```

To exercise the real install/uninstall path, upload the built zip into a local Shopware 6 store
(Admin → Extensions → Upload extension) with the base `shopware/agentic-commerce` extension active.

## Code style

`declare(strict_types=1)` everywhere. Explicit checks, throw on missing-required input, no
null-masking or silent fallbacks. Prism does all token/chain/x402 math — this plugin only relays
(currency- and chain-agnostic).

## License

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).

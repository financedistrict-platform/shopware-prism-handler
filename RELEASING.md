# Releasing FdPrismPayment

How to cut a new version, and how Shopware applies it as an update.

## Cut a release
1. **Bump the version** in `composer.json` (`"version"`). Shopware treats an uploaded zip as an
   *update* only when this is **higher** than the installed version — same/lower is ignored.
2. **Add a changelog entry** — a new `# x.y.z` block at the top of `CHANGELOG.md`. The Shopware
   Store reads this, and it's the human-facing update note.
3. If the change touches the **database** (tables/columns), add a **new migration** under
   `src/Migration/` with a timestamp **higher** than the existing ones. Shopware runs new
   migrations automatically on update.
4. If the change touches a **definition** the plugin upserts (the payment method, the order
   custom-field set, `config.xml`-backed config), make sure `FdPrismPayment::update()` re-runs the
   relevant idempotent upsert — see the gotcha below.
5. Commit, then tag and push:
   ```bash
   git tag vX.Y.Z && git push origin vX.Y.Z
   ```
   The **Package** workflow (`.github/workflows/package.yml`) validates the extension, builds
   `FdPrismPayment-vX.Y.Z.zip`, and attaches it to a GitHub Release for that tag.
6. **Apply the update:** Admin → Extensions → Upload extension → the new zip → **Update** → clear cache.

## How Shopware applies an update (and the gotchas)
**On a version upgrade Shopware calls `update(UpdateContext)` — NOT `install()` or `activate()`.** So:
- **Code** changes (PHP classes — the payment handler, the UCP adapter, etc.) take effect
  automatically after update + cache clear; they're resolved by class at runtime.
- **Definition** changes (payment-method fields, the custom-field set, `config.xml`) apply on update
  **only if `update()` re-runs the upsert**. `install()`/`activate()` do **not** fire on update.
- **DB** changes apply via **migrations** (new timestamp), run automatically on update.
- `activate()`/`deactivate()` run only on (de)activation — never put update-time logic there.

## Versioning intent (semver)
- **patch** (`0.1.0 → 0.1.1`): bug fix / hardening, no API or config change (e.g. the D13 `pay()` guard).
- **minor**: new, backward-compatible capability.
- **major**: breaking config or behavior change.

The **UCP handler id `xyz.fd.prism_payment` never changes** across any version — it's the
externally-stable contract, independent of the plugin version and the (renameable) plugin name.

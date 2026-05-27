# Changelog — @ausus/renderer-react

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

## [0.2.0-alpha.4] — 2026-05-27

### Added
- **`peerSchemaVersion` field declared** in `package.json` as `"^1.0.0"`.
  Formalises the renderer's ViewSchema compatibility contract with the
  AUSUS backend. The renderer accepts any backend release whose emitted
  `schemaVersion` (currently `1.0.0` in `Ausus\Runtime\ProjectionRenderer`)
  satisfies this semver range. Enforced by
  `scripts/check-renderer-alignment.sh` (CI step in `release-gate.yml`).

### Changed
- **Release alignment with backend.** Version bumped from `0.1.1` to
  `0.2.0-alpha.4` to align with the AUSUS PHP packages
  v0.2.0-alpha.4 hotfix release. No runtime code changes — pure version
  alignment + introduction of the compatibility contract field.

### Compatibility contract

The renderer now follows independent semver per ViewSchema compatibility
(cf. `docs/VERSIONING.md` §"Package version alignment"):

- The renderer is forward-compatible with any backend release whose
  `schemaVersion` satisfies `peerSchemaVersion` (`^1.0.0`).
- A backend release that does NOT change `schemaVersion` does NOT require
  a renderer release.
- A renderer release that adds new optional widgets/props does NOT require
  a backend bump.
- A `schemaVersion` major bump (e.g., `1.x` → `2.x`) requires a synchronised
  renderer release expanding `peerSchemaVersion` to include the new range.

## [Unreleased] — v0.1.x stabilisation

### Documentation
- **API stability declared explicitly.** The eight action-form helpers
  exported from `src/components.tsx` (`inputDefault`, `initialFor`,
  `isUnchanged`, `isRequired`, `shapeValue`, `validateInputs`,
  `buildCreatePayload`, `buildUpdatePayload`) are now marked as
  **stable** with `@public stable` TSDoc tags, and `README.md` carries
  a new "API stability" section spelling out the v0.1.x
  backward-compatibility guarantee, the permitted evolutions
  (additive type-union entries, additive optional descriptor keys,
  new helpers alongside the existing ones), and what counts as a
  breaking change.

## [0.1.0] — 2026-05-19

First public release. React 18 / 19 renderer for the AUSUS RFC-004
ViewSchema wire format. **No third-party UI library dependencies.**

### Added — public exports (10)
- `AususProvider`, `useAusus` — context for `(apiBaseUrl, tenant, fetcher)`
- `useViewSchema(projection, subject?)` — fetch + dispatch hook
- `useAction(actionFqn)` — invocation hook with `pending` + `lastError`
- `ViewSchemaConsumer` — top-level renderer; auto-dispatches by data shape
- `ListView`, `DetailView` — table / definition-list renderers
- `ActionModal` — confirmation + input form for Action invocation
- `WorkflowBadge` — colored state badge (gray/blue/green/red palette)
- `FieldDisplay` — type-dispatching field renderer

### Compatibility (verified in clean-room consumer)
| Environment | Result |
|---|---|
| Vanilla Node ESM (`node script.mjs`) | ✓ Works (NodeNext-emitted `.js` extensions) |
| `tsx` / `ts-node` | ✓ Works |
| Vite / esbuild / Rollup / Webpack | ✓ Works (16.3 kB bundled minified) |
| Next.js / Remix / Astro | ✓ Works (bundler-mediated) |
| Deno / Bun strict ESM | ✓ Expected to work (same emit) |

### Bundle metrics (`npm pack`)
- Tarball: **10.9 kB packed / 45.6 kB unpacked / 21 files** (includes LICENSE)
- Minified bundle (with React inlined): **16.3 kB** (5.7 kB gzipped)

### Dependencies
- **peer:** `react ^18 || ^19`, `react-dom ^18 || ^19`
- **runtime:** zero
- **engines:** Node ≥ 18

### License
MIT — see `LICENSE`.

[0.1.0]: https://github.com/ausus-framework/ausus/releases/tag/renderer-react-v0.1.0

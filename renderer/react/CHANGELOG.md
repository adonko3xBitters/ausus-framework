# Changelog — @ausus/renderer-react

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

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

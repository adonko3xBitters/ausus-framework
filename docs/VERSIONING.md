# Versioning policy

This document defines the backward-compatibility contract for AUSUS.
It is binding on every package shipped under the `ausus/` Composer
vendor and the `@ausus/` npm scope, and on the ViewSchema wire format
they exchange. Plugin authors and integrators rely on this document;
contributors reviewing API changes treat it as the gate.

## Scope

The contract covers:

- The PHP public API of every published Composer package.
- The TypeScript public API of `@ausus/renderer-react`.
- The ViewSchema JSON wire format (RFC-004) emitted by the HTTP API
  and consumed by the renderer.
- The HTTP route set documented in
  `docs-site/docs/reference/http-routes.md` — methods, paths, request
  bodies, response bodies, status codes, and the header set
  (`X-Tenant-ID`, `X-Actor-Id`, `X-Actor-Roles`).
- The kernel error taxonomy (`AususError` and its subclasses) and the
  mapping from each class to an HTTP status.

It does **not** cover:

- Symbols carrying `@internal` PHPDoc or absent from the documented
  public surface — these may change at any time, including in a patch
  release. The full internal set is enumerated in the package
  CHANGELOGs under the v0.1.x stabilization sweep.
- Storage schema migrations performed by `SchemaDeriver`. Storage
  format is durable per major version; migration paths are documented
  separately when one is required.
- The audit log row format (RFC-007) beyond the wire shape returned
  by future audit query endpoints, which do not exist in v0.1.x.
- CSS class names emitted by the renderer beyond the explicit
  selectors documented in `renderer/react/README.md`.

## Semantic versioning

AUSUS follows [SemVer 2.0.0](https://semver.org/) on each package
independently. For a version `MAJOR.MINOR.PATCH`:

- **PATCH** — bug fixes, documentation, annotation changes, and
  internal refactors. No change to any documented public symbol or
  wire field is allowed. CI green is sufficient.
- **MINOR** — additive changes to the public API or wire format.
  Existing consumers must keep compiling and running unchanged.
- **MAJOR** — removals, renames, or behaviour changes that break a
  documented contract. Shipped with a migration guide and a
  deprecation cycle (see below).

The pre-1.0 caveat: while AUSUS is on `0.x`, the SemVer specification
permits any release to break compatibility. AUSUS narrows that
permission — see "v0.1.x guarantees" below.

## v0.1.x guarantees

Despite the `0.x` major, the v0.1.x line is treated as a stable line
for the duration of its release window. Within v0.1.x:

- No documented public symbol is renamed or removed.
- No request/response field documented in the HTTP routes reference
  changes meaning, type, or required/optional status.
- No `schemaVersion`, `targetProfile`, or `metadata.locale` value
  emitted by the runtime changes.
- The `BuiltinEffect` enum string values
  (`kernel.builtin.create | .transition | .update`) are stable wire
  metadata and will not be renamed or repurposed.
- The kernel error class names and their HTTP status mapping (per
  `ErrorMapper`) do not change. Adding a new error class is permitted
  only if it maps to an HTTP status already in the documented set.

The v0.1.x line accepts two narrowly-scoped breaking changes that
shipped during stabilization, both flagged in the relevant package
CHANGELOGs as `### Changed (BREAKING)`:

1. `Router::resolveActor()` became fail-closed (no role fallback).
2. `ErrorMapper::classify()` corrected its short-name table; classes
   that previously routed silently to `500` now map to their
   documented status.

Both are explicitly recorded because they are corrections of behaviour
that the original release contract never promised. No further breaking
changes will be accepted on v0.1.x; subsequent corrections of similar
shape ship in v0.2.0.

## ViewSchema wire-format compatibility

The ViewSchema wire format is **additive-only** within a major
`schemaVersion`. Inside `1.x.y`:

- New optional keys may be added to any object.
- New entries may be added to type-discriminator unions (e.g. the
  `FieldDescriptor.type` set, the `ActionDescriptor` set).
- New values may appear in fields documented as opaque strings
  (`targetProfile`, `metadata.locale`, `data.pagination.nextCursor`).

Within `1.x.y`, the runtime will not:

- Rename or remove any documented key.
- Narrow the type of any documented field.
- Promote an optional field to required.
- Change the meaning of any documented field.

Consumers — including the React renderer and any third-party renderer
— must tolerate unknown keys and unknown enum values; they must not
fail-closed on an unrecognised `FieldDescriptor.type` (a defensive
fall-through is the documented behaviour). Reserved fields documented
in `docs-site/docs/frontend/viewschema.md` carry their forward shape
explicitly; producing code outside the framework should not emit
non-default values for them in v0.1.x.

A breaking change to the wire format requires a `schemaVersion` major
bump (`1.0.0` → `2.0.0`) and the corresponding renderer release.

## Renderer compatibility

`@ausus/renderer-react` accepts `schemaVersion` values within a
single major (today: `1.0.x`) and rejects anything else with a
deterministic error. The compatibility expectations are:

- The renderer is forward-compatible with additive wire changes
  within its accepted major — unknown keys are tolerated; new
  `FieldDescriptor.type` values fall through to the default control.
- The renderer is backward-compatible with older runtimes within its
  accepted major — fields the older runtime does not emit are
  rendered as their documented absent-case (the renderer never
  requires a field the runtime is not yet emitting).
- A renderer major bump is the only path that may drop support for an
  older `schemaVersion`.

Consumers pin a renderer version compatible with the runtime version
they deploy. The renderer's `peerDependencies` (React 18 / 19) follow
React's own support window; an upstream React major drop is treated
as a renderer major bump.

## Deprecation policy

Symbols and behaviours are deprecated for at least one minor cycle
before removal in a major. The deprecation channel depends on the
language:

- **PHP** — `E_USER_DEPRECATED` raised at the call site, plus
  `@deprecated` PHPDoc on the symbol. The PHPDoc states the
  replacement and the earliest major in which removal may occur.
  Precedent: the implicit workflow inference path raises
  `E_USER_DEPRECATED` and points to `EntityBuilder::workflow()`.
- **TypeScript** — `@deprecated` TSDoc on the symbol, plus a runtime
  `console.warn` once per process for any deprecated function call
  that survives type-checking.
- **HTTP** — a `Deprecation` response header on responses served by a
  deprecated route, naming the replacement route. The route remains
  fully functional for the remainder of its major.
- **ViewSchema** — a deprecated wire field is kept populated through
  the remainder of the `schemaVersion` major. A new field carrying
  the replacement value is added; both are emitted in parallel.

A deprecation does not earn a major bump on its own. The major bump
ships with the *removal*, not the *deprecation*.

## Package version alignment

The Composer packages and the `@ausus/renderer-react` npm package
are versioned together — the runtime, the persistence driver, the
HTTP API, and the renderer that consume the same ViewSchema
`schemaVersion` ship the same version number. A single `MAJOR.MINOR`
covers the matched set; only `PATCH` versions diverge per-package
when a fix is local to one package.

Concretely, for v0.1.x:

- Every package under `packages/*` and `renderer/react` carries the
  same `0.1.MINOR.PATCH` line.
- A package may publish a patch (`0.1.0` → `0.1.1`) without the rest
  of the set publishing; the matched set's `MAJOR.MINOR` stays
  pinned.
- A minor bump on any one package — anything that meets the
  additive-only definition for *any* component — ships across the
  entire set, even when the change is local. This keeps the
  per-package version a reliable indicator of which ViewSchema
  generation a deployment is on.

Reserved packages (`ausus/auth-bridge`, `ausus/audit-database`,
`ausus/tenancy-row`) ship empty composer manifests at the line's
current version to hold the name on Packagist. Their first
implementation release will rejoin the alignment scheme described
above.

## Recording changes

Every published change is reflected in the per-package
`CHANGELOG.md`. The `[Unreleased]` section accumulates entries
during the development of the next release; on cut, the section is
renamed to the version and dated. CHANGELOG categories follow
[Keep a Changelog](https://keepachangelog.com/) — `Added`, `Changed`,
`Deprecated`, `Removed`, `Fixed`, `Security`, plus `Documentation`
for annotation-only changes that do not affect runtime behaviour.

Breaking changes within a major are forbidden by the rules above; the
`### Changed (BREAKING)` category is reserved for the v0.1.x
corrections enumerated earlier and, going forward, for major-version
releases.

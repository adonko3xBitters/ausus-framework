# Changelog — ausus/api-http

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

## [1.1.0] — 2026-06-12

### Added
- **RFC-018.** `X-Actor-Attributes` request header parsed (fail-safe, scalar-only)
  into the resolved `StubActor`.
- Enforcement boundary: `getProjection` enforces the projection's declared
  read-role (`ProjectionNode.role`) before rendering.

## [1.0.1] — 2026-05-29

### Changed
- No runtime or HTTP surface change vs `1.0.0`. Patch release tagged
  in step with the v1.0.1 hotfix line — version alignment only.

## [1.0.0] — 2026-05-29

### Released
- First stable release. Bit-identical to `0.2.0-rc.1`. `Router`,
  `Emitter`, `ErrorMapper`, the three HTTP routes (`GET /_health`,
  `GET /projections/{fqn}`, `POST /actions/{fqn}`), and the
  `?limit` / `?offset` / `?filter.<field>.<op>` / `?sort=` query
  parameter contracts are frozen as stable public surface.

### Changed
- Package metadata: `ausus/kernel` and `ausus/runtime-default`
  require constraints move from `^0.2@alpha` to `^1.0`.

## [0.2.0-rc.1] — 2026-05-29

### Changed
- Release-candidate cut of v0.2.0-beta.1 with zero runtime change.
  HTTP surface (`?limit` / `?offset` / `?filter.*` / `?sort=`),
  400 paths, and CORS headers stay bit-identical to beta.1.

## [0.2.0-beta.1] — 2026-05-29

### Added
- `GET /projections/{fqn}` accepts five list-mode query parameters:
  - `?limit=N` (clamped to [1, 1000]) and `?offset=M` (non-negative).
  - `?filter.<field>.<op>=<value>` for `op ∈ {eq, in, contains}`. In-list
    values are comma-separated. Walked from the raw URI query to defeat
    PHP `parse_str`'s `.→_` rewrite. Whitelisted against the projection's
    declared fields.
  - `?sort=<field>:<dir>[,<field>:<dir>]` for multi-column ordering.
    Directions must be exactly `asc` or `desc`; capitalised forms are
    refused so the SQL adapter's pattern match cannot drift.
- Every malformed input returns `400 BadRequest` with a precise reason
  (`'… is not declared on projection …'`, `'allowed: …'`,
  `'<field>:<asc|desc>'`, `'more than once'`, `'must not be empty'`).
- Subject mode (`?subject=…`) ignores filter and sort parameters; the
  detail-view contract has no list-narrowing surface.

## [0.2.0-alpha.5] — 2026-05-28

### Changed
- No runtime changes in v0.2.0-alpha.5.
- Release validation coverage now includes clean-room starter installation
  checks.

## [0.2.0-alpha.4] — 2026-05-27

### Release engineering
- **No HTTP contract, route, header, body, or status-code change.**
  Zero code changes vs `v0.2.0-alpha.3`. The `Router`, `ErrorMapper`,
  `BadRequest`, marker interfaces dispatch, JSON envelope shape, and
  PSR-7/15 surface are all bit-identical. Consumers see the exact same
  wire.
- **Release validation alignment.** The api-http manifest is now
  validated by `composer validate --strict` (Composer 2.x — the
  deprecated `--no-check-version` flag is dropped from the CI gate).
- **Live install gate.** Every tagged release is exercised by
  `scripts/release-gate.sh` in live mode, including a real
  `composer create-project ausus/starter` from Packagist that pulls
  api-http transitively + verifies the L4 surface is callable.
  Regression of the wire would now fail the release-gate workflow
  before any tag promotion.

## [Unreleased] — v0.1.x stabilisation

### Documentation
- **API stability sweep.** `Ausus\Api\Http\BadRequest` now carries an
  `@internal` PHPDoc tag — it is the wire-error sentinel inside the
  Router and is mapped to `400 Bad Request` by `ErrorMapper`. Consumers
  MUST NOT catch or reference it; the public boundary is the HTTP
  status code plus the `{ error: { code, message } }` JSON body.

### Changed (BREAKING)
- **`Router::resolveActor()` is now fail-closed.** A missing or empty
  `X-Actor-Roles` header yields a roleless actor; every action with a
  `requireRole(...)` policy returns `403 PolicyDenied`. The previous
  behaviour substituted the HelloInvoice-specific role set
  (`invoice.creator, invoice.issuer, invoice.canceler, invoice.viewer`)
  — that fallback is gone. An authenticated gateway must set
  `X-Actor-Roles` from the verified session.
  See [Operations · Authenticated gateway](../../docs-site/docs/operations/authenticated-gateway.md).

### Fixed (BREAKING in status-code terms, correctness wins)
- **`ErrorMapper::classify()` short-name table corrected.** The map
  referenced `PolicyDeniedException` / `EffectFailure` — class names
  that never existed; the kernel's actual `PolicyDenied`,
  `EffectFailed`, `NotFound`, `UnknownAction`, `WorkflowSubjectNotFound`,
  `PolicySubjectRequired`, `ActorRequired`, `TenantContextRequired`,
  `WorkflowGuardDenied` and `AuditEmissionFailed` silently routed to
  `500 InternalError`. Each now maps to its documented status (403 /
  404 / 400 / 500 per [HTTP routes reference](../../docs-site/docs/reference/http-routes.md#status-codes)).

### Notes
- Clients that ignored the HTTP status and relied solely on
  `response.body.error.kind` are unaffected.
- The route layout, request body shapes, and `OPTIONS *` CORS
  behaviour are unchanged.

## [0.1.0] — 2026-05-19

First public release. L4 — the HTTP API surface for the AUSUS metadata
graph. Pure PSR-7/15. No framework dependency.

### Added
- **`Router`** — single `Psr\Http\Server\RequestHandlerInterface` that
  dispatches three real routes:
  - `GET  /_health`              — liveness + graph hash
  - `GET  /projections/{fqn}`    — RFC-004 ViewSchema with embedded
    data; `?subject=<id>` selects DetailView
  - `POST /actions/{fqn}`        — invoke Action; returns `ActionResult`
  - `OPTIONS *`                  — CORS preflight
- **`ErrorMapper`** — kernel exception taxonomy → HTTP status + envelope.
  Covers `BadRequest`, `MalformedDescriptor`, `PolicyDeniedException`,
  `TenantBoundaryViolation`, `WorkflowStateMismatch`,
  `ConcurrencyConflict`, `EffectFailure`, and a 500 fallback.
- **`BadRequest`** — internal protocol exception (missing header / bad
  body) feeding into the same error envelope.
- **`Emitter`** — minimal PSR-7 response → SAPI emit. Stand-in for
  `laminas-httphandlerrunner`; the demo `php -S` script uses it.

### Wire format frozen
- `X-Tenant-ID` header (not `X-Tenant`) — locked against
  `renderer/react/src/hooks.tsx`.
- `ActionResult` body: `{ok: true, outputs}` on success;
  `{ok: false, error: {kind, message}}` on failure.

### Compatibility
- PSR-7 `^1.1 || ^2.0`
- PSR-17 (`http-factory`) `^1.0`
- PSR-15 (`http-server-handler`, `http-server-middleware`) `^1.0`

### Dependencies
- PHP ≥ 8.3
- `psr/http-message`, `psr/http-factory`, `psr/http-server-handler`,
  `psr/http-server-middleware` (all pure interfaces)
- `ausus/kernel` 0.1.*
- `ausus/runtime-default` 0.1.*

### Security
The V0 transport ships with permissive CORS and a stub-actor model
read from `X-Actor-*` headers. Both are demo-only — replace with a real
auth middleware and a restrictive CORS allowlist before any non-local
deployment. See `README.md` §Security notes.

### License
MIT — see `LICENSE`.

[0.1.0]: https://github.com/adonko3xBitters/ausus-framework/releases/tag/api-http-v0.1.0

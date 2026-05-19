# Changelog — ausus/api-http

All notable changes documented per [Keep a Changelog](https://keepachangelog.com/).
Versioning follows [SemVer](https://semver.org/).

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

# ausus/runtime-default

L2 — the reference Runtime. Implements the Invoker chain, the Policy Engine, the Workflow runtime, and Effect dispatch.

## Owned RFC surfaces

- **RFC-001 §A-1.4 §8.2.1** — Invoker chain steps 1–5 (Tenant, Policy, Workflow, Effect, Audit).
- **RFC-005 §3–§13** — Policy Engine implementation (chain assembly, composition, two-tier cache, failure semantics, side-effect spy).
- **RFC-006** — Workflow runtime (state read, guard evaluation, transition validation, state mutation via default Effect).
- **RFC-013** — `Effect` interface dispatch + `EffectContext` construction.
- Built-in parameterized Policies from RFC-011 §8.3: `RoleRequired`, `PermissionRequired`, `RolesRequired`.
- Built-in default Effects from RFC-011 §8.2: `CreateEffect`, `TransitionEffect` (covers `Action::create` / `Action::transition` sugar).

## Allowed dependencies

- `ausus/kernel` only.
- `illuminate/contracts` + `illuminate/support` (Laravel facades, container — not Eloquent, not HTTP).

## Forbidden dependencies

- `illuminate/database` — Persistence is L3.
- `illuminate/http` — API Surface is L4.
- Any other AUSUS package.

## Public surface

- `Ausus\Runtime\RuntimeServiceProvider` (Laravel service provider).
- Implementation classes are private; the kernel's contracts are the only public consumption surface.

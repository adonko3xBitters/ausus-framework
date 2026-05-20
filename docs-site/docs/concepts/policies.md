---
id: policies
title: Policies
sidebar_label: Policies
description: Authorization decisions for actions in AUSUS.
---

# Policies

A **policy** decides whether an [actor](#actors) may invoke an action. Every
action in the graph has exactly one policy. The [runtime](../backend/runtime.md)
evaluates it before any data is touched.

## The `Policy` contract

```php
interface Policy
{
    public function evaluate(
        Actor $actor,
        string $actionFqn,
        ?Subject $subject,
        Context $context,
    ): Decision;
}
```

A policy returns a `Decision` enum value:

| Decision | Meaning |
|---|---|
| `Permit` | the action is allowed |
| `Deny` | the action is refused |
| `Abstain` | the policy makes no decision |

## Deny-by-default, fail-closed

The `PolicyEngine` applies two safety rules when it evaluates a policy:

- **Abstain becomes Deny.** If a policy returns `Abstain`, the engine treats it
  as `Deny`. There is no "allow because nothing objected".
- **An exception becomes Deny.** If a policy throws, the engine catches it and
  returns `Deny`. A broken policy fails *closed*, never open.

If the decision is anything other than `Permit`, the runtime throws
`PolicyDenied` and the invocation stops before the transaction opens.

## The built-in policy: `RoleRequired`

v0.1.0 ships one policy implementation: `RoleRequired`. It permits the action
if the actor holds a named role.

You attach it through the DSL with `->requireRole()`:

```php
'issue' => Action::transition('status', from: 'DRAFT', to: 'ISSUED')
             ->requireRole('invoice.issuer'),
```

The compiler creates a `PolicyNode` for the action that constructs a
`RoleRequired` policy with `role: 'invoice.issuer'`. At invocation time the
engine checks the role against the actor's role list.

## Actors {#actors}

An **actor** is who is performing the action. The `Actor` contract exposes a
ref, a role list, a permission list, and a canonical `roleHash()`.

v0.1.0 ships `StubActor` — a fixed in-memory actor:

```php
use Ausus\{StubActor, ActorRef};

$actor = new StubActor(
    new ActorRef('user', 'user42', 'acme'),
    ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'],
);
```

The HTTP API builds a `StubActor` from request headers (`X-Actor-Id`,
`X-Actor-Roles`) — see [The HTTP API](../backend/http-api.md).

## Current v0.1.0 limitations

- `RoleRequired` is the **only** policy implementation. There is no
  attribute-based policy, no permission-based policy, and no policy combination
  (all-of / any-of) in v0.1.0.
- There is **no authentication**. `StubActor` is a trusted, caller-supplied
  identity. Anything exposing the runtime — including the HTTP API — must put a
  real authentication layer in front of it. The reserved `ausus/auth-bridge`
  package is the planned home for that and ships no code in v0.1.0.
- Field-level and projection-level visibility policies are designed but not
  enforced in v0.1.0.

## Related

- [The Runtime](../backend/runtime.md) — where the policy check runs.
- [Entities, Fields & Actions](entities-fields-actions.md) — actions carry policies.
- [Error Reference](../reference/errors.md) — `PolicyDenied` and related errors.

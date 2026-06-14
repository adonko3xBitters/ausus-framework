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

## The `Policy` contract {#the-policy-contract}

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

## Deny-by-default, fail-closed {#deny-by-default-fail-closed}

The `PolicyEngine` applies two safety rules when it evaluates a policy:

- **Abstain becomes Deny.** If a policy returns `Abstain`, the engine treats it
  as `Deny`. There is no "allow because nothing objected".
- **An exception becomes Deny.** If a policy throws, the engine catches it and
  returns `Deny`. A broken policy fails *closed*, never open.

If the decision is anything other than `Permit`, the runtime throws
`PolicyDenied` and the invocation stops before the transaction opens.

## The built-in policy: `RoleRequired` {#the-built-in-policy-rolerequired}

AUSUS ships `RoleRequired` for **role-based** authorization. It permits the
action if the actor holds a named role.

You attach it through the DSL with `->requireRole()`:

```php
'issue' => Action::transition('status', from: 'DRAFT', to: 'ISSUED')
             ->requireRole('invoice.issuer'),
```

The compiler creates a `PolicyNode` for the action that constructs a
`RoleRequired` policy with `role: 'invoice.issuer'`. At invocation time the
engine checks the role against the actor's role list.

## Data-dependent authorization (RFC-018) {#data-dependent-authorization}

Role-based policies decide on identity alone. **RFC-018** adds *guards* that read
the **subject record** and **structured actor attributes** at authorization time
— so a rule like "an adjuster may approve a claim only up to their authority
limit" is expressed as configuration, not application code.

A guard is attached to an action with `->requireThat(Cond)`, alongside (not
instead of) `->requireRole()`:

```php
$dsl->actorAttributes(['authority_limit' => Field::integer()]);

'approve' => Action::transition('status', from: 'ASSESSING', to: 'APPROVED')
    ->requireRole('claims.adjuster')
    ->requireThat(Cond::lte(Fact::subject('claim_amount'), Fact::actor('authority_limit'))),
```

- **Facts.** `Fact::subject(field)` reads a field of the loaded subject entity;
  `Fact::actor(attribute)` reads a structured actor attribute (declared with
  `Dsl::actorAttributes(...)`); `Fact::input(key)` reads an action input.
- **Conditions.** `Cond::eq / ne / lt / lte / gt / gte / in`, composed with
  `Cond::and / or / not`.
- **Compile-time closure.** A guard referencing an unknown subject field, actor
  attribute, or input is rejected when the graph compiles
  (`DanglingFactReference`).
- **Fail-closed, in-transaction.** The subject is loaded before authorization and
  the guard runs **inside the action's transaction**, before any effect. A
  failing guard raises `PolicyDenied` (HTTP `403`) and rolls the transaction
  back.

Guards do not change the `Policy` contract above — they are an additional
authorization mechanism on the same action. Actor attributes are seeded through
`ApplicationConfig::actorAttributes(...)` and, over HTTP, an `X-Actor-Attributes`
header parsed fail-safe.

## Actors {#actors}

An **actor** is who is performing the action. The `Actor` contract exposes a
ref, a role list, a permission list, and a canonical `roleHash()`.

AUSUS ships `StubActor` — a fixed in-memory actor:

```php
use Ausus\{StubActor, ActorRef};

$actor = new StubActor(
    new ActorRef('user', 'user42', 'acme'),
    ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'],
);
```

The HTTP API builds a `StubActor` from request headers (`X-Actor-Id`,
`X-Actor-Roles`) — see [The HTTP API](../backend/http-api.md).

## Current limitations {#current-v010-limitations}

- Authorization is **role-based** (`RoleRequired`) and, since RFC-018,
  **data-dependent** (`requireThat` guards, above). There is no separate
  permission-based policy class.
- There is **no authentication**. `StubActor` is a trusted, caller-supplied
  identity. Anything exposing the runtime — including the HTTP API — must put a
  real authentication layer in front of it. The reserved `ausus/auth-bridge`
  package is the planned home for that and ships no code.
- Field-level and projection-level visibility policies are designed but not
  enforced.

## Related {#related}

- [The Runtime](../backend/runtime.md) — where the policy check runs.
- [Entities, Fields & Actions](entities-fields-actions.md) — actions carry policies.
- [Error Reference](../reference/errors.md) — `PolicyDenied` and related errors.

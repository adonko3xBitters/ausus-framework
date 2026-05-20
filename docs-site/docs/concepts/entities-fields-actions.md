---
id: entities-fields-actions
title: Entities, Fields & Actions
sidebar_label: Entities, Fields & Actions
description: The core data and behaviour nodes of the metadata graph.
---

# Entities, Fields & Actions

Entities, fields, and actions are the data-and-behaviour core of an AUSUS
domain. This page describes them as graph concepts; the builder methods that
declare them are in [The PHP DSL](../backend/php-dsl.md).

## Entities

An **entity** is a domain record type — `invoice`, `customer`, `order`. In the
graph it is an `EntityNode` with:

- `fqn` — `{plugin}.{local name}`, e.g. `billing.invoice`.
- `tenantScoped` — always `true` in v0.1.0; every entity is tenant-scoped.
- `fields` — the field list (system + user fields).
- `actionFqns`, `projectionFqns`, `workflowFqns` — what is attached to it.

One entity maps to one SQL table; see [SQL Persistence](../backend/sql-persistence.md).

## Fields

A **field** (`FieldNode`) has a `name`, a `type`, a `nullable` flag, optional
`typeOptions`, and an optional `default`.

### Field types

v0.1.0 supports eight field types:

| Type | Purpose | Options |
|---|---|---|
| `string` | text | `maxLength` |
| `integer` | whole numbers | — |
| `enum` | a fixed set of string values | `options` |
| `money` | an amount + currency | `currency` |
| `datetime` | a timestamp | — |
| `identity` | the record id (system) | — |
| `version` | the optimistic-lock token (system) | — |
| `system_string` | internal string column (system) | — |

`identity`, `version`, and `system_string` are **system types** — you do not
declare them; the kernel injects them.

### System fields {#system-fields}

Every entity automatically gets five system fields, in this order:

| Field | Type | Role |
|---|---|---|
| `id` | `identity` | primary key — a 26-char ULID |
| `tenant_id` | `system_string` | the owning tenant |
| `_version` | `version` | optimistic-concurrency token (a ULID) |
| `created_at` | `datetime` | creation timestamp |
| `updated_at` | `datetime` | last-update timestamp |

You declare only your **domain** fields. The `money` type stores the amount in
the column and resolves currency from the field's `typeOptions`.

## Actions

An **action** is the only way to change data. In the graph an `ActionNode` has
an `fqn`, the `entityFqn` it belongs to, a `policyFqn`, an `effectClass`, an
input list, a `subjectRequired` flag, and a `kind`.

v0.1.0 has two action kinds, both backed by **built-in effects**:

### Create actions

A create action inserts a new record. It declares which fields are inputs:

```php
'create' => Action::create('number', 'customer_name', 'amount')
              ->requireRole('invoice.creator'),
```

- `subjectRequired` is `false` — there is no existing record to act on.
- The effect is `kernel.builtin.create`.
- If the entity has an enum field with a default (a workflow state field), the
  create effect applies that default automatically.

### Transition actions

A transition action moves a record between workflow states:

```php
'issue' => Action::transition('status', from: 'DRAFT', to: 'ISSUED')
             ->stamp('issued_at')
             ->requireRole('invoice.issuer'),
```

- `subjectRequired` is `true` — you must pass a `Reference` to the record.
- The effect is `kernel.builtin.transition`.
- `->stamp('issued_at')` writes the current timestamp to a field as part of the
  transition.
- `->andTransition(...)` adds another `(from, to)` pair to the same action — so
  one action can be legal from several source states.

Transition actions are what [workflows](workflows.md) are built from.

## Current v0.1.0 limitations

- The only action kinds are **create** and **transition**, both built-in.
  There is no built-in `update` or `delete` action, and custom
  `Effect`-class actions, while supported by the dispatcher, are not exercised
  by the v0.1.0 sample domain.
- Field validation is limited to **type coercion and presence**. `maxLength`
  and `unique` are recorded in the graph but are not enforced as runtime
  validation rules in v0.1.0.
- All entities are tenant-scoped; there is no global (un-scoped) entity.

## Related

- [Workflows](workflows.md) — what transition actions drive.
- [Policies](policies.md) — the authorization on each action.
- [The PHP DSL](../backend/php-dsl.md) — the builder reference.

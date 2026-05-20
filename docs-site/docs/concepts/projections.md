---
id: projections
title: Projections
sidebar_label: Projections
description: Read-shaped views over an entity.
---

# Projections

A **projection** is a read-shaped view over an entity — the fields and actions
that a particular screen needs. Projections are how AUSUS turns a domain into
something a UI can render without the UI knowing the domain.

## Declaring a projection

A projection is declared on the entity, naming the fields and actions it
exposes:

```php
$dsl->entity('invoice')
    ->fields([ /* ... */ ])
    ->actions([ /* ... */ ])
    ->projection('summary',
        fields:  ['id', 'number', 'customer_name', 'status', 'amount'],
        actions: ['create', 'cancel'],
        role:    'invoice.viewer')
    ->projection('detail',
        fields:  ['id', 'number', 'customer_name', 'status', 'amount', 'issued_at', 'created_at', 'updated_at'],
        actions: ['issue', 'cancel'],
        role:    'invoice.viewer');
```

Each projection becomes a `ProjectionNode` in the graph with an FQN of
`{entity}.{projection name}`, e.g. `billing.invoice.summary`. The optional
`role` attaches a read policy.

## Rendering a projection

`ProjectionRenderer` turns a projection into a [ViewSchema](../frontend/viewschema.md)
— a JSON-shaped description of fields, actions, and data:

```php
use Ausus\Runtime\ProjectionRenderer;

$renderer = new ProjectionRenderer($graph, $driver, $tenant);

// List form — no subject:
$list = $renderer->render('billing.invoice.summary');
// $list['data']['items'] -> array of rows for the tenant

// Detail form — with a subject Reference:
$detail = $renderer->render('billing.invoice.detail', $invoiceRef);
// $detail['data']['item'] -> a single row
```

The shape of `data` tells the consumer which view to draw:

- `data.items` present → a **list** view.
- `data.item` present → a **detail** view.

The [React renderer](../frontend/react-renderer.md)'s `ViewSchemaConsumer`
dispatches on exactly this.

## List vs detail

| | List | Detail |
|---|---|---|
| Call | `render($fqn)` | `render($fqn, $subjectRef)` |
| `data` | `{ items: [...], pagination }` | `{ item: {...} \| null }` |
| Typical actions | list-level (e.g. `create`) | item-level (e.g. `issue`, `cancel`) |

The renderer separates **list actions** (no subject required) from **item
actions** (subject required) automatically based on each action's
`subjectRequired` flag.

## Current v0.1.0 limitations

- **List rendering returns all rows for the tenant.** There is no filtering,
  sorting, or real pagination — the ViewSchema `filters` array is empty and
  `pagination.nextCursor` is always `null`.
- The list query path reads rows directly rather than through the `Repository`
  contract (the v0.1.0 `Repository` has no `findMany`). This is a known
  internal shortcut, documented as a v0.1.0 finding.
- Field labels are derived mechanically from field names (`customer_name` →
  "Customer name"). There is no label localization or override in v0.1.0.

## Related

- [ViewSchema](../frontend/viewschema.md) — the wire format a projection renders to.
- [The React renderer](../frontend/react-renderer.md) — the client that consumes it.
- [The HTTP API](../backend/http-api.md) — serves projections over HTTP.

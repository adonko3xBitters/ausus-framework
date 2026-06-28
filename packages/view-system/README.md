# ausus/view-system

AUSUS **2.0** — View System (L5). Pure presentation **metadata**: `ViewDefinition`
→ `PageDefinition` → `SectionDefinition`, where a section shows a projection or an
action. No compile, no hash, no repository — it only assembles metadata for the
React Renderer to consume.

## Installation

```bash
composer require ausus/view-system:^2.0
```

## Dependencies

- PHP 8.3+
- none

## Public surface

- `Ausus\View\ViewDefinition`, `Ausus\View\PageDefinition`,
  `Ausus\View\SectionDefinition` — value objects with `toArray()`.
- `Ausus\View\ViewRegistry` — collects view definitions.

## Minimal example

```php
<?php
use Ausus\View\ViewDefinition;

$view = new ViewDefinition(/* entity, pages, sections */);
$json = $view->toArray(); // consumed by @ausus/react-renderer
```

## Documentation

See the canonical reference [`docs/v2/`](../../docs/v2/README.md) and the
[Quick Start](../../docs/v2/QUICKSTART.md).

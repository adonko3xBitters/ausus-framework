# @ausus/react-renderer

AUSUS **2.0** — React Renderer (L5). Renders entities, projections and actions
discovered from the `api-runtime` HTTP contract **only**. It has no knowledge of
the kernel, engine, compiler, or repository — give it a base URL and it renders.

## Installation

```bash
npm install @ausus/react-renderer react react-dom
```

## Dependencies

- Node 18+
- peer: `react` ^18 || ^19, `react-dom` ^18 || ^19

## Public surface

- `RuntimeClient` — `new RuntimeClient({ baseUrl })` (uses global `fetch`, or pass
  a custom `fetchFn`).
- `RendererApp` — `<RendererApp client={client} entities={entities} />`.
- `EntityRegistry`, `ProjectionTable`, `ActionForm`, `ProjectionPage`,
  `EntityPage`, and the view-model helpers (`buildProjectionTable`,
  `buildActionForm`).

## Minimal example

```tsx
import { RuntimeClient, RendererApp } from '@ausus/react-renderer';

const client = new RuntimeClient({ baseUrl: 'http://localhost:8080' });

export function App() {
  return <RendererApp client={client} entities={['customer']} />;
}
```

## Documentation

See the canonical reference [`docs/v2/`](../../docs/v2/README.md) and the
[Quick Start](../../docs/v2/QUICKSTART.md).

// IMPLEMENTATION-003 — top-level app: builds navigation from the configured
// entity list and renders the selected entity page. Adding a newly compiled
// entity is a data change (the `entities` prop), never a code change.

import { useState, type ReactElement } from 'react';
import type { RuntimeClient } from '../api/RuntimeClient.ts';
import { EntityPage } from '../pages/EntityPage.tsx';

export interface RendererAppProps {
  client: RuntimeClient;
  entities: string[];
}

export function RendererApp({ client, entities }: RendererAppProps): ReactElement {
  const [selected, setSelected] = useState<string>(entities[0] ?? '');

  return (
    <div className="ausus-renderer">
      <nav className="ausus-nav">
        <h1>AUSUS</h1>
        <ul>
          {entities.map((entity) => (
            <li key={entity}>
              <button
                type="button"
                aria-current={entity === selected}
                onClick={() => setSelected(entity)}
              >
                {entity}
              </button>
            </li>
          ))}
        </ul>
      </nav>
      <main>{selected ? <EntityPage entity={selected} client={client} /> : <p>No entities.</p>}</main>
    </div>
  );
}

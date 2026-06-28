// IMPLEMENTATION-003 — entity page: discovers an entity and renders its actions
// (as forms) and projections (as pages). Fully generic — driven by discovery.

import { useEffect, useState, type ReactElement } from 'react';
import type { RuntimeClient } from '../api/RuntimeClient.ts';
import type { EntitySchemaResponse } from '../types.ts';
import { ActionForm } from '../components/ActionForm.tsx';
import { ProjectionPage } from './ProjectionPage.tsx';

export interface EntityPageProps {
  entity: string;
  client: RuntimeClient;
}

export function EntityPage({ entity, client }: EntityPageProps): ReactElement {
  const [schema, setSchema] = useState<EntitySchemaResponse | null>(null);
  const [refreshKey, setRefreshKey] = useState<number>(0);

  useEffect(() => {
    let active = true;
    void client.getEntitySchema(entity).then((res) => {
      if (active && res.status === 200) {
        setSchema(res.body);
      }
    });
    return () => {
      active = false;
    };
  }, [entity, client]);

  if (!schema) {
    return <p>Discovering “{entity}”…</p>;
  }

  return (
    <article className="ausus-entity-page">
      <h2>{schema.identity}</h2>

      <section className="ausus-actions">
        <h3>Actions</h3>
        {schema.actions.map((action) => (
          <ActionForm
            key={action.name}
            entity={entity}
            meta={action}
            client={client}
            onInvoked={() => setRefreshKey((k) => k + 1)}
          />
        ))}
      </section>

      <section className="ausus-projections">
        <h3>Projections</h3>
        {schema.projections.map((projection) => (
          <ProjectionPage
            key={`${projection.name}:${refreshKey}`}
            entity={entity}
            meta={projection}
            client={client}
          />
        ))}
      </section>
    </article>
  );
}

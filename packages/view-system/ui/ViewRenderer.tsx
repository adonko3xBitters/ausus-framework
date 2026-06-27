// IMPLEMENTATION-004 — renders a ViewDefinition by DELEGATING to the existing
// React Renderer (IMPLEMENTATION-003), which is imported, never modified. A
// projection section reuses <ProjectionPage>; an action section reuses
// <ActionForm>. No business knowledge: everything is driven by the view JSON
// and entity discovery through the api-runtime.

import { useEffect, useState, type ReactElement } from 'react';
import {
  ActionForm,
  ProjectionPage,
  type RuntimeClient,
  type ActionMeta,
  type EntitySchemaResponse,
  type ProjectionMeta,
} from '@ausus/react-renderer';
import { flattenView, viewNavigation, type FlatSection, type ViewJson } from './viewModel.ts';

export interface ViewRendererProps {
  view: ViewJson;
  client: RuntimeClient;
}

function SectionView({ section, client }: { section: FlatSection; client: RuntimeClient }): ReactElement {
  const [schema, setSchema] = useState<EntitySchemaResponse | null>(null);

  useEffect(() => {
    let active = true;
    void client.getEntitySchema(section.entity).then((res) => {
      if (active && res.status === 200) {
        setSchema(res.body);
      }
    });
    return () => {
      active = false;
    };
  }, [section.entity, client]);

  if (!schema) {
    return <p>Loading “{section.entity}”…</p>;
  }

  if (section.kind === 'projection') {
    const meta: ProjectionMeta | undefined = schema.projections.find((p) => p.name === section.name);
    return meta ? (
      <ProjectionPage entity={section.entity} meta={meta} client={client} />
    ) : (
      <p>unknown projection “{section.name}”</p>
    );
  }

  const meta: ActionMeta | undefined = schema.actions.find((a) => a.name === section.name);
  return meta ? (
    <ActionForm entity={section.entity} meta={meta} client={client} />
  ) : (
    <p>unknown action “{section.name}”</p>
  );
}

export function ViewRenderer({ view, client }: ViewRendererProps): ReactElement {
  const flat = flattenView(view);
  const pages = viewNavigation(view);
  const [selected, setSelected] = useState<string>(pages[0]?.identity ?? '');
  const page = flat.pages.find((p) => p.identity === selected) ?? flat.pages[0];

  return (
    <div className="ausus-view">
      <h1>{flat.title}</h1>
      <nav className="ausus-view-nav">
        {pages.map((p) => (
          <button
            key={p.identity}
            type="button"
            aria-current={p.identity === selected}
            onClick={() => setSelected(p.identity)}
          >
            {p.title}
          </button>
        ))}
      </nav>
      {page && (
        <main>
          {page.sections.map((section, index) => (
            <section key={`${section.kind}:${section.name}:${index}`} className="ausus-view-section">
              <h2>{section.title}</h2>
              <SectionView section={section} client={client} />
            </section>
          ))}
        </main>
      )}
    </div>
  );
}

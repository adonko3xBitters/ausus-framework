// IMPLEMENTATION-003 — projection page: reads a projection and renders its
// table, with a refresh control. Generic over entity/projection.

import { useCallback, useEffect, useState, type ReactElement } from 'react';
import type { RuntimeClient } from '../api/RuntimeClient.ts';
import type { ProjectionMeta } from '../types.ts';
import { ProjectionTable } from '../components/ProjectionTable.tsx';

export interface ProjectionPageProps {
  entity: string;
  meta: ProjectionMeta;
  client: RuntimeClient;
}

export function ProjectionPage({ entity, meta, client }: ProjectionPageProps): ReactElement {
  const [rows, setRows] = useState<Array<Record<string, unknown>>>([]);
  const [loading, setLoading] = useState<boolean>(true);

  const refresh = useCallback(async () => {
    setLoading(true);
    const result = await client.readProjection(entity, meta.name);
    setRows(result.status === 200 ? result.body.rows : []);
    setLoading(false);
  }, [entity, meta.name, client]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  return (
    <section className="ausus-projection-page">
      <header>
        <h3>{entity} / {meta.name}</h3>
        <button type="button" onClick={() => void refresh()}>Refresh</button>
      </header>
      {loading ? <p>Loading…</p> : <ProjectionTable meta={meta} rows={rows} />}
    </section>
  );
}

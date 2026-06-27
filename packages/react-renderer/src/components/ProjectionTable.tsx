// IMPLEMENTATION-003 — generic projection table. Renders the pure TableModel;
// holds no entity-specific knowledge.

import { type ReactElement } from 'react';
import type { ProjectionMeta } from '../types.ts';
import { buildProjectionTable, cellText } from '../view/projectionModel.ts';

export interface ProjectionTableProps {
  meta: ProjectionMeta;
  rows: Array<Record<string, unknown>>;
}

export function ProjectionTable({ meta, rows }: ProjectionTableProps): ReactElement {
  const model = buildProjectionTable(meta, rows);

  if (model.rows.length === 0) {
    return <p className="ausus-empty">No rows for projection “{meta.name}”.</p>;
  }

  return (
    <table className="ausus-projection-table">
      <thead>
        <tr>
          {model.columns.map((column) => (
            <th key={column}>{column}</th>
          ))}
        </tr>
      </thead>
      <tbody>
        {model.rows.map((row, index) => (
          <tr key={index}>
            {model.columns.map((column) => (
              <td key={column}>{cellText(row[column])}</td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  );
}

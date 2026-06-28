// IMPLEMENTATION-003 — pure view-model for projections (no React, no business
// knowledge). Columns are derived generically from the projection metadata and
// the actual rows returned by the API (visibility-omitted fields simply do not
// appear). This is the logic the ProjectionTable component renders.

import type { ProjectionMeta } from '../types.ts';

export interface TableModel {
  columns: string[];
  rows: Array<Record<string, unknown>>;
}

export function buildProjectionTable(
  meta: ProjectionMeta,
  rows: Array<Record<string, unknown>>,
): TableModel {
  const seen = new Set<string>();
  const columns: string[] = [];

  // Declared shape first: exposed fields, then expand keys.
  for (const f of meta.fields) {
    if (!seen.has(f.field)) {
      seen.add(f.field);
      columns.push(f.field);
    }
  }
  for (const e of meta.expand) {
    if (!seen.has(e.via)) {
      seen.add(e.via);
      columns.push(e.via);
    }
  }
  // Any extra keys actually present in rows (defensive, still generic).
  for (const row of rows) {
    for (const key of Object.keys(row)) {
      if (!seen.has(key)) {
        seen.add(key);
        columns.push(key);
      }
    }
  }

  return { columns, rows };
}

export function cellText(value: unknown): string {
  if (value === null || value === undefined) {
    return '';
  }
  if (typeof value === 'object') {
    return JSON.stringify(value);
  }
  return String(value);
}

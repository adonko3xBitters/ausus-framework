// IMPLEMENTATION-003 — L3 Projection Query, client side.
//
// A typed builder that emits the FLAT query-string params consumed by
// RuntimeClient.readProjection() — i.e. the same encoding the api-runtime
// QueryStringParser understands. It is a pure encoder: it performs NO
// validation. The runtime ProjectionQuery is the single fail-closed authority,
// so an unknown field/operator surfaces as a 400 from the server, never a
// silently-dropped clause here.
//
// Encoding (kept byte-compatible with packages/api-runtime QueryStringParser):
//   where   → "field:op:value,..."   (comma = AND; value omitted for valueless ops)
//   orderBy → "field:dir,..."
//   limit / offset → decimal strings
//
// OR / nested groups are intentionally NOT expressible here: the v1 HTTP
// shorthand is AND-only by design (the structured read() params remain the
// escape hatch on the server). This keeps the public read contract small.

export type FilterOperator =
  | 'eq'
  | 'ne'
  | 'lt'
  | 'lte'
  | 'gt'
  | 'gte'
  | 'contains'
  | 'startsWith'
  | 'endsWith'
  | 'isNull'
  | 'isNotNull';

const VALUELESS: ReadonlyArray<FilterOperator> = ['isNull', 'isNotNull'];

export interface FilterCondition {
  field: string;
  op: FilterOperator;
  value?: string | number | boolean;
}

export interface SortSpec {
  field: string;
  dir?: 'asc' | 'desc';
}

// L4 — KPI aggregations.
export type AggregateOp = 'count' | 'sum' | 'avg' | 'min' | 'max';

export interface AggregateSpec {
  op: AggregateOp;
  /** Required for sum/avg/min/max; optional for count (omitted ⇒ row count). */
  field?: string;
  /** Result alias under `aggregates` in the response. */
  as: string;
}

export interface ProjectionQuerySpec {
  /** AND-joined conditions (matches the HTTP shorthand semantics). */
  where?: FilterCondition[];
  orderBy?: SortSpec[];
  limit?: number;
  offset?: number;
  /** L4 — aggregates computed over the full WHERE-filtered set (ignores limit/offset). */
  aggregate?: AggregateSpec[];
}

/**
 * Encode a typed query spec into the flat param map that
 * RuntimeClient.readProjection() serialises to the query string.
 */
export function buildProjectionParams(spec: ProjectionQuerySpec): Record<string, string> {
  const params: Record<string, string> = {};

  if (spec.where && spec.where.length > 0) {
    params.where = spec.where
      .map((c) =>
        VALUELESS.includes(c.op) || c.value === undefined
          ? `${c.field}:${c.op}`
          : `${c.field}:${c.op}:${String(c.value)}`,
      )
      .join(',');
  }

  if (spec.orderBy && spec.orderBy.length > 0) {
    params.orderBy = spec.orderBy.map((s) => `${s.field}:${s.dir ?? 'asc'}`).join(',');
  }

  if (spec.limit !== undefined) {
    params.limit = String(spec.limit);
  }
  if (spec.offset !== undefined) {
    params.offset = String(spec.offset);
  }

  if (spec.aggregate && spec.aggregate.length > 0) {
    params.aggregate = spec.aggregate
      .map((a) => (a.field === undefined ? `${a.op}:${a.as}` : `${a.op}:${a.field}:${a.as}`))
      .join(',');
  }

  return params;
}

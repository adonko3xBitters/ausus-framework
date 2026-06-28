// IMPLEMENTATION-003 — the api-runtime HTTP contract, as seen by the Renderer.
// These mirror exactly the JSON shapes returned by Ausus\Api\Runtime (L4).
// The Renderer knows ONLY these shapes — never any kernel/engine type.

export type ActionKind = 'create' | 'update' | 'transition';

export interface TransitionMeta {
  field: string;
  from: string[];
  to: string;
}

export interface ActionMeta {
  name: string;
  kind: ActionKind;
  inputs: string[];
  guarded: boolean;
  transition: TransitionMeta | null;
}

export interface ProjectionFieldMeta {
  field: string;
  restricted: boolean;
}

export interface ExpandMeta {
  via: string;
  projection: string;
}

export interface ProjectionMeta {
  name: string;
  fields: ProjectionFieldMeta[];
  expand: ExpandMeta[];
}

// GET /api/entities/{entity}
export interface EntitySchemaResponse {
  identity: string;
  tenantScoped: boolean;
  actions: ActionMeta[];
  projections: ProjectionMeta[];
}

// GET /api/entities/{entity}/projections/{projection}
export interface ProjectionResponse {
  rows: Array<Record<string, unknown>>;
}

// POST /api/entities/{entity}/actions/{action}
export interface InvokeResponse {
  reference: { tenantId: string; entityFqn: string; identityHandle: string };
  version: string;
  fields: Record<string, unknown>;
}

export interface ApiResponse<T> {
  status: number;
  body: T;
}

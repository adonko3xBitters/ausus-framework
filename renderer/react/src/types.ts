/**
 * RFC-004 ViewSchema wire-format types (V0 subset).
 * Pure TypeScript; no runtime dependency.
 */

export interface Reference {
  tenantId: string;
  entityFqn: string;
  identityHandle: string;
}

export interface FieldDescriptor {
  name: string;
  type:
    | "string"
    | "integer"
    | "datetime"
    | "enum"
    | "money"
    | "identity"
    | "version"
    | "system_string";
  label: string;
  typeOptions?: {
    maxLength?: number;
    currency?: string;
    options?: string[];
  };
}

export interface ActionDescriptor {
  fqn: string;
  name: string;
  label: string;
  subjectRequired: boolean;
  inputs?: FieldDescriptor[];
  confirmation?: { required: boolean; prompt?: string };
}

export interface FilterDescriptor {
  name: string;
  field: string;
  operator: string;
  label: string;
}

export interface ViewSchemaMetadata {
  projection: string;
  entity: string;
  tenant: string;
  locale: string;
  generatedAt: string;
}

export interface ViewSchema {
  schemaVersion: string;
  targetProfile: string;
  metadata: ViewSchemaMetadata;
  fields: FieldDescriptor[];
  actions: ActionDescriptor[];
  filters: FilterDescriptor[];
  data:
    | { items: Record<string, unknown>[]; pagination?: { nextCursor: string | null; pageSize: number } }
    | { item: Record<string, unknown> | null }
    | null;
}

export type ActionResult =
  | { ok: true; outputs: Record<string, unknown> }
  | { ok: false; error: { kind: string; message: string } };

export type Fetcher = (url: string, init?: RequestInit) => Promise<Response>;

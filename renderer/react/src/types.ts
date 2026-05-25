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
    | "boolean"
    | "identity"
    | "version"
    | "system_string";
  label: string;
  typeOptions?: {
    maxLength?: number;
    currency?: string;
    options?: string[];
  };
  /**
   * The following fields are only meaningful on ActionDescriptor.inputs; the
   * runtime emits them so the renderer can build a working form. They are
   * **always optional** so this type stays usable for read-only projection
   * fields that carry no form semantics.
   */
  required?: boolean;
  nullable?: boolean;
  default?: unknown;
}

export interface ActionDescriptor {
  fqn: string;
  name: string;
  label: string;
  subjectRequired: boolean;
  inputs?: FieldDescriptor[];
  /**
   * Set on **update** action descriptors when the projection is a detail
   * shape (`data.item`); maps each input field name to its current value
   * from the rendered subject. The renderer's ActionModal uses this to
   * prefill the form. Absent on create / transition descriptors and on
   * list-view renderings.
   */
  initialValues?: Record<string, unknown>;
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

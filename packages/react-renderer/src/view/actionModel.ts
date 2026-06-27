// IMPLEMENTATION-003 — pure view-model for actions (no React, no business
// knowledge). Form fields are derived from the action's declared inputs;
// non-create actions get a subject 'id' field. This is the logic the
// ActionForm component renders, validates and submits.

import type { ActionMeta } from '../types.ts';

export interface FormField {
  name: string;
  subject: boolean;
}

export interface FormModel {
  action: string;
  kind: string;
  fields: FormField[];
}

export function buildActionForm(meta: ActionMeta): FormModel {
  const fields: FormField[] = [];
  // Transition/update operate on an existing subject identified by 'id'.
  if (meta.kind !== 'create') {
    fields.push({ name: 'id', subject: true });
  }
  for (const input of meta.inputs) {
    fields.push({ name: input, subject: false });
  }
  return { action: meta.name, kind: meta.kind, fields };
}

/** Minimal validation: the subject identity is required for non-create actions. */
export function validate(model: FormModel, values: Record<string, string>): string[] {
  const errors: string[] = [];
  for (const field of model.fields) {
    if (field.subject && !(values[field.name] ?? '').trim()) {
      errors.push(`${field.name} is required`);
    }
  }
  return errors;
}

/** Build the `inputs` payload, omitting empties and coercing scalar literals. */
export function buildInputs(
  model: FormModel,
  values: Record<string, string>,
): Record<string, unknown> {
  const inputs: Record<string, unknown> = {};
  for (const field of model.fields) {
    const raw = values[field.name];
    if (raw === undefined || raw === '') {
      continue;
    }
    inputs[field.name] = coerce(raw);
  }
  return inputs;
}

function coerce(raw: string): unknown {
  if (/^-?\d+$/.test(raw)) {
    return Number.parseInt(raw, 10);
  }
  if (/^-?\d*\.\d+$/.test(raw)) {
    return Number.parseFloat(raw);
  }
  if (raw === 'true') {
    return true;
  }
  if (raw === 'false') {
    return false;
  }
  return raw;
}

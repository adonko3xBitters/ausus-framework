import React from "react";
import { useState, useEffect, ReactNode } from "react";
import { useAction } from "./hooks.js";
import type {
  ActionDescriptor, FieldDescriptor, Reference, ViewSchema,
} from "./types.js";

// ─────────────────────────────────────────────────────────────────────────────
// WorkflowBadge — colored badge for enum state fields.
// V0 color palette: gray / blue / green / red per RFC-012 default theme.
// ─────────────────────────────────────────────────────────────────────────────

const BADGE_PALETTE: Record<string, string> = {
  DRAFT:     "ausus-badge ausus-badge--gray",
  ISSUED:    "ausus-badge ausus-badge--blue",
  PAID:      "ausus-badge ausus-badge--green",
  CANCELLED: "ausus-badge ausus-badge--red",
};

export function WorkflowBadge({ value }: { value: string | null | undefined }) {
  const v = value ?? "?";
  const cls = BADGE_PALETTE[v] ?? "ausus-badge ausus-badge--default";
  return <span className={cls}>{v}</span>;
}

// ─────────────────────────────────────────────────────────────────────────────
// Field cell — read-only renderer dispatched by field.type and hints.
// ─────────────────────────────────────────────────────────────────────────────

function formatMoney(value: any, opts?: FieldDescriptor["typeOptions"]): string {
  if (value == null) return "";
  const amount =
    typeof value === "object" && value !== null && "amount" in value
      ? String((value as any).amount)
      : String(value);
  const currency =
    (typeof value === "object" && value !== null && "currency" in value && (value as any).currency) ||
    opts?.currency || "";
  const n = Number(amount);
  const formatted = Number.isFinite(n) ? n.toFixed(2) : amount;
  return currency ? `${currency} ${formatted}` : formatted;
}

function isWorkflowStateField(field: FieldDescriptor): boolean {
  // V0 heuristic: enum field named "status" is the workflow state.
  // M2 will use an explicit field.hints.role marker.
  return field.type === "enum" && field.name === "status";
}

export function FieldDisplay({ field, value }: { field: FieldDescriptor; value: any }) {
  if (isWorkflowStateField(field)) return <WorkflowBadge value={String(value ?? "")} />;
  switch (field.type) {
    case "money":    return <span className="ausus-cell ausus-cell--money">{formatMoney(value, field.typeOptions)}</span>;
    case "enum":     return <span className="ausus-cell ausus-cell--enum">{String(value ?? "")}</span>;
    case "datetime": return <span className="ausus-cell ausus-cell--datetime">{value ? String(value) : "—"}</span>;
    case "integer":  return <span className="ausus-cell ausus-cell--num">{String(value ?? "")}</span>;
    default:         return <span className="ausus-cell">{String(value ?? "")}</span>;
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// ActionModal — confirmation + input form for an Action invocation.
//
// The form is generated entirely from `action.inputs` (FieldDescriptor[])
// emitted by the runtime. Per-type inputs (text, number, select, checkbox,
// datetime, money), required validation, and shape-correct payload assembly
// are all metadata-driven — no entity-specific UI lives here.
//
// Action helper public API:
//   inputDefault, initialFor, isUnchanged, isRequired, shapeValue,
//   validateInputs, buildCreatePayload, buildUpdatePayload.
//
// Stability for v0.1.x: **stable**. These helpers are the supported
// extension seam for consumers building a custom action UI on top of the
// renderer's `FieldDescriptor` / `ActionDescriptor` types. Their names,
// arities, and return shapes are covered by the v0.1.x backward-compatibility
// guarantee (see `renderer/react/README.md`, "API stability"). Additions to
// the `FieldDescriptor.type` union may add branches; existing branches will
// keep returning the same shape.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Initial form value for an input, honoring its declared default.
 *
 * @public stable
 */
export function inputDefault(input: FieldDescriptor): unknown {
  if (input.default !== undefined && input.default !== null) {
    return input.default;
  }
  switch (input.type) {
    case "boolean": return false;
    case "enum":
      // Required enum with options → preselect the first option, so the form
      // always submits a valid value. Optional enum starts empty.
      if (input.required && input.typeOptions?.options?.length) {
        return input.typeOptions.options[0];
      }
      return "";
    default: return "";
  }
}

/**
 * Initial form value for an input when the descriptor carries
 * `initialValues` (update action). Falls through to {@link inputDefault}
 * when no prefill is available. Compound `money` values flatten to the
 * amount string for the input box; `shapeValue` reconstitutes the tuple
 * on submit.
 *
 * @public stable
 */
export function initialFor(input: FieldDescriptor, prefill: unknown): unknown {
  if (prefill === undefined || prefill === null) return inputDefault(input);
  if (
    input.type === "money"
    && typeof prefill === "object"
    && prefill !== null
    && "amount" in (prefill as Record<string, unknown>)
  ) {
    return String((prefill as { amount: unknown }).amount);
  }
  return prefill;
}

/**
 * Equality test that knows about money's `{amount, currency}` compound shape.
 *
 * @public stable
 */
export function isUnchanged(
  input: FieldDescriptor,
  current: unknown,
  initial: unknown,
): boolean {
  if (current === initial) return true;
  if (current == null && initial == null) return true;
  if (
    input.type === "money"
    && typeof current === "object" && current !== null
    && typeof initial === "object" && initial !== null
  ) {
    const a = current as { amount: unknown; currency: unknown };
    const b = initial as { amount: unknown; currency: unknown };
    return String(a.amount) === String(b.amount) && a.currency === b.currency;
  }
  return false;
}

/**
 * Build the payload for a **create** action: every non-empty shaped value
 * is included.
 *
 * @public stable
 */
export function buildCreatePayload(
  inputs: FieldDescriptor[],
  values: Record<string, unknown>,
): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const f of inputs) {
    const shaped = shapeValue(f, values[f.name]);
    if (shaped !== undefined) out[f.name] = shaped;
  }
  return out;
}

/**
 * Build the payload for an **update** action: only fields whose current
 * value differs from `initialValues` are included. Empty/cleared inputs
 * are treated as "untouched" (PATCH no-op for that key); explicit
 * null-on-nullable clearing is a deferred concern, see ADR-0002 §12.4.
 *
 * @public stable
 */
export function buildUpdatePayload(
  inputs: FieldDescriptor[],
  values: Record<string, unknown>,
  initialValues: Record<string, unknown>,
): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const f of inputs) {
    const shaped = shapeValue(f, values[f.name]);
    if (shaped === undefined) continue;
    if (isUnchanged(f, shaped, initialValues[f.name])) continue;
    out[f.name] = shaped;
  }
  return out;
}

/**
 * Required when the runtime says so; fall back to "not explicitly nullable".
 *
 * @public stable
 */
export function isRequired(input: FieldDescriptor): boolean {
  if (typeof input.required === "boolean") return input.required;
  return input.nullable !== true;
}

/**
 * Shape a raw form value into the payload form the runtime expects.
 *
 * @public stable
 */
export function shapeValue(input: FieldDescriptor, raw: unknown): unknown {
  if (raw === undefined || raw === null || raw === "") return undefined;
  switch (input.type) {
    case "integer": {
      const n = typeof raw === "number" ? raw : Number(raw);
      return Number.isFinite(n) ? Math.trunc(n) : undefined;
    }
    case "money":
      return { amount: String(raw), currency: input.typeOptions?.currency ?? "USD" };
    case "boolean":
      return Boolean(raw);
    default:
      return raw;
  }
}

/**
 * Synchronous validation of the current form values.
 *
 * @public stable
 */
export function validateInputs(
  inputs: FieldDescriptor[],
  values: Record<string, unknown>,
): Record<string, string> {
  const errors: Record<string, string> = {};
  for (const f of inputs) {
    if (!isRequired(f)) continue;
    const v = values[f.name];
    if (v === undefined || v === null || v === "") {
      errors[f.name] = `${f.label} is required.`;
      continue;
    }
    if (f.type === "integer" && !Number.isFinite(Number(v))) {
      errors[f.name] = `${f.label} must be a whole number.`;
    }
    if (f.type === "money" && !Number.isFinite(Number(v))) {
      errors[f.name] = `${f.label} must be a number.`;
    }
  }
  return errors;
}

interface InputControlProps {
  input: FieldDescriptor;
  value: unknown;
  onChange: (next: unknown) => void;
  disabled: boolean;
  invalid: boolean;
}

/** One typed form control. Defensive: unknown types fall back to text. */
function InputControl({ input, value, onChange, disabled, invalid }: InputControlProps) {
  const cls = "ausus-input" + (invalid ? " ausus-input--invalid" : "");
  switch (input.type) {
    case "enum": {
      const options = input.typeOptions?.options ?? [];
      return (
        <select
          className={cls}
          value={String(value ?? "")}
          onChange={e => onChange(e.target.value)}
          disabled={disabled}
        >
          {!isRequired(input) && <option value="">—</option>}
          {options.map(opt => (
            <option key={opt} value={opt}>{opt}</option>
          ))}
        </select>
      );
    }
    case "integer":
      return (
        <input
          className={cls}
          type="number"
          step="1"
          value={String(value ?? "")}
          onChange={e => onChange(e.target.value)}
          disabled={disabled}
        />
      );
    case "money":
      return (
        <span className="ausus-money-input">
          <input
            className={cls}
            type="number"
            step="0.01"
            min="0"
            value={String(value ?? "")}
            onChange={e => onChange(e.target.value)}
            disabled={disabled}
          />
          <span className="ausus-money-input__currency">
            {input.typeOptions?.currency ?? ""}
          </span>
        </span>
      );
    case "boolean":
      return (
        <input
          className={cls}
          type="checkbox"
          checked={Boolean(value)}
          onChange={e => onChange(e.target.checked)}
          disabled={disabled}
        />
      );
    case "datetime":
      return (
        <input
          className={cls}
          type="datetime-local"
          value={String(value ?? "")}
          onChange={e => onChange(e.target.value)}
          disabled={disabled}
        />
      );
    default:
      return (
        <input
          className={cls}
          type="text"
          maxLength={input.typeOptions?.maxLength}
          value={String(value ?? "")}
          onChange={e => onChange(e.target.value)}
          disabled={disabled}
        />
      );
  }
}

export function ActionModal(props: {
  action: ActionDescriptor;
  subject?: Reference;
  onClose: () => void;
  onSuccess?: (outputs: Record<string, unknown>) => void;
}) {
  const { invoke, pending, lastError } = useAction(props.action.fqn);
  const inputs = props.action.inputs ?? [];
  const hasInputs = inputs.length > 0;
  // Presence of `initialValues` is how the runtime signals "this is an
  // update action with a prefilled subject" (ADR-0002 §8). The form
  // prefills, and the submit handler sends only changed fields.
  const initialValues = props.action.initialValues ?? null;
  const isUpdate = initialValues !== null;

  const [values, setValues] = useState<Record<string, unknown>>(() => {
    const initial: Record<string, unknown> = {};
    for (const f of inputs) {
      initial[f.name] = isUpdate
        ? initialFor(f, initialValues[f.name])
        : inputDefault(f);
    }
    return initial;
  });
  const [errors, setErrors] = useState<Record<string, string>>({});

  function setOne(name: string, next: unknown) {
    setValues(prev => ({ ...prev, [name]: next }));
    if (errors[name]) {
      const { [name]: _drop, ...rest } = errors;
      setErrors(rest);
    }
  }

  async function handleConfirm() {
    if (hasInputs) {
      const errs = validateInputs(inputs, values);
      if (Object.keys(errs).length > 0) {
        setErrors(errs);
        return;
      }
      setErrors({});
    }
    const payload = isUpdate
      ? buildUpdatePayload(inputs, values, initialValues!)
      : buildCreatePayload(inputs, values);
    const result = await invoke({ subject: props.subject, inputs: payload });
    if (result.ok) {
      props.onSuccess?.(result.outputs);
      props.onClose();
    }
  }

  return (
    <div className="ausus-modal-backdrop" role="dialog" aria-modal="true">
      <div className="ausus-modal">
        <h2 className="ausus-modal__title">{props.action.label}</h2>
        {!hasInputs && (
          <p className="ausus-modal__body">
            {props.action.confirmation?.prompt ?? `Confirm ${props.action.label}?`}
          </p>
        )}
        {hasInputs && (
          <div className="ausus-modal__inputs">
            {inputs.map(input => {
              const err = errors[input.name];
              return (
                <label key={input.name} className="ausus-modal__input">
                  <span>
                    {input.label}
                    {isRequired(input) && <span className="ausus-required" aria-label="required"> *</span>}
                  </span>
                  <InputControl
                    input={input}
                    value={values[input.name]}
                    onChange={next => setOne(input.name, next)}
                    disabled={pending}
                    invalid={Boolean(err)}
                  />
                  {err && (
                    <span className="ausus-input-error" role="alert">{err}</span>
                  )}
                </label>
              );
            })}
          </div>
        )}
        {lastError && (
          <div className="ausus-modal__error" role="alert">
            <strong>{props.action.label} failed:</strong> {lastError.message}
          </div>
        )}
        <div className="ausus-modal__actions">
          <button type="button" className="ausus-btn ausus-btn--ghost" onClick={props.onClose} disabled={pending}>
            Discard
          </button>
          <button type="button" className="ausus-btn ausus-btn--primary" onClick={handleConfirm} disabled={pending}>
            {pending ? "…" : props.action.label}
          </button>
        </div>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// ActionButton — single button; orchestrates modal flow.
// ─────────────────────────────────────────────────────────────────────────────

function ActionButton(props: {
  action: ActionDescriptor;
  subject?: Reference;
  onSuccess?: (outputs: Record<string, unknown>) => void;
}) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button
        type="button"
        className="ausus-btn ausus-btn--action"
        onClick={() => setOpen(true)}
      >
        {props.action.label}
      </button>
      {open && (
        <ActionModal
          action={props.action}
          subject={props.subject}
          onClose={() => setOpen(false)}
          onSuccess={props.onSuccess}
        />
      )}
    </>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// ActionBar — renders Action buttons for a row or page.
// ─────────────────────────────────────────────────────────────────────────────

function ActionBar(props: {
  actions: ActionDescriptor[];
  subject?: Reference;
  onSuccess?: () => void;
}) {
  return (
    <div className="ausus-action-bar">
      {props.actions.map(a => (
        <ActionButton
          key={a.fqn}
          action={a}
          subject={a.subjectRequired ? props.subject : undefined}
          onSuccess={() => props.onSuccess?.()}
        />
      ))}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// ListView — table-based list rendering.
// ─────────────────────────────────────────────────────────────────────────────

export function ListView(props: {
  schema: ViewSchema;
  onRefetch: () => void;
}) {
  const data = props.schema.data as { items: Record<string, unknown>[] };
  const items = data?.items ?? [];

  const listActions = props.schema.actions.filter(a => !a.subjectRequired);
  const itemActions = props.schema.actions.filter(a =>  a.subjectRequired);

  return (
    <section className="ausus-list">
      <header className="ausus-list__header">
        <h1 className="ausus-list__title">{props.schema.metadata.projection}</h1>
        <ActionBar actions={listActions} onSuccess={props.onRefetch} />
      </header>
      <table className="ausus-table">
        <thead>
          <tr>
            {props.schema.fields.map(f => (
              <th key={f.name}>{f.label}</th>
            ))}
            {itemActions.length > 0 && <th className="ausus-table__actions-col">Actions</th>}
          </tr>
        </thead>
        <tbody>
          {items.length === 0 && (
            <tr>
              <td colSpan={props.schema.fields.length + (itemActions.length > 0 ? 1 : 0)} className="ausus-empty">
                No items.
              </td>
            </tr>
          )}
          {items.map((row, i) => {
            const subject: Reference = {
              tenantId: props.schema.metadata.tenant,
              entityFqn: props.schema.metadata.entity,
              identityHandle: String(row["id"] ?? ""),
            };
            return (
              <tr key={subject.identityHandle || i}>
                {props.schema.fields.map(f => (
                  <td key={f.name}><FieldDisplay field={f} value={row[f.name]} /></td>
                ))}
                {itemActions.length > 0 && (
                  <td className="ausus-table__actions-cell">
                    <ActionBar
                      actions={itemActions}
                      subject={subject}
                      onSuccess={props.onRefetch}
                    />
                  </td>
                )}
              </tr>
            );
          })}
        </tbody>
      </table>
    </section>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// DetailView — read-only field list + action bar.
// ─────────────────────────────────────────────────────────────────────────────

export function DetailView(props: {
  schema: ViewSchema;
  subject: Reference;
  onRefetch: () => void;
}) {
  const data = props.schema.data as { item: Record<string, unknown> | null };
  const item = data?.item;

  if (!item) {
    return <div className="ausus-empty">Item not found.</div>;
  }

  return (
    <section className="ausus-detail">
      <header className="ausus-detail__header">
        <h1 className="ausus-detail__title">{props.schema.metadata.projection}</h1>
      </header>
      <dl className="ausus-detail__list">
        {props.schema.fields.map(f => (
          <div className="ausus-detail__row" key={f.name}>
            <dt>{f.label}</dt>
            <dd><FieldDisplay field={f} value={item[f.name]} /></dd>
          </div>
        ))}
      </dl>
      <footer className="ausus-detail__footer">
        <ActionBar
          actions={props.schema.actions}
          subject={props.subject}
          onSuccess={props.onRefetch}
        />
      </footer>
    </section>
  );
}

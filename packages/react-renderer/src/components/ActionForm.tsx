// IMPLEMENTATION-003 — generic action form. Auto-generates fields from the
// action metadata, validates minimally, and submits via the RuntimeClient.

import { useState, type FormEvent, type ReactElement } from 'react';
import type { RuntimeClient } from '../api/RuntimeClient.ts';
import type { ActionMeta } from '../types.ts';
import { buildActionForm, buildInputs, validate } from '../view/actionModel.ts';

export interface ActionFormProps {
  entity: string;
  meta: ActionMeta;
  client: RuntimeClient;
  onInvoked?: () => void;
}

export function ActionForm({ entity, meta, client, onInvoked }: ActionFormProps): ReactElement {
  const model = buildActionForm(meta);
  const [values, setValues] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<string[]>([]);
  const [status, setStatus] = useState<string>('');

  async function onSubmit(event: FormEvent): Promise<void> {
    event.preventDefault();
    const validationErrors = validate(model, values);
    if (validationErrors.length > 0) {
      setErrors(validationErrors);
      return;
    }
    setErrors([]);
    setStatus('…');
    const result = await client.invokeAction(entity, meta.name, buildInputs(model, values));
    if (result.status === 200) {
      setStatus('ok');
      setValues({});
      onInvoked?.();
    } else {
      const message =
        result.body && typeof result.body === 'object' && 'error' in result.body
          ? String((result.body as { error: unknown }).error)
          : `HTTP ${result.status}`;
      setStatus(`error: ${message}`);
    }
  }

  return (
    <form className="ausus-action-form" onSubmit={onSubmit}>
      <h4>
        {meta.name} <small>({meta.kind})</small>
      </h4>
      {model.fields.map((field) => (
        <label key={field.name}>
          <span>{field.name}{field.subject ? ' *' : ''}</span>
          <input
            name={field.name}
            value={values[field.name] ?? ''}
            onChange={(e) => setValues({ ...values, [field.name]: e.target.value })}
          />
        </label>
      ))}
      {errors.map((error) => (
        <p key={error} className="ausus-error">{error}</p>
      ))}
      <button type="submit">Run {meta.name}</button>
      {status && <span className="ausus-status">{status}</span>}
    </form>
  );
}

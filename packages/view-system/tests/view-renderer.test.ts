// IMPLEMENTATION-004 — the Renderer consumes a ViewDefinition (Tests 6–9).
// Drives the EXISTING React Renderer (IMPLEMENTATION-003) primitives — imported,
// never modified — per flattened section, against a faithful api-runtime mock.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { RuntimeClient, type FetchLike } from '../../react-renderer/src/api/RuntimeClient.ts';
import { buildProjectionTable } from '../../react-renderer/src/view/projectionModel.ts';
import { buildActionForm, buildInputs } from '../../react-renderer/src/view/actionModel.ts';
import type { EntitySchemaResponse } from '../../react-renderer/src/types.ts';
import { flattenView, viewNavigation, type FlatSection, type ViewJson } from '../ui/viewModel.ts';

// ── api-runtime mock (same contract as IMPLEMENTATION-002/003) ──────────────
function makeApi() {
  const invoices: Array<{ amount: number; buyer?: string }> = [];
  const customers: Record<string, { name: string }> = {};
  let counter = 0;
  const schemas: Record<string, EntitySchemaResponse> = {
    invoice: {
      identity: 'invoice',
      tenantScoped: true,
      actions: [
        { name: 'create', kind: 'create', inputs: ['amount', 'buyer'], guarded: true, transition: null },
      ],
      projections: [
        { name: 'board', fields: [{ field: 'amount', restricted: false }], expand: [{ via: 'buyer', projection: 'card' }] },
      ],
    },
    customer: {
      identity: 'customer',
      tenantScoped: true,
      actions: [{ name: 'create', kind: 'create', inputs: ['name'], guarded: false, transition: null }],
      projections: [{ name: 'card', fields: [{ field: 'name', restricted: false }], expand: [] }],
    },
  };
  const json = (status: number, body: unknown) => ({ status, json: async () => body });
  const fetchFn: FetchLike = async (url, init = {}) => {
    const path = new URL(url, 'http://test').pathname;
    const method = (init.method ?? 'GET').toUpperCase();
    const body = init.body ? JSON.parse(init.body) : {};
    let m: RegExpMatchArray | null;
    if (method === 'GET' && (m = path.match(/^\/api\/entities\/([^/]+)$/))) {
      const s = schemas[m[1]];
      return json(s ? 200 : 404, s ?? { error: `no schema for entity '${m[1]}'` });
    }
    if (method === 'GET' && (m = path.match(/^\/api\/entities\/([^/]+)\/projections\/([^/]+)$/))) {
      if (m[1] === 'invoice' && m[2] === 'board') {
        return json(200, {
          rows: invoices.map((i) => {
            const r: Record<string, unknown> = { amount: i.amount };
            if (i.buyer && customers[i.buyer]) r.buyer = { name: customers[i.buyer].name };
            return r;
          }),
        });
      }
      if (m[1] === 'customer' && m[2] === 'card') {
        return json(200, { rows: Object.values(customers).map((c) => ({ name: c.name })) });
      }
      return json(404, { error: 'unknown projection' });
    }
    if (method === 'POST' && (m = path.match(/^\/api\/entities\/([^/]+)\/actions\/([^/]+)$/))) {
      const inputs = body.inputs ?? {};
      if (m[1] === 'customer' && m[2] === 'create') {
        const id = `mem-${++counter}`;
        customers[id] = { name: String(inputs.name) };
        return json(200, { reference: { tenantId: 'acme', entityFqn: 'customer', identityHandle: id }, version: '1', fields: { name: inputs.name } });
      }
      if (m[1] === 'invoice' && m[2] === 'create') {
        const id = `mem-${++counter}`;
        invoices.push({ amount: inputs.amount, buyer: inputs.buyer });
        return json(200, { reference: { tenantId: 'acme', entityFqn: 'invoice', identityHandle: id }, version: '1', fields: { amount: inputs.amount, buyer: inputs.buyer } });
      }
      return json(404, { error: 'unknown action' });
    }
    return json(404, { error: 'no route' });
  };
  return { fetchFn, store: { invoices, customers } };
}

// A ViewDefinition exactly as Ausus\View\ViewDefinition::toArray() emits it.
const invoiceView: ViewJson = {
  identity: 'invoice-view',
  title: 'Invoices',
  pages: [
    {
      identity: 'board',
      title: 'Board',
      sections: [
        { title: 'All invoices', entity: 'invoice', kind: 'projection', projection: 'board', action: null },
        { title: 'Create invoice', entity: 'invoice', kind: 'action', projection: null, action: 'create' },
      ],
    },
  ],
};

const client = (api: ReturnType<typeof makeApi>) =>
  new RuntimeClient({ headers: { 'X-Tenant-ID': 'acme', 'X-Actor-Type': 'user' }, fetchFn: api.fetchFn });

// Section drivers — exactly what ViewRenderer's <SectionView> does, headless.
async function discover(c: RuntimeClient, entity: string): Promise<EntitySchemaResponse> {
  return (await c.getEntitySchema(entity)).body;
}
async function renderProjection(c: RuntimeClient, section: FlatSection) {
  const schema = await discover(c, section.entity);
  const meta = schema.projections.find((p) => p.name === section.name)!;
  const rows = (await c.readProjection(section.entity, section.name)).body.rows;
  return buildProjectionTable(meta, rows);
}
async function renderAction(c: RuntimeClient, section: FlatSection) {
  const schema = await discover(c, section.entity);
  const meta = schema.actions.find((a) => a.name === section.name)!;
  return buildActionForm(meta);
}

// ── Test 6 — Renderer consumes ViewDefinition ───────────────────────────────
test('6 — renderer consumes ViewDefinition (flatten + navigation)', () => {
  const flat = flattenView(invoiceView);
  assert.equal(flat.title, 'Invoices');
  assert.deepEqual(viewNavigation(invoiceView), [{ identity: 'board', title: 'Board' }]);
  const sections = flat.pages[0].sections;
  assert.deepEqual(sections.map((s) => `${s.kind}:${s.name}`), ['projection:board', 'action:create']);
});

// ── Test 7 — page projection rendered ───────────────────────────────────────
test('7 — projection section rendered via the renderer', async () => {
  const api = makeApi();
  const c = client(api);
  await c.invokeAction('invoice', 'create', { amount: 1000 });
  const flat = flattenView(invoiceView);
  const projectionSection = flat.pages[0].sections.find((s) => s.kind === 'projection')!;
  const table = await renderProjection(c, projectionSection);
  assert.deepEqual(table.columns, ['amount', 'buyer']);
  assert.equal(table.rows.length, 1);
  assert.equal(table.rows[0].amount, 1000);
});

// ── Test 8 — page action rendered ───────────────────────────────────────────
test('8 — action section rendered via the renderer', async () => {
  const api = makeApi();
  const c = client(api);
  const flat = flattenView(invoiceView);
  const actionSection = flat.pages[0].sections.find((s) => s.kind === 'action')!;
  const form = await renderAction(c, actionSection);
  assert.equal(form.action, 'create');
  assert.deepEqual(form.fields.map((f) => f.name), ['amount', 'buyer']);
});

// ── Test 9 — integration: View → Renderer → Projection → Action ─────────────
test('9 — integration: view drives projection then action then refresh', async () => {
  const api = makeApi();
  const c = client(api);
  const flat = flattenView(invoiceView);
  const projectionSection = flat.pages[0].sections.find((s) => s.kind === 'projection')!;
  const actionSection = flat.pages[0].sections.find((s) => s.kind === 'action')!;

  // initially empty
  let table = await renderProjection(c, projectionSection);
  assert.equal(table.rows.length, 0);

  // execute the action section's form (with expand: create a buyer first)
  const cust = await c.invokeAction('customer', 'create', { name: 'Globex' });
  const form = await renderAction(c, actionSection);
  await c.invokeAction(
    actionSection.entity,
    actionSection.name,
    buildInputs(form, { amount: '1200', buyer: cust.body.reference.identityHandle }),
  );

  // refresh the projection section → new row visible, with single-hop expand
  table = await renderProjection(c, projectionSection);
  assert.equal(table.rows.length, 1);
  assert.equal(table.rows[0].amount, 1200);
  assert.deepEqual(table.rows[0].buyer, { name: 'Globex' });
});

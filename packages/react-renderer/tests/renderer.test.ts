// IMPLEMENTATION-003 — React Renderer tests (Node built-in runner + TS
// strip-types; zero installs). Exercises the renderer LOGIC against a faithful
// in-memory mock of the api-runtime HTTP contract. The .tsx components are thin
// views over this tested core.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { RuntimeClient, type FetchLike } from '../src/api/RuntimeClient.ts';
import { EntityRegistry } from '../src/discovery/EntityRegistry.ts';
import { buildProjectionTable } from '../src/view/projectionModel.ts';
import { buildActionForm, buildInputs, validate } from '../src/view/actionModel.ts';
import type { EntitySchemaResponse } from '../src/types.ts';

// ── faithful mock of api-runtime (L4) ───────────────────────────────────────
function makeApi() {
  const customers: Record<string, { name: string }> = {};
  const invoices: Array<{ amount: number; buyer?: string }> = [];
  let counter = 0;

  const schemas: Record<string, EntitySchemaResponse> = {
    invoice: {
      identity: 'invoice',
      tenantScoped: true,
      actions: [
        { name: 'create', kind: 'create', inputs: ['amount', 'buyer'], guarded: true, transition: null },
        { name: 'approve', kind: 'transition', inputs: [], guarded: true, transition: { field: 'status', from: ['draft'], to: 'approved' } },
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
    const u = new URL(url, 'http://test');
    const path = u.pathname;
    const method = (init.method ?? 'GET').toUpperCase();
    const body = init.body ? JSON.parse(init.body) : {};
    let m: RegExpMatchArray | null;

    if (method === 'GET' && (m = path.match(/^\/api\/entities\/([^/]+)$/))) {
      const s = schemas[m[1]];
      return json(s ? 200 : 404, s ?? { error: `no schema for entity '${m[1]}'` });
    }
    if (method === 'GET' && (m = path.match(/^\/api\/entities\/([^/]+)\/projections\/([^/]+)$/))) {
      if (m[1] === 'invoice' && m[2] === 'board') {
        const rows = invoices.map((i) => {
          const row: Record<string, unknown> = { amount: i.amount };
          if (i.buyer && customers[i.buyer]) {
            row.buyer = { name: customers[i.buyer].name };
          }
          return row;
        });
        return json(200, { rows });
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
        if (typeof inputs.amount === 'number' && inputs.amount >= 5000) {
          return json(403, { error: "action 'create' denied" });
        }
        const id = `mem-${++counter}`;
        invoices.push({ amount: inputs.amount, buyer: inputs.buyer });
        return json(200, { reference: { tenantId: 'acme', entityFqn: 'invoice', identityHandle: id }, version: '1', fields: { amount: inputs.amount, buyer: inputs.buyer } });
      }
      return json(404, { error: 'unknown action' });
    }
    return json(404, { error: 'no route' });
  };

  return { fetchFn, schemas, store: { customers, invoices } };
}

const client = (api: ReturnType<typeof makeApi>) =>
  new RuntimeClient({ headers: { 'X-Tenant-ID': 'acme', 'X-Actor-Type': 'user' }, fetchFn: api.fetchFn });

// ── Test 1 — entity discovery ───────────────────────────────────────────────
test('1 — entity discovery from the API', async () => {
  const api = makeApi();
  const registry = new EntityRegistry(client(api), ['invoice', 'customer']);
  const invoice = await registry.discover('invoice');
  assert.equal(invoice.identity, 'invoice');
  assert.deepEqual(invoice.actions.map((a) => a.name), ['create', 'approve']);
  assert.deepEqual(invoice.projections.map((p) => p.name), ['board']);
});

// ── Test 2 — projection display ─────────────────────────────────────────────
test('2 — projection rendering (table model from API rows)', async () => {
  const api = makeApi();
  const c = client(api);
  await c.invokeAction('invoice', 'create', { amount: 1000 });
  await c.invokeAction('invoice', 'create', { amount: 2000 });
  const schema = await new EntityRegistry(c, ['invoice']).discover('invoice');
  const board = schema.projections[0];
  const res = await c.readProjection('invoice', 'board');
  const table = buildProjectionTable(board, res.body.rows);
  assert.deepEqual(table.columns, ['amount', 'buyer']);
  assert.equal(table.rows.length, 2);
  assert.equal(table.rows[0].amount, 1000);
});

// ── Test 3 — action form rendering ──────────────────────────────────────────
test('3 — action form auto-generated from metadata', async () => {
  const api = makeApi();
  const schema = await new EntityRegistry(client(api), ['invoice']).discover('invoice');
  const createForm = buildActionForm(schema.actions[0]);
  assert.deepEqual(createForm.fields.map((f) => f.name), ['amount', 'buyer']);
  const approveForm = buildActionForm(schema.actions[1]);
  // transition gets a subject 'id' field first
  assert.equal(approveForm.fields[0].name, 'id');
  assert.equal(approveForm.fields[0].subject, true);
  assert.deepEqual(validate(approveForm, {}), ['id is required']);
  assert.deepEqual(buildInputs(createForm, { amount: '1500', buyer: '' }), { amount: 1500 });
});

// ── Test 4 — action execution ───────────────────────────────────────────────
test('4 — action execution via the API', async () => {
  const api = makeApi();
  const c = client(api);
  const ok = await c.invokeAction('invoice', 'create', { amount: 1000 });
  assert.equal(ok.status, 200);
  assert.equal(ok.body.fields.amount, 1000);
  assert.equal(api.store.invoices.length, 1);
  // guard deny surfaces as a non-200 the form can show
  const denied = await c.invokeAction('invoice', 'create', { amount: 9999 });
  assert.equal(denied.status, 403);
  assert.equal(api.store.invoices.length, 1);
});

// ── Test 5 — automatic navigation (incl. a brand-new compiled entity) ───────
test('5 — navigation built from discovery; new entity needs no code change', async () => {
  const api = makeApi();
  // A newly compiled entity appears on the backend…
  api.schemas.widget = {
    identity: 'widget',
    tenantScoped: false,
    actions: [{ name: 'create', kind: 'create', inputs: ['label'], guarded: false, transition: null }],
    projections: [{ name: 'list', fields: [{ field: 'label', restricted: false }], expand: [] }],
  };
  // …and is exposed by adding its name to the registry (configuration, not code).
  const registry = new EntityRegistry(client(api), ['invoice', 'customer', 'widget']);
  const nav = await registry.navigation();
  assert.deepEqual(nav.map((n) => n.entity), ['invoice', 'customer', 'widget']);
  const widget = nav.find((n) => n.entity === 'widget');
  assert.deepEqual(widget?.actions, ['create']);
  assert.deepEqual(widget?.projections, ['list']);
});

// ── Test 6 — full integration: discover → read → invoke → refresh ───────────
test('6 — integration: discover → read → action → refresh', async () => {
  const api = makeApi();
  const c = client(api);
  const registry = new EntityRegistry(c, ['invoice', 'customer']);

  const invoiceSchema = await registry.discover('invoice');
  const board = invoiceSchema.projections[0];

  // initially empty
  let res = await c.readProjection('invoice', 'board');
  assert.equal(buildProjectionTable(board, res.body.rows).rows.length, 0);

  // create a customer + an invoice referencing it through the forms' inputs
  const customerSchema = await registry.discover('customer');
  const customerForm = buildActionForm(customerSchema.actions[0]);
  const cust = await c.invokeAction('customer', 'create', buildInputs(customerForm, { name: 'Globex' }));
  const custId = cust.body.reference.identityHandle;
  const createForm = buildActionForm(invoiceSchema.actions[0]);
  await c.invokeAction('invoice', 'create', buildInputs(createForm, { amount: '1200', buyer: custId }));

  // refresh → the new row is visible, with single-hop expand embedded
  res = await c.readProjection('invoice', 'board');
  const table = buildProjectionTable(board, res.body.rows);
  assert.equal(table.rows.length, 1);
  assert.equal(table.rows[0].amount, 1200);
  assert.deepEqual(table.rows[0].buyer, { name: 'Globex' });
});

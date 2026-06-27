// VALIDATION-001 — CRM React/View layer (test 5 navigation + test 6 view UI).
// Consumes the REAL compiled CRM schemas/views (fixtures written by
// crm-validation-test.php) through the existing renderer + view system.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';

import { RuntimeClient, type FetchLike } from '../../../packages/react-renderer/src/api/RuntimeClient.ts';
import { EntityRegistry } from '../../../packages/react-renderer/src/discovery/EntityRegistry.ts';
import { buildProjectionTable } from '../../../packages/react-renderer/src/view/projectionModel.ts';
import { buildActionForm } from '../../../packages/react-renderer/src/view/actionModel.ts';
import type { EntitySchemaResponse } from '../../../packages/react-renderer/src/types.ts';
import { flattenView, viewNavigation, type ViewJson } from '../../../packages/view-system/ui/viewModel.ts';

const fixtures = new URL('./.fixtures/', import.meta.url);
const read = (name: string) => JSON.parse(readFileSync(new URL(name, fixtures), 'utf8'));

if (!existsSync(new URL('./schemas.json', fixtures))) {
  throw new Error('CRM fixtures missing — run crm-validation-test.php first');
}

const schemas = read('schemas.json') as Record<string, EntitySchemaResponse>;
const views = read('views.json') as { views: ViewJson[] };
const pipeline = read('pipeline.json') as { rows: Array<Record<string, unknown>> };
const crmEntities = ['user', 'customer', 'opportunity', 'activity', 'task'];

// Mock backed by the REAL compiled schemas + a real pipeline projection.
const fetchFn: FetchLike = async (url) => {
  const path = new URL(url, 'http://test').pathname;
  let m: RegExpMatchArray | null;
  const json = (status: number, body: unknown) => ({ status, json: async () => body });
  if ((m = path.match(/^\/api\/entities\/([^/]+)$/))) {
    return json(schemas[m[1]] ? 200 : 404, schemas[m[1]] ?? { error: 'unknown' });
  }
  if ((m = path.match(/^\/api\/entities\/opportunity\/projections\/pipeline$/))) {
    return json(200, pipeline);
  }
  return json(404, { error: 'no route' });
};
const client = new RuntimeClient({ fetchFn });

// ── Test 5 — automatic navigation over the whole CRM ────────────────────────
test('5 — React renderer auto-navigation across all CRM entities', async () => {
  const registry = new EntityRegistry(client, crmEntities);
  const nav = await registry.navigation();
  assert.deepEqual(nav.map((n) => n.entity), crmEntities);
  const opp = nav.find((n) => n.entity === 'opportunity')!;
  assert.deepEqual(opp.actions, ['create', 'qualify', 'win', 'lose']);
  assert.deepEqual(opp.projections, ['pipeline', 'detail']);
  const customer = nav.find((n) => n.entity === 'customer')!;
  assert.deepEqual(customer.actions, ['create', 'activate', 'deactivate']);
});

// ── Test 6 — CRM views render via the View System + renderer ────────────────
test('6 — all five CRM views flatten into renderable sections', () => {
  assert.equal(views.views.length, 5);
  for (const view of views.views) {
    const flat = flattenView(view);
    assert.ok(flat.pages.length >= 1);
    for (const page of flat.pages) {
      for (const section of page.sections) {
        // every section names a real capability of a real CRM entity
        const schema = schemas[section.entity];
        assert.ok(schema, `entity ${section.entity} exists`);
        const names =
          section.kind === 'projection'
            ? schema.projections.map((p) => p.name)
            : schema.actions.map((a) => a.name);
        assert.ok(names.includes(section.name), `${section.entity}.${section.name} exists`);
      }
    }
  }
  // navigation derived from a view (CRM Dashboard)
  const dashboard = views.views.find((v) => v.identity === 'crm-dashboard')!;
  assert.deepEqual(viewNavigation(dashboard), [{ identity: 'dashboard', title: 'Dashboard' }]);
});

// ── Test 6b — render a real projection section (pipeline, with expand) ──────
test('6 — pipeline projection renders with expanded customer', () => {
  const meta = schemas.opportunity.projections.find((p) => p.name === 'pipeline')!;
  const table = buildProjectionTable(meta, pipeline.rows);
  assert.deepEqual(table.columns, ['title', 'amount', 'stage', 'customer']);
  assert.ok(table.rows.length >= 1);
  const withCustomer = table.rows.find((r) => r.customer && typeof r.customer === 'object');
  assert.ok(withCustomer, 'a pipeline row carries an expanded customer object');
});

// ── Test 6c — render a real action form (customer.create) ───────────────────
test('6 — customer create action renders an auto-form', () => {
  const meta = schemas.customer.actions.find((a) => a.name === 'create')!;
  const form = buildActionForm(meta);
  assert.deepEqual(form.fields.map((f) => f.name), ['name', 'email', 'phone']);
});

// VALIDATION-003 — SGH React/View layer (test 5 navigation + view UI).
// Consumes the REAL compiled SGH schemas/views (fixtures from sgh-validation-test.php).

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';

import { RuntimeClient, type FetchLike } from '../../../packages/react-renderer/src/api/RuntimeClient.ts';
import { EntityRegistry } from '../../../packages/react-renderer/src/discovery/EntityRegistry.ts';
import { buildProjectionTable } from '../../../packages/react-renderer/src/view/projectionModel.ts';
import { buildActionForm } from '../../../packages/react-renderer/src/view/actionModel.ts';
import type { EntitySchemaResponse } from '../../../packages/react-renderer/src/types.ts';
import { flattenView, type ViewJson } from '../../../packages/view-system/ui/viewModel.ts';

const fixtures = new URL('./.fixtures/', import.meta.url);
const read = (name: string) => JSON.parse(readFileSync(new URL(name, fixtures), 'utf8'));
if (!existsSync(new URL('./schemas.json', fixtures))) {
  throw new Error('SGH fixtures missing — run sgh-validation-test.php first');
}

const schemas = read('schemas.json') as Record<string, EntitySchemaResponse>;
const views = read('views.json') as { views: ViewJson[] };
const apptDetail = read('appointment-detail.json') as { rows: Array<Record<string, unknown>> };
const sghEntities = ['user', 'department', 'doctor', 'patient', 'appointment', 'consultation', 'admission', 'bed', 'prescription', 'invoice', 'payment', 'medicalrecord'];

const fetchFn: FetchLike = async (url) => {
  const path = new URL(url, 'http://test').pathname;
  const json = (status: number, body: unknown) => ({ status, json: async () => body });
  let m: RegExpMatchArray | null;
  if ((m = path.match(/^\/api\/entities\/([^/]+)$/))) {
    return json(schemas[m[1]] ? 200 : 404, schemas[m[1]] ?? { error: 'unknown' });
  }
  if (path === '/api/entities/appointment/projections/detail') {
    return json(200, apptDetail);
  }
  return json(404, { error: 'no route' });
};
const client = new RuntimeClient({ fetchFn });

// ── Test 5 — auto-navigation across all 12 hospital entities ────────────────
test('5 — React auto-navigation across the whole hospital', async () => {
  const nav = await new EntityRegistry(client, sghEntities).navigation();
  assert.deepEqual(nav.map((n) => n.entity), sghEntities);
  const appt = nav.find((n) => n.entity === 'appointment')!;
  assert.deepEqual(appt.actions, ['create', 'confirm', 'cancel', 'complete']);
  const invoice = nav.find((n) => n.entity === 'invoice')!;
  assert.deepEqual(invoice.actions, ['create', 'validate', 'markPaid']);
});

// ── Test 6 — the six SGH views render into real sections ────────────────────
test('6 — SGH views render; every section maps to a real capability', () => {
  assert.equal(views.views.length, 6);
  let sections = 0;
  for (const view of views.views) {
    for (const page of flattenView(view).pages) {
      for (const s of page.sections) {
        sections++;
        const schema = schemas[s.entity];
        assert.ok(schema, `entity ${s.entity}`);
        const names = s.kind === 'projection' ? schema.projections.map((p) => p.name) : schema.actions.map((a) => a.name);
        assert.ok(names.includes(s.name), `${s.entity}.${s.name}`);
      }
    }
  }
  assert.equal(sections, 18);
});

// ── Test 7-UI — expanded projection renders (Appointment → Patient + Doctor) ─
test('6 — appointment detail renders with expanded patient + doctor', () => {
  const meta = schemas.appointment.projections.find((p) => p.name === 'detail')!;
  const table = buildProjectionTable(meta, apptDetail.rows);
  assert.deepEqual(table.columns, ['code', 'date', 'status', 'patient', 'doctor']);
  const row = table.rows.find((r) => r.patient && r.doctor);
  assert.ok(row, 'a row carries expanded patient + doctor');
  assert.equal((row!.patient as Record<string, unknown>).lastName, 'Sy');
  assert.equal((row!.doctor as Record<string, unknown>).lastName, 'Ba');
});

// ── Test 6 — book-appointment action renders an auto-form ───────────────────
test('6 — appointment.create renders an auto-form', () => {
  const form = buildActionForm(schemas.appointment.actions.find((a) => a.name === 'create')!);
  assert.deepEqual(form.fields.map((f) => f.name), ['code', 'date', 'patient', 'doctor']);
});

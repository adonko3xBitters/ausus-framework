// VALIDATION-002 — Teranga PMS React/View layer (test 5 navigation + view UI).
// Consumes the REAL compiled PMS schemas/views (fixtures from pms-validation-test.php)
// through the existing renderer + view system. No framework code is modified.

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
  throw new Error('PMS fixtures missing — run pms-validation-test.php first');
}

const schemas = read('schemas.json') as Record<string, EntitySchemaResponse>;
const views = read('views.json') as { views: ViewJson[] };
const reservationDetail = read('reservation-detail.json') as { rows: Array<Record<string, unknown>> };
const pmsEntities = ['user', 'hotel', 'roomtype', 'room', 'guest', 'reservation', 'stay', 'invoice', 'payment', 'housekeepingtask'];

const fetchFn: FetchLike = async (url) => {
  const path = new URL(url, 'http://test').pathname;
  const json = (status: number, body: unknown) => ({ status, json: async () => body });
  let m: RegExpMatchArray | null;
  if ((m = path.match(/^\/api\/entities\/([^/]+)$/))) {
    return json(schemas[m[1]] ? 200 : 404, schemas[m[1]] ?? { error: 'unknown' });
  }
  if (path === '/api/entities/reservation/projections/detail') {
    return json(200, reservationDetail);
  }
  return json(404, { error: 'no route' });
};
const client = new RuntimeClient({ fetchFn });

// ── Test 5 — automatic navigation across all 10 PMS entities ────────────────
test('5 — React renderer auto-navigation across the whole PMS', async () => {
  const nav = await new EntityRegistry(client, pmsEntities).navigation();
  assert.deepEqual(nav.map((n) => n.entity), pmsEntities);
  const reservation = nav.find((n) => n.entity === 'reservation')!;
  assert.deepEqual(reservation.actions, ['create', 'confirm', 'cancel']);
  assert.deepEqual(reservation.projections, ['board', 'detail']);
  const stay = nav.find((n) => n.entity === 'stay')!;
  assert.deepEqual(stay.actions, ['checkIn', 'checkOut']);
});

// ── Test 6 — all five PMS views flatten into renderable sections ────────────
test('6 — PMS views render; every section maps to a real capability', () => {
  assert.equal(views.views.length, 5);
  let sections = 0;
  for (const view of views.views) {
    for (const page of flattenView(view).pages) {
      for (const section of page.sections) {
        sections++;
        const schema = schemas[section.entity];
        assert.ok(schema, `entity ${section.entity}`);
        const names =
          section.kind === 'projection'
            ? schema.projections.map((p) => p.name)
            : schema.actions.map((a) => a.name);
        assert.ok(names.includes(section.name), `${section.entity}.${section.name}`);
      }
    }
  }
  assert.equal(sections, 14);
});

// ── Test 7-UI — expanded projection renders (Reservation → Guest + Room) ────
test('6 — reservation detail renders with expanded guest + room', () => {
  const meta = schemas.reservation.projections.find((p) => p.name === 'detail')!;
  const table = buildProjectionTable(meta, reservationDetail.rows);
  assert.deepEqual(table.columns, ['code', 'checkInDate', 'checkOutDate', 'status', 'guest', 'room']);
  const row = table.rows.find((r) => r.guest && r.room);
  assert.ok(row, 'a row carries expanded guest + room objects');
  assert.equal((row!.guest as Record<string, unknown>).lastName, 'Sow');
  assert.equal((row!.room as Record<string, unknown>).number, '201');
});

// ── Test 6 — Front Desk check-in action renders an auto-form ────────────────
test('6 — stay.checkIn action renders an auto-form', () => {
  const meta = schemas.stay.actions.find((a) => a.name === 'checkIn')!;
  const form = buildActionForm(meta);
  assert.deepEqual(form.fields.map((f) => f.name), ['reservation', 'actualCheckIn']);
  // a transition (checkOut) auto-form gets the subject 'id' field
  const checkOut = buildActionForm(schemas.stay.actions.find((a) => a.name === 'checkOut')!);
  assert.equal(checkOut.fields[0].name, 'id');
});

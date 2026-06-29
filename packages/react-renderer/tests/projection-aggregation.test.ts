// IMPLEMENTATION-003 — L4 client aggregation builder tests (Node built-in
// runner + TS strip-types; zero installs). Verifies that buildProjectionParams()
// encodes `aggregate` exactly as the api-runtime QueryStringParser consumes it,
// composes with where/orderBy, and drives RuntimeClient.readProjection() to the
// correct URL — plus the ProjectionResponse.aggregates shape.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { RuntimeClient, type FetchLike } from '../src/api/RuntimeClient.ts';
import { buildProjectionParams } from '../src/api/projectionQuery.ts';
import type { ProjectionResponse } from '../src/types.ts';

test('aggregate: count without field encodes op:as', () => {
  const p = buildProjectionParams({ aggregate: [{ op: 'count', as: 'rooms' }] });
  assert.deepEqual(p, { aggregate: 'count:rooms' });
});

test('aggregate: field ops encode op:field:as, comma-joined', () => {
  const p = buildProjectionParams({
    aggregate: [
      { op: 'count', as: 'rooms' },
      { op: 'sum', field: 'total', as: 'revenue' },
      { op: 'avg', field: 'price', as: 'averagePrice' },
    ],
  });
  assert.deepEqual(p, { aggregate: 'count:rooms,sum:total:revenue,avg:price:averagePrice' });
});

test('aggregate composes with where / orderBy / limit', () => {
  const p = buildProjectionParams({
    where: [{ field: 'status', op: 'eq', value: 'available' }],
    orderBy: [{ field: 'price', dir: 'desc' }],
    limit: 20,
    aggregate: [{ op: 'sum', field: 'price', as: 'revenue' }],
  });
  assert.deepEqual(p, {
    where: 'status:eq:available',
    orderBy: 'price:desc',
    limit: '20',
    aggregate: 'sum:price:revenue',
  });
});

test('no aggregate → no aggregate key (backward compatible)', () => {
  assert.deepEqual(buildProjectionParams({ where: [{ field: 'a', op: 'eq', value: '1' }] }), {
    where: 'a:eq:1',
  });
});

test('readProjection drives the aggregate query string', async () => {
  let captured = '';
  const fetchFn: FetchLike = async (url) => {
    captured = url;
    return { status: 200, json: async () => ({ rows: [], aggregates: { rooms: 42, revenue: 580000 } }) };
  };
  const client = new RuntimeClient({ baseUrl: 'https://api.test', fetchFn });

  const res = await client.readProjection(
    'room',
    'board',
    buildProjectionParams({
      where: [{ field: 'status', op: 'eq', value: 'available' }],
      aggregate: [
        { op: 'count', as: 'rooms' },
        { op: 'sum', field: 'price', as: 'revenue' },
      ],
    }),
  );

  const params = new URLSearchParams(captured.split('?')[1] ?? '');
  assert.equal(params.get('where'), 'status:eq:available');
  assert.equal(params.get('aggregate'), 'count:rooms,sum:price:revenue');

  // ProjectionResponse.aggregates is typed and surfaced
  const body: ProjectionResponse = res.body;
  assert.equal(body.aggregates?.rooms, 42);
  assert.equal(body.aggregates?.revenue, 580000);
});

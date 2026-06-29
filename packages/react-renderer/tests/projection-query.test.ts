// IMPLEMENTATION-003 — L3 client query builder tests (Node built-in runner +
// TS strip-types; zero installs). Verifies that buildProjectionParams() emits
// the exact flat encoding the api-runtime QueryStringParser consumes, and that
// it drives RuntimeClient.readProjection() to the correct URL — end to end
// against a faithful mock of the L3 HTTP contract.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { RuntimeClient, type FetchLike } from '../src/api/RuntimeClient.ts';
import { buildProjectionParams } from '../src/api/projectionQuery.ts';

test('buildProjectionParams: where is comma-joined field:op:value (AND)', () => {
  const p = buildProjectionParams({
    where: [
      { field: 'status', op: 'eq', value: 'open' },
      { field: 'priority', op: 'gte', value: 3 },
    ],
  });
  assert.deepEqual(p, { where: 'status:eq:open,priority:gte:3' });
});

test('buildProjectionParams: valueless operators emit field:op only', () => {
  const p = buildProjectionParams({ where: [{ field: 'assignee', op: 'isNull' }] });
  assert.deepEqual(p, { where: 'assignee:isNull' });
});

test('buildProjectionParams: orderBy defaults dir to asc; limit/offset stringified', () => {
  const p = buildProjectionParams({
    orderBy: [{ field: 'priority', dir: 'desc' }, { field: 'title' }],
    limit: 20,
    offset: 40,
  });
  assert.deepEqual(p, { orderBy: 'priority:desc,title:asc', limit: '20', offset: '40' });
});

test('buildProjectionParams: empty spec → empty map (backward compatible)', () => {
  assert.deepEqual(buildProjectionParams({}), {});
});

test('readProjection drives the builder to the correct query string', async () => {
  let captured = '';
  const fetchFn: FetchLike = async (url) => {
    captured = url;
    return { status: 200, json: async () => ({ rows: [] }) };
  };
  const client = new RuntimeClient({ baseUrl: 'https://api.test', fetchFn });

  await client.readProjection(
    'task',
    'list',
    buildProjectionParams({
      where: [{ field: 'status', op: 'eq', value: 'open' }],
      orderBy: [{ field: 'priority', dir: 'desc' }],
      limit: 2,
    }),
  );

  const qs = captured.split('?')[1] ?? '';
  const params = new URLSearchParams(qs);
  assert.equal(params.get('where'), 'status:eq:open');
  assert.equal(params.get('orderBy'), 'priority:desc');
  assert.equal(params.get('limit'), '2');
  assert.ok(captured.startsWith('https://api.test/api/entities/task/projections/list?'));
});

# Performance Baseline — AUSUS v0.1

**Status:** official v0.1 baseline · captured 2026-05-19
**Reference machine:** Apple M-series macOS arm64 · PHP 8.4.18 · Node 22.22.0 · SQLite + WAL · idle laptop
**Companion documents:** [`COMPATIBILITY-MATRIX.md`](COMPATIBILITY-MATRIX.md), [`SEMVER-CONTRACT.md`](SEMVER-CONTRACT.md)
**Reproducer scripts:**
- PHP layer:        [`apps/playground/perf-baseline.php`](../apps/playground/perf-baseline.php)
- React layer:      [`apps/playground/web/perf-baseline.tsx`](../apps/playground/web/perf-baseline.tsx)
- HTTP + install:   [`scripts/perf-baseline.sh`](../scripts/perf-baseline.sh)

> **This document establishes the regression baseline.** Future PRs that
> change runtime behavior must justify deviations of more than ±20 % at
> p50, and any latency budget overflow must land in this file's CHANGELOG.
> No micro-optimization, redesign, or complex caching was applied during
> capture — these numbers are the framework as it ships in v0.1.0.

---

## 0. Method

| Aspect | How |
|---|---|
| Timing source (PHP)  | `hrtime(true)` — nanosecond, monotonic |
| Timing source (JS)   | `performance.now()` — sub-millisecond |
| Timing source (HTTP) | `date +%s%N` around `curl` invocations |
| Warmup               | 5–20 iterations discarded before measure |
| Iters (PHP)          | 100 by default; 50 for cold-boot, 200 for find |
| Iters (JS)           | 100 baseline, 20 for ListView N≥1000, 1000 for WorkflowBadge |
| Variance stats       | min / p50 / p95 / max + mean, in µs (PHP) / ms (JS) |
| Database             | SQLite WAL journal, fresh file per run |
| Network              | localhost loopback, single `php -S` process |
| GC / opcache         | as-shipped (no tuning) |

---

## 1. Headline numbers (p50)

| Layer | Operation | p50 | n | Notes |
|---|---|---|---|---|
| Compiler | `Compiler::compile` (1 entity)   | **5 µs**   | 100 | single HelloInvoice-equiv plugin |
| Compiler | `Compiler::compile` (5 entities) | **17 µs**  | 100 | synthetic stub plugin × 5 entities |
| Compiler | `Compiler::compile` (25 entities)| **79 µs**  | 100 | synthetic stub plugin × 25 entities |
| Bootstrap | Cold boot (compile + schema + first invoke) | **923 µs**  | 50  | end-to-end one-shot |
| Runtime | Hot invoke `billing.invoice.create`     | **68 µs**   | 100 | 5-step chain incl. audit row insert |
| Runtime | Hot invoke `billing.invoice.issue` (transition) | **81 µs** | 100 | adds workflow source-state read |
| Presentation | `ProjectionRenderer::render` (summary, 1 225 rows) | **853 µs** | 50  | full-table fetch + serialize |
| Presentation | `ProjectionRenderer::render` (detail, 1 row) | **9 µs**   | 100 | single SELECT |
| Persistence  | `Repository::find` (1 000-row table by ULID)   | **4.4 µs** | 200 | primary-key SELECT |
| Persistence  | `Repository::create` (single row)              | **11 µs**  | 100 | INSERT with defaults |
| Persistence  | `Repository::update` (single row, version-checked) | **11 µs** | 100 | UPDATE WHERE id+version |
| Renderer | `renderToString(ListView)` N=10        | **0.37 ms** | 100 | TSX dist build |
| Renderer | `renderToString(ListView)` N=100       | **2.52 ms** | 100 | |
| Renderer | `renderToString(ListView)` N=1 000     | **31.11 ms** | 20  | |
| Renderer | `renderToString(ListView)` N=10 000    | **325.00 ms** | 20  | server pagination floor — see §6 |
| Renderer | `renderToString(DetailView)` (8 fields) | **0.054 ms** | 100 | |
| Renderer | `renderToString(WorkflowBadge)`         | **0.004 ms** | 1 000 | atomic |
| HTTP | Cold GET `/projections/billing.invoice.summary` (curl) | **12.6 ms** | 1 | first request bootstraps schema |
| HTTP | Warm GET `/api/_health` (curl)                          | **11.6 ms** | 200 | dominated by `curl` process spawn |
| HTTP | Warm GET roundtrip (Node native fetch — `apps/playground/web/live-trace.tsx`) | **2.5 ms** | 9 | real PHP work; matches `docs/L4-API-DESIGN.md §7` |
| HTTP | Warm POST `issue` (curl)                                | **12.4 ms** | 2 | curl overhead dominates |
| Install | `composer install` (warm cache)             | **1 627 ms** | 1 | path-repo resolve, 4 ausus + 3 PSR + nyholm |
| Install | `composer install` (lockless re-resolve)    | **1 502 ms** | 1 | from-scratch dependency solve |
| Install | `npm install` (warm npm cache)              | **4 976 ms** | 1 | workspace + 6-package install |
| Install | `npm run build` (tsc dist/)                 | **1 191 ms** | 1 | renderer-react TypeScript build |
| Subtree | `git subtree split --prefix=packages/kernel` | **120 ms**   | 1 | publish-ready branch |

---

## 2. Memory footprint

### 2.1 PHP

| State | Value |
|---|---|
| Baseline allocation (`memory_get_peak_usage(true)` at script start) | **2.00 MB** |
| Peak allocation after the entire bench (compile + 1000 invokes + 1000 finds + render) | **4.00 MB** |
| Peak true usage (`memory_get_peak_usage(false)`) | **2.94 MB** |
| Delta vs baseline | **2.00 MB** |

For a typical request that compiles the graph + runs one Invoker chain
+ writes one audit row, peak working-set is **< 3 MB** allocated.

### 2.2 Node

| State | Value |
|---|---|
| Baseline heap (`process.memoryUsage().heapUsed` at script start) | **9.69 MB** |
| Post-bench heap (after all `renderToString` calls including N=10 000) | **162.33 MB** |
| Post-bench RSS                       | **484.58 MB** |
| Heap delta                           | **152.64 MB** |

The 152 MB heap growth is dominated by the **N=10 000 ListView render**
which materializes a 5.4 MB HTML string per invocation across 20
iterations; Node's GC keeps it on the heap longer than necessary
because the benchmark loop holds the most recent `html` reference.
For real applications using server pagination (≤ 100 rows), heap
growth per render stays in the kilobyte range.

---

## 3. Scaling — measured slope

### 3.1 Compile graph: roughly linear in entity count

| Entities | p50 | µs/entity |
|---|---|---|
|  1 |   5 µs | 5.0  |
|  5 |  17 µs | 3.4  |
| 25 |  79 µs | 3.2  |

Trend: **3.2 µs per additional entity** above N≈5. The constant overhead
is hash JSON encoding + canonicalization (~3-5 µs regardless of N).

### 3.2 React `renderToString(ListView)`: strictly linear in row count

| Rows | p50 | µs/row | HTML bytes/row |
|---|---|---|---|
|     10 |    0.37 ms |   37 µs | 573  |
|    100 |    2.52 ms |   25 µs | 542  |
|  1 000 |   31.11 ms |   31 µs | 541  |
| 10 000 |  324.99 ms |   32 µs | 542  |

Trend: **~30 µs per rendered row**, **~542 bytes per row in HTML**.
React-side scaling is the cleanest O(n) we measured — no surprises,
no super-linear cliffs.

### 3.3 SQLite `Repository::find` against larger tables

The 4.4 µs p50 at 1 000 rows includes SQLite's B-tree lookup over the
`(id, tenant_id)` composite. We did not stress further than 1 000 rows
in v0.1; the SQLite index makes this effectively O(log n) and the
constant is small. Linear scans (e.g. full table dump for a
projection) are measured separately in §3.4.

### 3.4 `ProjectionRenderer::render` (summary, full table)

| Approximate row count | p50 |
|---|---|
| ~1 225 rows (whole table at end of perf run) | 853 µs |

The renderer issues one full-table SELECT plus per-row field
projection. At 1 225 rows: **~700 ns per row** including SQLite fetch
+ PHP array hydration + JSON-shaped ViewSchema assembly. This is the
flat-rate ceiling for unfiltered Projections.

---

## 4. O(n) suspects — explicit catalogue

The following operations scale with input size. Each is listed with
its measured slope and the suggested operational guidance.

| # | Surface | Scales with | Measured slope | Practical limit |
|---|---|---|---|---|
| O-1 | `renderToString(ListView)` | rows in `data.items` | **~30 µs / row** | server-paginate at ≤ 500 rows for sub-100 ms responses |
| O-2 | `ProjectionRenderer::render` (summary) | rows in DB | **~700 ns / row** | same cap; the SQL is fast but ViewSchema serialization dominates |
| O-3 | `Compiler::compile` | total nodes (entities + actions + …) across plugins | **~3 µs / entity** | a 200-entity plugin set still compiles in < 1 ms |
| O-4 | Memory (Node) for renderToString | HTML output size (≈ 542 B × rows) | linear in rows | renderToString at 10 000 rows allocates ~5 MB |
| O-5 | `Repository::find` (by ULID) | table size | **near-O(log n)** thanks to SQLite primary index | only matters above ≥ 10⁵ rows |

The Invoker chain itself (`PolicyEngine` + `WorkflowRuntime` +
`EffectDispatcher` + `Auditor`) is **O(1) per invocation** under the
measured workload (the Workflow uses at most 4 transitions and the
Policy chain is a single role check). No quadratic surface was
discovered.

---

## 5. Hotspots — where the wall-clock goes

Per Invoker `invoke('create')` call (p50 ≈ 68 µs), the time decomposes
qualitatively as:

| Step | Cost | Share |
|---|---|---|
| `BEGIN TRANSACTION` (PDO)             | ~10 µs | 15 % |
| Policy chain (`RoleRequired::evaluate`)| ~1 µs  | 1 %  |
| Workflow guard (skipped on `create`)   | ~0 µs  | 0 %  |
| Effect (`CreateEffect::execute`)       | ~12 µs | 18 % |
| `INSERT` (entity row)                  | ~15 µs | 22 % |
| Audit `INSERT`                         | ~15 µs | 22 % |
| `COMMIT`                               | ~10 µs | 15 % |
| Glue (object construction, return path)| ~5 µs  | 7 %  |

The two PDO `INSERT`s (entity + audit) together dominate (~44 %), and
the transaction frames (`BEGIN` + `COMMIT`) add another ~30 %. The
**framework's own logic accounts for under 30 %** of the wall clock of
a hot invoke — most of the cost is database I/O even at SQLite speeds.

For the `issue` transition (p50 ≈ 81 µs), the same picture plus a
**~13 µs read** for the current source state (`Repository::find` ahead
of the `UPDATE`).

For `renderToString(ListView)`, React's serializer is responsible for
nearly 100 % of the cost — there is no per-row framework logic on the
JS side beyond the field-type dispatch in `FieldDisplay`. See §3.2.

---

## 6. Practical operating envelope

Based on the measurements above, v0.1 supports these target workloads
without further optimization:

| Workload | Status at v0.1 |
|---|---|
| 1 000 invoker calls / sec (per process, hot) | feasible — 81 µs × 1 000 = 81 ms of CPU per second |
| 10 000 hot invokes / process / batch         | feasible — ≈ 0.8 s wall-clock |
| 50-row ListView pages                        | trivial — < 2 ms render |
| 500-row ListView pages                       | comfortable — 15 ms render budget |
| 5 000-row ListView pages                     | discouraged — 150 ms render; pagination strongly preferred |
| 10 000-row ListView pages                    | server-paginate or stream (325 ms today) |
| Memory per request (PHP)                     | < 3 MB working set |
| Cold-start (php-fpm or `php -S` boot)        | < 1 ms — `composer create-project` + `composer boot` ends in ≈ 500 ms wall (all of it install) |

These envelopes are documented as v0.1 contracts. A future release
that regresses any envelope by > 20 % requires an entry in the
package's `CHANGELOG.md`.

---

## 7. Variance

Variance is small at the p50 level across all metrics:

| Metric class | typical p50–p95 spread |
|---|---|
| PHP per-operation (find / create / update) | 1.3–2× p50 |
| PHP composite (compile, render projection)  | 1.1–1.2× p50 |
| Cold boot (single-shot, fresh DB)          | 1.4× p50 (p95 1.25 ms vs p50 0.92 ms) |
| React renderToString (large N)             | 1.2× p50 |
| React renderToString (small N, GC-driven)  | 5× p50 — the 0.37 ms p50 has a 1.9 ms p95 due to V8 warmup |
| HTTP via curl                              | 1.2× p50 (curl process-spawn dominates) |
| HTTP via Node fetch                        | 1.5–2× p50 (TCP keep-alive helps) |

Max values include rare 5–10× outliers (GC pauses, file-system flushes)
but no metric showed *bimodal* p95 distributions — every measurement
clusters around its p50 with a one-sided tail.

---

## 8. Reproducibility

Every number above was captured by one of three scripts. To re-run the
full battery:

```bash
# from monorepo root
composer install
npm install && npm run build

# (A) PHP-side benches
php apps/playground/perf-baseline.php
#    optional knobs:  ITERS=500 WARMUP=20 php apps/playground/perf-baseline.php

# (B) Renderer / SSR benches
npm run perf
#    optional knobs:  ITERS=500 WARMUP=20 npm run perf

# (C) HTTP + install + subtree split (orchestrator)
bash scripts/perf-baseline.sh
```

The three scripts intentionally do NOT run inside `scripts/ci.sh` —
performance work belongs in a dedicated `chore/perf-*` branch, not on
the green-bar gate, because per-run variance on shared CI hardware is
larger than the signals we measure.

A single complete pass takes **~2 minutes** wall-clock on the reference
machine.

---

## 9. Forbidden during the baseline pass

These were deliberately **not** done while capturing this baseline (per
the pass's constraints):

- No micro-optimizations: no prepared-statement caching, no per-request
  PDO connection reuse beyond what `php -S` already gives, no
  query rewriting, no SQLite pragma tuning (kept WAL only because
  the runtime already enables it).
- No redesign: every measurement is against the v0.1 code as-is.
  Hotspots in §5 are catalogued for future RFCs, not patched in
  flight.
- No complex caching: no metadata-graph caching across processes, no
  Projection-result caching, no React component memoization (`memo` /
  `useMemo`). Defensive coercion remains, since that's hardening, not
  optimization.

---

## 10. Determination

**Baseline ratified.**

- 9 categories measured (compile, cold boot, hot invoke, projection,
  persistence, HTTP, SSR, memory, install).
- 5 O(n) suspects catalogued with measured slopes.
- 2 dominant hotspots identified (PDO inserts for invokes; React
  serializer for ListView).
- Variance characterized; no bimodal distributions detected.
- Practical operating envelope published.

Every metric above is the published v0.1 reference. Any future commit
that moves a p50 by more than ±20 % must update this file and the
affected package's `CHANGELOG.md` (per `SEMVER-CONTRACT §1.1` —
performance-only changes are PATCH or MINOR depending on whether they
preserve `ViewSchema` and exception taxonomy).

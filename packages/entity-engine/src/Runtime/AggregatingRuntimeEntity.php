<?php

declare(strict_types=1);

namespace Ausus\Engine\Runtime;

use Ausus\Contracts\Context;

/**
 * L4 — additive read capability: a projection read that also returns KPI
 * aggregates.
 *
 * This interface lives in entity-engine (NOT the frozen kernel) so the kernel
 * {@see \Ausus\Contracts\RuntimeEntity} contract stays byte-frozen. A runtime
 * that supports L4 (the default {@see DefaultRuntimeEntity}) implements it; the
 * API layer feature-detects it with `instanceof` and falls back to the plain
 * `read()` rows envelope when absent.
 *
 * The result reuses primitive shapes — no new kernel value object:
 *
 *   array{ rows: list<array<string,mixed>>, aggregates: array<string,mixed> }
 *
 * `rows` is exactly what {@see \Ausus\Contracts\RuntimeEntity::read} returns
 * (same filter/sort/pagination/visibility/expand). `aggregates` is computed over
 * the full WHERE-filtered, visible set and is `[]` when no `aggregate` was asked.
 */
interface AggregatingRuntimeEntity
{
    /**
     * @param array<string,mixed> $params selection (L3) + `aggregate` (L4)
     * @return array{rows: list<array<string,mixed>>, aggregates: array<string,mixed>}
     */
    public function readWithAggregates(string $projection, array $params, Context $context): array;
}

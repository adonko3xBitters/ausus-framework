<?php

declare(strict_types=1);

namespace Ausus\Engine\Query;

use Ausus\Definition\ProjectionDefinition;

/**
 * L4 — Projection Aggregations (v1): KPI/statistics over a read shape.
 *
 * The official, additive aggregation contract of AUSUS Gen2. It is the second
 * stage of the read pipeline, running AFTER {@see ProjectionQuery} has filtered
 * (WHERE) and the runtime has applied per-field visibility, but BEFORE the page
 * is sorted/paginated and rendered:
 *
 *     findAll → WHERE → AGGREGATE (this) → ORDER BY → LIMIT/OFFSET → render
 *
 * Aggregates are computed over the FULL tenant-scoped, WHERE-filtered, visible
 * set — they intentionally ignore `limit`/`offset` (a KPI card sums every
 * matching row, not the current page). It is driver-agnostic: a future SQL
 * driver may push the same contract down without changing it. It is FAIL-CLOSED:
 * an unknown operator, an unexposed/missing field, a duplicate alias, or a
 * type-incompatible value is rejected ({@see QueryError}).
 *
 * `$params['aggregate']` shape (optional; absent ⇒ no aggregation):
 *
 *   [
 *     ['op' => 'count',                   'as' => 'rooms'],        // row count
 *     ['op' => 'count', 'field' => 'ref', 'as' => 'withRef'],     // non-null count
 *     ['op' => 'sum',   'field' => 'total', 'as' => 'revenue'],
 *     ['op' => 'avg',   'field' => 'price', 'as' => 'averagePrice'],
 *     ['op' => 'min',   'field' => 'price', 'as' => 'cheapest'],
 *     ['op' => 'max',   'field' => 'price', 'as' => 'dearest'],
 *   ]
 *
 * Operators: count, sum, avg, min, max. `field` is required for every operator
 * except `count` (where it is optional). `sum`/`avg` require numeric values;
 * `min`/`max` compare numerically when numeric, else lexicographically. `field`
 * must be a scalar field the projection exposes (the same allow-list as L3).
 */
final class ProjectionAggregation
{
    public const OPERATORS = ['count', 'sum', 'avg', 'min', 'max'];

    /** Operators that mandate a `field`. */
    private const FIELD_REQUIRED = ['sum', 'avg', 'min', 'max'];

    /** Operators that require numeric values. */
    private const NUMERIC_ONLY = ['sum', 'avg'];

    /**
     * @param list<array{op:string,field:?string,as:string}> $specs
     */
    private function __construct(private readonly array $specs)
    {
    }

    /**
     * Parse + validate `$params['aggregate']` against what the projection
     * exposes. An absent `aggregate` key yields an empty (no-op) instance.
     *
     * @param array<string,mixed> $params
     */
    public static function fromParams(array $params, ProjectionDefinition $projection): self
    {
        if (!array_key_exists('aggregate', $params)) {
            return new self([]);
        }

        $allowed = [];
        foreach ($projection->fields as $exposed) {
            $allowed[$exposed->field] = true;
        }

        $raw = $params['aggregate'];
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw new QueryError('ausus:aggregate: aggregate must be a non-empty list of {op, field?, as}');
        }

        $specs = [];
        $aliases = [];
        foreach ($raw as $spec) {
            if (!is_array($spec) || !isset($spec['op'], $spec['as'])) {
                throw new QueryError('ausus:aggregate: each entry requires {op, as}');
            }
            $op = $spec['op'];
            $as = $spec['as'];
            if (!is_string($op) || !in_array($op, self::OPERATORS, true)) {
                throw new QueryError("ausus:aggregate: unknown operator '" . (is_string($op) ? $op : '?') . "'");
            }
            if (!is_string($as) || $as === '') {
                throw new QueryError('ausus:aggregate: alias (as) must be a non-empty string');
            }
            if (isset($aliases[$as])) {
                throw new QueryError("ausus:aggregate: duplicate alias '{$as}'");
            }
            $aliases[$as] = true;

            $field = $spec['field'] ?? null;
            if (in_array($op, self::FIELD_REQUIRED, true) && $field === null) {
                throw new QueryError("ausus:aggregate: operator '{$op}' requires a field");
            }
            if ($field !== null) {
                if (!is_string($field) || !isset($allowed[$field])) {
                    throw new QueryError("ausus:aggregate: field '" . (is_string($field) ? $field : '?') . "' is not exposed by this projection");
                }
            }

            $specs[] = ['op' => $op, 'field' => $field, 'as' => $as];
        }

        return new self($specs);
    }

    public function isEmpty(): bool
    {
        return $this->specs === [];
    }

    /**
     * Compute every aggregate over the visible rows.
     *
     * Each row is a rendered field map (per-field visibility already applied by
     * the runtime), so a field hidden for the current actor is simply absent and
     * never contributes — aggregates can never leak invisible values.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public function compute(array $rows): array
    {
        $out = [];
        foreach ($this->specs as $spec) {
            $out[$spec['as']] = $this->reduce($spec['op'], $spec['field'], $rows);
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function reduce(string $op, ?string $field, array $rows): mixed
    {
        if ($op === 'count' && $field === null) {
            return count($rows);
        }

        // Collect the visible, present, non-null values for $field.
        $values = [];
        foreach ($rows as $row) {
            if (!array_key_exists($field, $row)) {
                continue; // hidden (visibility) or absent → excluded
            }
            $v = $row[$field];
            if ($v === null) {
                continue;
            }
            if (in_array($op, self::NUMERIC_ONLY, true) && !is_numeric($v)) {
                throw new QueryError("ausus:aggregate: '{$op}' on '{$field}' requires numeric values");
            }
            $values[] = $v;
        }

        switch ($op) {
            case 'count':
                return count($values);
            case 'sum':
                return $this->numericSum($values);
            case 'avg':
                return $values === [] ? null : (float) $this->numericSum($values) / count($values);
            case 'min':
                return $this->extremum($values, -1);
            case 'max':
                return $this->extremum($values, 1);
        }

        return null; // unreachable: operators validated at parse time
    }

    /**
     * @param list<int|float|string> $values
     */
    private function numericSum(array $values): int|float
    {
        $sum = 0;
        foreach ($values as $v) {
            $sum += $v + 0; // numeric coercion (validated numeric upstream)
        }

        return $sum;
    }

    /**
     * @param list<mixed> $values
     * @param int $want -1 for min, 1 for max
     */
    private function extremum(array $values, int $want): mixed
    {
        if ($values === []) {
            return null;
        }
        $best = $values[0];
        foreach ($values as $v) {
            if ($this->compare($v, $best) === $want) {
                $best = $v;
            }
        }

        return $best;
    }

    private function compare(mixed $a, mixed $b): int
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }

        // normalize strcmp's arbitrary magnitude to -1/0/1
        return strcmp((string) $a, (string) $b) <=> 0;
    }
}

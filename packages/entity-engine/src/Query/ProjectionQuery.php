<?php

declare(strict_types=1);

namespace Ausus\Engine\Query;

use Ausus\Definition\ProjectionDefinition;
use Ausus\Entity;

/**
 * L3 — the Projection Query Language (v1): selection over a read shape.
 *
 * This is the official, additive read contract of AUSUS Gen2. It is applied in
 * the runtime, after a tenant-scoped `findAll()`, in this fixed order:
 *
 *     findAll → WHERE (filter) → ORDER BY (sort) → LIMIT/OFFSET (paginate) → render
 *
 * It is driver-agnostic: the Memory driver works unchanged, and a future
 * SQLite/Postgres driver may push the same contract down without changing it.
 * It is FAIL-CLOSED: any malformed clause, unknown operator, or reference to a
 * field the projection does not expose is rejected ({@see QueryError}).
 *
 * `$params` shape (all keys optional; an empty array is a no-op):
 *
 *   [
 *     'where'   => <group>,                                  // boolean filter
 *     'orderBy' => [ ['field' => 'total', 'dir' => 'desc'], … ],
 *     'limit'   => 20,                                       // 0..MAX_LIMIT
 *     'offset'  => 40,                                       // >= 0
 *   ]
 *
 *   <group>     := list<condition>                           // implicit AND
 *                | ['and' => list<group|condition>]
 *                | ['or'  => list<group|condition>]
 *   <condition> := ['field' => string, 'op' => <operator>, 'value' => mixed?]
 *
 * Operators: eq, ne, lt, lte, gt, gte, contains, startsWith, endsWith,
 * isNull, isNotNull. `value` is required for every operator except
 * isNull/isNotNull.
 *
 * v1 deliberately excludes joins, reverse relations, aggregations, computed
 * fields. Filter/sort fields are restricted to the projection's exposed scalar
 * fields (not expand targets) — the foundation for L4+.
 */
final class ProjectionQuery
{
    public const MAX_LIMIT = 200;

    public const OPERATORS = [
        'eq', 'ne', 'lt', 'lte', 'gt', 'gte',
        'contains', 'startsWith', 'endsWith', 'isNull', 'isNotNull',
    ];

    private const VALUELESS = ['isNull', 'isNotNull'];

    /**
     * @param array<string,mixed>|null              $filter normalized filter node
     * @param list<array{field:string,dir:string}>  $orderBy
     */
    private function __construct(
        private readonly ?array $filter,
        private readonly array $orderBy,
        private readonly ?int $limit,
        private readonly int $offset,
    ) {
    }

    /**
     * Parse + validate `$params` against what the projection exposes.
     *
     * @param array<string,mixed> $params
     */
    public static function fromParams(array $params, ProjectionDefinition $projection): self
    {
        // Allowed fields = the projection's exposed scalar fields (not expands).
        $allowed = [];
        foreach ($projection->fields as $exposed) {
            $allowed[$exposed->field] = true;
        }

        foreach (array_keys($params) as $key) {
            if (!in_array($key, ['where', 'orderBy', 'limit', 'offset'], true)) {
                throw new QueryError("ausus:query: unknown parameter '{$key}'");
            }
        }

        $filter = isset($params['where']) ? self::parseGroup($params['where'], $allowed) : null;
        $orderBy = self::parseOrderBy($params['orderBy'] ?? [], $allowed);
        $limit = self::parseLimit($params['limit'] ?? null);
        $offset = self::parseOffset($params['offset'] ?? null);

        return new self($filter, $orderBy, $limit, $offset);
    }

    /**
     * Apply WHERE → ORDER BY → LIMIT/OFFSET to a list of entities.
     *
     * @param list<Entity> $entities
     * @return list<Entity>
     */
    public function apply(array $entities): array
    {
        if ($this->filter !== null) {
            $entities = array_values(array_filter(
                $entities,
                fn (Entity $e): bool => $this->matches($this->filter, $e),
            ));
        }

        if ($this->orderBy !== []) {
            usort($entities, fn (Entity $a, Entity $b): int => $this->compareRows($a, $b));
        }

        if ($this->offset > 0 || $this->limit !== null) {
            $entities = array_slice($entities, $this->offset, $this->limit);
        }

        return array_values($entities);
    }

    // ── parsing (fail-closed) ────────────────────────────────────────────────

    /**
     * @param array<string,bool> $allowed
     * @return array<string,mixed>
     */
    private static function parseGroup(mixed $group, array $allowed): array
    {
        if (!is_array($group)) {
            throw new QueryError('ausus:query: where must be a list or an and/or group');
        }

        // and/or group
        if (isset($group['and']) || isset($group['or'])) {
            $op = isset($group['and']) ? 'and' : 'or';
            $items = $group[$op];
            if (!is_array($items) || $items === [] || !array_is_list($items)) {
                throw new QueryError("ausus:query: '{$op}' must be a non-empty list");
            }

            return ['kind' => $op, 'nodes' => array_map(
                fn ($n) => self::parseNode($n, $allowed),
                $items,
            )];
        }

        // bare list of conditions → implicit AND
        if (array_is_list($group)) {
            if ($group === []) {
                throw new QueryError('ausus:query: where list must not be empty');
            }

            return ['kind' => 'and', 'nodes' => array_map(
                fn ($n) => self::parseNode($n, $allowed),
                $group,
            )];
        }

        // single condition map
        return self::parseCondition($group, $allowed);
    }

    /** @param array<string,bool> $allowed @return array<string,mixed> */
    private static function parseNode(mixed $node, array $allowed): array
    {
        if (is_array($node) && (isset($node['and']) || isset($node['or']))) {
            return self::parseGroup($node, $allowed);
        }

        return self::parseCondition($node, $allowed);
    }

    /** @param array<string,bool> $allowed @return array<string,mixed> */
    private static function parseCondition(mixed $cond, array $allowed): array
    {
        if (!is_array($cond) || !isset($cond['field'], $cond['op'])) {
            throw new QueryError('ausus:query: a condition requires {field, op}');
        }
        $field = $cond['field'];
        $op = $cond['op'];

        if (!is_string($field) || !isset($allowed[$field])) {
            throw new QueryError("ausus:query: field '" . (is_string($field) ? $field : '?') . "' is not exposed by this projection");
        }
        if (!is_string($op) || !in_array($op, self::OPERATORS, true)) {
            throw new QueryError("ausus:query: unknown operator '" . (is_string($op) ? $op : '?') . "'");
        }

        if (in_array($op, self::VALUELESS, true)) {
            return ['kind' => 'cond', 'field' => $field, 'op' => $op, 'value' => null];
        }

        if (!array_key_exists('value', $cond)) {
            throw new QueryError("ausus:query: operator '{$op}' requires a value");
        }

        return ['kind' => 'cond', 'field' => $field, 'op' => $op, 'value' => $cond['value']];
    }

    /**
     * @param array<string,bool> $allowed
     * @return list<array{field:string,dir:string}>
     */
    private static function parseOrderBy(mixed $orderBy, array $allowed): array
    {
        if (!is_array($orderBy) || !array_is_list($orderBy)) {
            throw new QueryError('ausus:query: orderBy must be a list of {field, dir}');
        }
        $out = [];
        foreach ($orderBy as $spec) {
            if (!is_array($spec) || !isset($spec['field'])) {
                throw new QueryError('ausus:query: each orderBy entry requires a field');
            }
            $field = $spec['field'];
            $dir = strtolower((string) ($spec['dir'] ?? 'asc'));
            if (!is_string($field) || !isset($allowed[$field])) {
                throw new QueryError("ausus:query: cannot sort by '" . (is_string($field) ? $field : '?') . "' — not exposed by this projection");
            }
            if ($dir !== 'asc' && $dir !== 'desc') {
                throw new QueryError("ausus:query: sort direction must be 'asc' or 'desc'");
            }
            $out[] = ['field' => $field, 'dir' => $dir];
        }

        return $out;
    }

    private static function parseLimit(mixed $limit): ?int
    {
        if ($limit === null) {
            return null;
        }
        if (!is_int($limit) && !(is_string($limit) && ctype_digit($limit))) {
            throw new QueryError('ausus:query: limit must be a non-negative integer');
        }
        $n = (int) $limit;
        if ($n < 0) {
            throw new QueryError('ausus:query: limit must be >= 0');
        }

        return min($n, self::MAX_LIMIT);
    }

    private static function parseOffset(mixed $offset): int
    {
        if ($offset === null) {
            return 0;
        }
        if (!is_int($offset) && !(is_string($offset) && ctype_digit($offset))) {
            throw new QueryError('ausus:query: offset must be a non-negative integer');
        }
        $n = (int) $offset;
        if ($n < 0) {
            throw new QueryError('ausus:query: offset must be >= 0');
        }

        return $n;
    }

    // ── evaluation ───────────────────────────────────────────────────────────

    /** @param array<string,mixed> $node */
    private function matches(array $node, Entity $entity): bool
    {
        if ($node['kind'] === 'and') {
            foreach ($node['nodes'] as $child) {
                if (!$this->matches($child, $entity)) {
                    return false;
                }
            }

            return true;
        }
        if ($node['kind'] === 'or') {
            foreach ($node['nodes'] as $child) {
                if ($this->matches($child, $entity)) {
                    return true;
                }
            }

            return false;
        }

        return $this->test($entity->field($node['field']), $node['op'], $node['value']);
    }

    private function test(mixed $actual, string $op, mixed $value): bool
    {
        switch ($op) {
            case 'isNull':    return $actual === null;
            case 'isNotNull': return $actual !== null;
            case 'eq':        return self::looseEquals($actual, $value);
            case 'ne':        return !self::looseEquals($actual, $value);
            case 'contains':   return $actual !== null && str_contains((string) $actual, (string) $value);
            case 'startsWith': return $actual !== null && str_starts_with((string) $actual, (string) $value);
            case 'endsWith':   return $actual !== null && str_ends_with((string) $actual, (string) $value);
            case 'lt': case 'lte': case 'gt': case 'gte':
                $cmp = self::compareScalar($actual, $value);
                if ($cmp === null) {
                    return false; // null never satisfies an ordered comparison
                }

                return match ($op) {
                    'lt'  => $cmp < 0,
                    'lte' => $cmp <= 0,
                    'gt'  => $cmp > 0,
                    'gte' => $cmp >= 0,
                };
        }

        return false; // unreachable: operators validated at parse time
    }

    private static function looseEquals(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return (string) $a === (string) $b;
    }

    /** @return int|null -1/0/1, or null when either side is null */
    private static function compareScalar(mixed $a, mixed $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }

        return strcmp((string) $a, (string) $b);
    }

    private function compareRows(Entity $a, Entity $b): int
    {
        foreach ($this->orderBy as $spec) {
            $va = $a->field($spec['field']);
            $vb = $b->field($spec['field']);
            // nulls sort last regardless of direction
            if ($va === null && $vb === null) {
                continue;
            }
            if ($va === null) {
                return 1;
            }
            if ($vb === null) {
                return -1;
            }
            $cmp = self::compareScalar($va, $vb) ?? 0;
            if ($cmp !== 0) {
                return $spec['dir'] === 'desc' ? -$cmp : $cmp;
            }
        }

        return 0;
    }
}

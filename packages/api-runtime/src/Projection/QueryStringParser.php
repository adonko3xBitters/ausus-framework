<?php

declare(strict_types=1);

namespace Ausus\Api\Runtime\Projection;

/**
 * L3 — translate a flat HTTP query string into the structured projection-query
 * params consumed by RuntimeEntity::read(). It only RESHAPES; it never
 * validates — the runtime ProjectionQuery is the single fail-closed authority,
 * so a malformed clause surfaces as a 400 from the runtime.
 *
 * Encoding (GET /api/entities/{e}/projections/{p}?…):
 *
 *   ?limit=20&offset=40                         pagination
 *   ?orderBy=priority:desc,title:asc            sorting (alias: sort)
 *   ?where=status:eq:open,priority:gte:3        explicit filters (comma = AND)
 *   ?where=assignee:isNull                      valueless operators
 *   ?status=open                                shorthand: <field>=<value> ⇒ eq
 *   ?aggregate=count:rooms,sum:total:revenue    KPI aggregations (L4)
 *
 * Shorthand filters and explicit `where` are merged into one AND list. OR /
 * nested groups are available through the structured read() params; the v1 HTTP
 * shorthand is AND-only by design.
 *
 * Aggregate clause grammar (comma-separated): `op:as` (count, no field) or
 * `op:field:as` (sum/avg/min/max, and count-of-field). The runtime
 * {@see \Ausus\Engine\Query\ProjectionAggregation} is the single fail-closed
 * authority, so an unknown op / unexposed field surfaces as a 400.
 */
final class QueryStringParser
{
    private const RESERVED = ['where', 'orderBy', 'sort', 'limit', 'offset', 'aggregate'];

    /**
     * @param array<string,mixed> $body flat query params
     * @return array<string,mixed> structured params for read()
     */
    public static function parse(array $body): array
    {
        $params = [];
        $where = [];

        // explicit where=field:op:value,...
        if (isset($body['where']) && is_string($body['where']) && $body['where'] !== '') {
            foreach (explode(',', $body['where']) as $clause) {
                $where[] = self::condition($clause);
            }
        }

        // shorthand: any non-reserved key ⇒ eq
        foreach ($body as $key => $value) {
            if (in_array($key, self::RESERVED, true) || !is_string($key)) {
                continue;
            }
            $where[] = ['field' => $key, 'op' => 'eq', 'value' => $value];
        }

        if ($where !== []) {
            $params['where'] = $where;
        }

        // orderBy / sort = field:dir,...
        $order = $body['orderBy'] ?? $body['sort'] ?? null;
        if (is_string($order) && $order !== '') {
            $orderBy = [];
            foreach (explode(',', $order) as $spec) {
                $parts = explode(':', $spec, 2);
                $orderBy[] = ['field' => $parts[0], 'dir' => $parts[1] ?? 'asc'];
            }
            $params['orderBy'] = $orderBy;
        }

        if (isset($body['limit'])) {
            $params['limit'] = $body['limit'];
        }
        if (isset($body['offset'])) {
            $params['offset'] = $body['offset'];
        }

        // aggregate = op:as,op:field:as,...
        if (isset($body['aggregate']) && is_string($body['aggregate']) && $body['aggregate'] !== '') {
            $aggregate = [];
            foreach (explode(',', $body['aggregate']) as $clause) {
                $aggregate[] = self::aggregateSpec($clause);
            }
            $params['aggregate'] = $aggregate;
        }

        return $params;
    }

    /** @return array<string,mixed> */
    private static function aggregateSpec(string $clause): array
    {
        // op:as (count) | op:field:as (sum/avg/min/max, count-of-field)
        $parts = explode(':', $clause, 3);
        if (count($parts) >= 3) {
            return ['op' => $parts[0], 'field' => $parts[1], 'as' => $parts[2]];
        }

        return ['op' => $parts[0], 'as' => $parts[1] ?? ''];
    }

    /** @return array<string,mixed> */
    private static function condition(string $clause): array
    {
        // field:op:value — value may itself contain ':', so split into 3 max.
        $parts = explode(':', $clause, 3);
        $field = $parts[0];
        $op = $parts[1] ?? '';
        if (in_array($op, ['isNull', 'isNotNull'], true) || count($parts) < 3) {
            return ['field' => $field, 'op' => $op];
        }

        return ['field' => $field, 'op' => $op, 'value' => $parts[2]];
    }
}

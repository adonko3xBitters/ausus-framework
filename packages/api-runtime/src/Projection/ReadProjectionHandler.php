<?php
declare(strict_types=1);

namespace Ausus\Api\Runtime\Projection;

use Ausus\Contracts\Context;
use Ausus\Contracts\EntityEngine;
use Ausus\Contracts\SchemaRepository;
use Ausus\PersistenceDriver;

/**
 * IMPLEMENTATION-002 — GET /api/entities/{entity}/projections/{projection}.
 *
 * Pipeline: resolve(entity) → bind(schema, driver) → read(projection, params,
 * context). Visibility and single-hop expand are applied by the RuntimeEntity.
 * No compile/canonicalise/hash/DSL.
 */
final class ReadProjectionHandler
{
    public function __construct(
        private readonly SchemaRepository $schemas,
        private readonly EntityEngine $engine,
        private readonly PersistenceDriver $driver,
    ) {
    }

    /**
     * @param array<string,mixed> $params
     * @return array{rows: list<array<string,mixed>>}
     */
    public function handle(string $entity, string $projection, array $params, Context $context): array
    {
        $schema = $this->schemas->resolve($entity);
        $runtime = $this->engine->bind($schema, $this->driver);

        return ['rows' => $runtime->read($projection, $params, $context)];
    }
}

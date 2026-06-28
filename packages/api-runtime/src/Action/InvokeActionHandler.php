<?php
declare(strict_types=1);

namespace Ausus\Api\Runtime\Action;

use Ausus\Contracts\Context;
use Ausus\Contracts\EntityEngine;
use Ausus\Contracts\SchemaRepository;
use Ausus\Entity;
use Ausus\PersistenceDriver;

/**
 * IMPLEMENTATION-002 — POST /api/entities/{entity}/actions/{action}.
 *
 * Pipeline (and nothing else): resolve(entity) → bind(schema, driver)
 * → invoke(action, inputs, context). No compile, no canonicalise, no hash, no
 * DSL. Consumes only the SchemaRepository, EntityEngine and PersistenceDriver
 * contracts.
 */
final class InvokeActionHandler
{
    public function __construct(
        private readonly SchemaRepository $schemas,
        private readonly EntityEngine $engine,
        private readonly PersistenceDriver $driver,
    ) {
    }

    /**
     * @param array<string,mixed> $inputs
     * @return array<string,mixed>
     */
    public function handle(string $entity, string $action, array $inputs, Context $context): array
    {
        $schema = $this->schemas->resolve($entity);
        $runtime = $this->engine->bind($schema, $this->driver);
        $result = $runtime->invoke($action, $inputs, $context);

        return $this->serialize($result);
    }

    /** @return array<string,mixed> */
    private function serialize(Entity $entity): array
    {
        return [
            'reference' => [
                'tenantId' => $entity->reference->tenantId,
                'entityFqn' => $entity->reference->entityFqn,
                'identityHandle' => $entity->reference->identityHandle,
            ],
            'version' => $entity->version->value,
            'fields' => $entity->fields,
        ];
    }
}

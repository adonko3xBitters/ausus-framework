<?php
declare(strict_types=1);

namespace Ausus\Persistence\Memory;

use Ausus\Entity;
use Ausus\Reference;
use Ausus\Repository;
use Ausus\Tenant;
use Ausus\TransactionHandle;
use Ausus\Version;
use RuntimeException;

/**
 * IMPLEMENTATION-001 Phase 9 — in-memory {@see Repository}, bound to a
 * {@see MemoryDriver}, a tenant, a transaction handle, and an entity FQN.
 *
 * All reads/writes go through the driver's committed store + the handle's
 * staging overlay (read-your-writes within the transaction). No business logic,
 * no caching, no optimisation. The frozen contract has no delete — none added.
 */
final class MemoryRepository implements Repository
{
    public function __construct(
        private readonly MemoryDriver $driver,
        private readonly Tenant $tenant,
        private readonly TransactionHandle $handle,
        private readonly string $entityFqn,
    ) {
    }

    public function find(Reference $ref): ?Entity
    {
        return $this->driver->lookup($this->handle, $this->tenant, $this->entityFqn, $ref->identityHandle);
    }

    /** @param array<string,mixed> $payload */
    public function create(array $payload, ?string $identity = null): Entity
    {
        $identity ??= $this->driver->generateIdentity($this->entityFqn);
        $entity = new Entity(
            new Reference($this->tenant->value(), $this->entityFqn, $identity),
            new Version('1'),
            $payload,
        );
        $this->driver->stage($this->handle, $this->tenant, $this->entityFqn, $entity);

        return $entity;
    }

    /** @param array<string,mixed> $patch */
    public function update(Reference $ref, array $patch, Version $expected): Entity
    {
        $current = $this->driver->lookup($this->handle, $this->tenant, $this->entityFqn, $ref->identityHandle);
        if ($current === null) {
            throw new RuntimeException("MemoryRepository: entity '{$ref->identityHandle}' not found");
        }
        if ($current->version->value !== $expected->value) {
            throw new RuntimeException("MemoryRepository: version conflict on '{$ref->identityHandle}'");
        }

        $updated = new Entity(
            $current->reference,
            new Version((string) ((int) $current->version->value + 1)),
            array_merge($current->fields, $patch),
        );
        $this->driver->stage($this->handle, $this->tenant, $this->entityFqn, $updated);

        return $updated;
    }

    /** @return list<Entity> */
    public function findAll(): array
    {
        return $this->driver->lookupAll($this->handle, $this->tenant, $this->entityFqn);
    }
}

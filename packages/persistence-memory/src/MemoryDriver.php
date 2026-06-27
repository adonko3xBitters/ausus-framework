<?php
declare(strict_types=1);

namespace Ausus\Persistence\Memory;

use Ausus\Entity;
use Ausus\PersistenceContext;
use Ausus\PersistenceDriver;
use Ausus\Repository;
use Ausus\Tenant;
use Ausus\TransactionHandle;

/**
 * IMPLEMENTATION-001 Phase 9 — the first official AUSUS Driver: an in-memory
 * {@see PersistenceDriver}, reference conformance target and test support.
 *
 * Transaction model — committed store + per-handle staging overlay:
 *   - beginTransaction() opens an independent handle with an empty staging area;
 *   - create()/update() (via the repository) write into the handle's staging;
 *   - reads see committed ∪ own-staging (read-your-writes); another handle sees
 *     only committed — uncommitted writes are invisible across transactions;
 *   - commit() merges the handle's staging into the committed store;
 *   - rollback() discards the handle's staging.
 * Nested transactions are not required; each beginTransaction() is independent.
 *
 * Deterministic: no clock, no random, no disk, no network. Identities come from
 * a monotonic counter. No business/RuntimeEntity/Authorization/Compiler logic.
 */
final class MemoryDriver implements PersistenceDriver
{
    /** @var array<string,array<string,array<string,Entity>>> committed [tenant][fqn][identity] */
    private array $committed = [];
    /** @var array<int,array<string,array<string,array<string,Entity>>>> staging [handleId][tenant][fqn][identity] */
    private array $staging = [];
    /** @var array<int,TransactionHandle> keep open handles alive (prevents spl_object_id reuse) */
    private array $handles = [];
    private int $idCounter = 0;

    public function beginTransaction(Tenant $tenant): TransactionHandle
    {
        $handle = new class($tenant) implements TransactionHandle {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function tenant(): Tenant
            {
                return $this->tenant;
            }
        };
        $id = spl_object_id($handle);
        $this->staging[$id] = [];
        $this->handles[$id] = $handle;

        return $handle;
    }

    public function commit(TransactionHandle $h): void
    {
        $id = spl_object_id($h);
        foreach ($this->staging[$id] ?? [] as $tenant => $byFqn) {
            foreach ($byFqn as $fqn => $byIdentity) {
                foreach ($byIdentity as $identity => $entity) {
                    $this->committed[$tenant][$fqn][$identity] = $entity;
                }
            }
        }
        unset($this->staging[$id], $this->handles[$id]);
    }

    public function rollback(TransactionHandle $h): void
    {
        $id = spl_object_id($h);
        unset($this->staging[$id], $this->handles[$id]);
    }

    public function context(Tenant $tenant, TransactionHandle $h): PersistenceContext
    {
        return new class($this, $tenant, $h) implements PersistenceContext {
            public function __construct(
                private readonly MemoryDriver $driver,
                private readonly Tenant $tenant,
                private readonly TransactionHandle $handle,
            ) {
            }

            public function repository(string $entityFqn): Repository
            {
                return new MemoryRepository($this->driver, $this->tenant, $this->handle, $entityFqn);
            }

            public function tenant(): Tenant
            {
                return $this->tenant;
            }
        };
    }

    public function generateIdentity(string $entityFqn): string
    {
        return 'mem-' . (++$this->idCounter);
    }

    // ── internal API consumed by MemoryRepository ────────────────────────────

    public function lookup(TransactionHandle $h, Tenant $tenant, string $fqn, string $identity): ?Entity
    {
        $t = $tenant->value();

        return $this->staging[spl_object_id($h)][$t][$fqn][$identity]
            ?? $this->committed[$t][$fqn][$identity]
            ?? null;
    }

    /** @return list<Entity> */
    public function lookupAll(TransactionHandle $h, Tenant $tenant, string $fqn): array
    {
        $t = $tenant->value();
        $merged = $this->committed[$t][$fqn] ?? [];
        foreach ($this->staging[spl_object_id($h)][$t][$fqn] ?? [] as $identity => $entity) {
            $merged[$identity] = $entity;
        }
        ksort($merged);

        return array_values($merged);
    }

    public function stage(TransactionHandle $h, Tenant $tenant, string $fqn, Entity $entity): void
    {
        $this->staging[spl_object_id($h)][$tenant->value()][$fqn][$entity->reference->identityHandle] = $entity;
    }
}

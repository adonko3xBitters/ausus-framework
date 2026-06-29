<?php

declare(strict_types=1);

namespace Ausus\Persistence\Sqlite;

use Ausus\Entity;
use Ausus\Persistence\Sqlite\Dialect\Dialect;
use Ausus\Reference;
use Ausus\Repository;
use Ausus\Tenant;
use Ausus\Version;
use PDO;

/**
 * SQLite {@see Repository}, bound to one PDO connection (the active transaction
 * handle), one tenant, and one entity FQN. All statements are tenant-scoped and
 * parameterised; the business payload is stored as JSON.
 *
 * Behaviour is byte-equivalent to {@see \Ausus\Persistence\Memory\MemoryRepository}:
 *   - `create` writes version "1" and a generated identity (UUID);
 *   - `update` bumps the version, merges the patch, and enforces optimistic
 *     concurrency (wrong expected version → conflict; missing row → not found);
 *   - `findAll` returns the tenant's rows of this kind, ordered by identity;
 *   - the frozen contract has no delete — none is added.
 */
final class SqliteRepository implements Repository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Dialect $dialect,
        private readonly string $table,
        private readonly Tenant $tenant,
        private readonly string $entityFqn,
        private readonly SqliteDriver $driver,
    ) {
    }

    public function find(Reference $ref): ?Entity
    {
        $t = $this->qTable();
        $stmt = $this->pdo->prepare(
            "SELECT identity, version, fields_json FROM {$t}
             WHERE tenant_id = ? AND entity_fqn = ? AND identity = ?"
        );
        $stmt->execute([$this->tenant->value(), $this->entityFqn, $ref->identityHandle]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
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

        $t = $this->qTable();
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$t} (tenant_id, entity_fqn, identity, version, fields_json)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $this->tenant->value(), $this->entityFqn, $identity, '1', $this->encode($payload),
        ]);

        return $entity;
    }

    /** @param array<string,mixed> $patch */
    public function update(Reference $ref, array $patch, Version $expected): Entity
    {
        $current = $this->find($ref);
        if ($current === null) {
            throw SqlPersistenceError::notFound($ref->identityHandle);
        }
        if ($current->version->value !== $expected->value) {
            throw SqlPersistenceError::versionConflict($ref->identityHandle);
        }

        $next = new Version((string) ((int) $current->version->value + 1));
        $merged = array_merge($current->fields, $patch);

        $t = $this->qTable();
        $stmt = $this->pdo->prepare(
            "UPDATE {$t} SET version = ?, fields_json = ?
             WHERE tenant_id = ? AND entity_fqn = ? AND identity = ? AND version = ?"
        );
        $stmt->execute([
            $next->value, $this->encode($merged),
            $this->tenant->value(), $this->entityFqn, $ref->identityHandle, $expected->value,
        ]);
        if ($stmt->rowCount() === 0) {
            // Lost the row to a concurrent writer between SELECT and UPDATE.
            throw SqlPersistenceError::versionConflict($ref->identityHandle);
        }

        return new Entity($current->reference, $next, $merged);
    }

    /** @return list<Entity> */
    public function findAll(): array
    {
        $t = $this->qTable();
        $stmt = $this->pdo->prepare(
            "SELECT identity, version, fields_json FROM {$t}
             WHERE tenant_id = ? AND entity_fqn = ? ORDER BY identity ASC"
        );
        $stmt->execute([$this->tenant->value(), $this->entityFqn]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function qTable(): string
    {
        return $this->dialect->quoteIdentifier($this->table);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Entity
    {
        /** @var array<string,mixed> $fields */
        $fields = json_decode((string) $row['fields_json'], true, 512, JSON_THROW_ON_ERROR);

        return new Entity(
            new Reference($this->tenant->value(), $this->entityFqn, (string) $row['identity']),
            new Version((string) $row['version']),
            $fields,
        );
    }

    /** @param array<string,mixed> $fields */
    private function encode(array $fields): string
    {
        return json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

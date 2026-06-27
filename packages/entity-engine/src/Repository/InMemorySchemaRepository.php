<?php
declare(strict_types=1);

namespace Ausus\Engine\Repository;

use Ausus\Compiled\EntitySchema;
use Ausus\Contracts\SchemaRepository;
use RuntimeException;

/**
 * IMPLEMENTATION-001 Phase 6 — in-memory content-addressed schema store
 * (RFC-CLI-001 §Q4/Q5/Q7).
 *
 * Holds compiled {@see EntitySchema} objects keyed by hash, plus an EntityId →
 * hash index. No disk, no compile, no hash, no canonicalisation — it works
 * purely from already-produced schemas.
 */
final class InMemorySchemaRepository implements SchemaRepository
{
    /** @var array<string,EntitySchema> hash → schema */
    private array $byHash = [];
    /** @var array<string,string> EntityId → hash */
    private array $index = [];

    public function putByHash(EntitySchema $schema): void
    {
        // Q5: identical hash ⇒ no rewrite (keep the first stored instance).
        if (!isset($this->byHash[$schema->hash])) {
            $this->byHash[$schema->hash] = $schema;
        }
        $this->index[$schema->identity] = $schema->hash;
    }

    public function getByHash(string $hash): EntitySchema
    {
        return $this->byHash[$hash]
            ?? throw new RuntimeException("no schema for hash '{$hash}'");
    }

    public function resolve(string $entityId): EntitySchema
    {
        $hash = $this->index[$entityId]
            ?? throw new RuntimeException("no schema for entity '{$entityId}'");

        return $this->getByHash($hash);
    }
}

<?php
declare(strict_types=1);

namespace Ausus\Compiled;

/**
 * RFC-CLI-001 §Q7 — the EntityId → content-hash retrieval map.
 *
 * Reconstructible from the schema set, kept for O(1) bind resolution. The
 * lookup is a pure map read, not business logic.
 */
final readonly class SchemaIndex
{
    /** @param array<string,string> $map EntityId → content hash */
    public function __construct(
        public array $map = [],
    ) {
    }

    public function hashFor(string $entityId): ?string
    {
        return $this->map[$entityId] ?? null;
    }
}

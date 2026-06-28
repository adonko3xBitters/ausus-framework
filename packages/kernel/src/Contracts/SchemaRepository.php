<?php
declare(strict_types=1);

namespace Ausus\Contracts;

use Ausus\Compiled\EntitySchema;

/**
 * RFC-CLI-001 §Q4 — content-addressed store of compiled schemas.
 *
 * `putByHash`/`getByHash` address by content hash; `resolve` maps an EntityId to
 * its current schema via the index. Enables EntityEngine::bind() without
 * recompilation.
 */
interface SchemaRepository
{
    public function putByHash(EntitySchema $schema): void;

    public function getByHash(string $hash): EntitySchema;

    public function resolve(string $entityId): EntitySchema;
}

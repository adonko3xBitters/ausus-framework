<?php
declare(strict_types=1);

namespace Ausus\Engine\Compile;

use Ausus\Compiled\EntitySchema;
use Ausus\Compiled\SchemaIndex;
use Ausus\Compiled\SchemaVersion;
use Ausus\Definition\EntityDefinition;

/**
 * IMPLEMENTATION-001 Phase 4 — the "compile" half of RFC-011.
 *
 *   EntityDefinition[] → ClosureValidator → Canonicalizer → Hasher
 *                      → EntitySchema[] + SchemaIndex
 *
 * Atomic: global closure runs first; any violation aborts with
 * {@see CompilationError} and NO partial output. No disk, no Driver, no bind,
 * no runtime.
 */
final class Compiler
{
    // Temporary stamps (RFC-CLI-001 §Q8). Excluded from the content hash.
    private const SCHEMA_VERSION = '0.1.0';
    private const KERNEL_VERSION = '0.1.0';
    private const ENGINE_VERSION = '0.1.0';

    public function __construct(
        private readonly ClosureValidator $validator = new ClosureValidator(),
        private readonly Canonicalizer $canonicalizer = new Canonicalizer(),
        private readonly Hasher $hasher = new Hasher(),
    ) {
    }

    /**
     * @param list<EntityDefinition> $definitions
     */
    public function compile(array $definitions): CompiledGraph
    {
        // Global closure — atomic. Throws before anything is built.
        $this->validator->validate($definitions);

        $schemas = [];
        $map = [];
        foreach ($definitions as $def) {
            // Hash comes exclusively from Hasher(Canonicalizer(def)).
            $hash = $this->hasher->hash($this->canonicalizer->canonicalize($def));

            $schemas[] = new EntitySchema(
                new SchemaVersion(self::SCHEMA_VERSION, self::KERNEL_VERSION, self::ENGINE_VERSION),
                $hash,
                $def->identity,
                $def->tenantScoped,
                $def->fields,        // compiled definition payload — nothing else
                $def->actions,
                $def->projections,
            );
            $map[$def->identity] = $hash;
        }

        return new CompiledGraph($schemas, new SchemaIndex($map));
    }
}

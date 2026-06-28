<?php
declare(strict_types=1);

namespace Ausus\Engine\Compile;

use Ausus\Compiled\EntitySchema;
use Ausus\Compiled\SchemaIndex;

/**
 * IMPLEMENTATION-001 Phase 4 — output of {@see Compiler::compile()}.
 *
 * Technical L1 DTO: the compiled schemas plus the EntityId → hash index. It
 * introduces no kernel concept and modifies no RFC.
 */
final readonly class CompiledGraph
{
    /** @param list<EntitySchema> $schemas */
    public function __construct(
        public array $schemas,
        public SchemaIndex $index,
    ) {
    }
}

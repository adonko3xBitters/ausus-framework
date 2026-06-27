<?php
declare(strict_types=1);

namespace Ausus\Contracts;

use Ausus\Compiled\EntitySchema;
use Ausus\PersistenceDriver;

/**
 * RFC-011 — the Entity Engine, bind half ONLY.
 *
 * Per the IMPLEMENTATION-001 correction (Q2), compilation lives solely in the
 * Compiler (entity-engine, L1, batch + global closure); the engine does NOT
 * duplicate it. `bind` wires a compiled {@see EntitySchema} to a frozen
 * {@see PersistenceDriver}, yielding an executable {@see RuntimeEntity}.
 */
interface EntityEngine
{
    public function bind(EntitySchema $schema, PersistenceDriver $driver): RuntimeEntity;
}

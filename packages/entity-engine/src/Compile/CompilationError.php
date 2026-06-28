<?php
declare(strict_types=1);

namespace Ausus\Engine\Compile;

use RuntimeException;

/**
 * IMPLEMENTATION-001 Phase 4 — raised when an EntityDefinition set fails any of
 * the 16 RFC-012 §Q6 closure invariants.
 *
 * Validation is atomic: the first violation aborts the whole compile with an
 * explicit message; no partial output is produced.
 */
final class CompilationError extends RuntimeException
{
}

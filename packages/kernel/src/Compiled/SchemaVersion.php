<?php
declare(strict_types=1);

namespace Ausus\Compiled;

/**
 * RFC-CLI-001 §Q8 — version stamp carried by an EntitySchema.
 *
 * Artefact/engine metadata, EXCLUDED from the content hash. Compatibility is a
 * gate (refuse), not a migrator: incompatible ⇒ recompile from source.
 */
final readonly class SchemaVersion
{
    public function __construct(
        public string $schemaVersion,
        public string $kernelVersion,
        public string $engineVersion,
    ) {
    }
}

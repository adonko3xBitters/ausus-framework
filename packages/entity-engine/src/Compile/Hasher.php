<?php
declare(strict_types=1);

namespace Ausus\Engine\Compile;

/**
 * IMPLEMENTATION-001 Phase 3 — content-addressed hash of a canonical definition.
 *
 * Pure, total function `array → string`. The input MUST be exactly the output of
 * {@see Canonicalizer::canonicalize()} (the RFC-012 §Q7 semantic normal form);
 * the Hasher validates nothing and assumes its input is already canonical.
 *
 * The hash depends ONLY on the canonical/semantic content. It never depends on
 * the DSL source, declaration order, file/path, author, date, engine/kernel
 * version, stamps, or runtime data — those never reach the canonical form, so
 * they cannot reach the hash.
 *
 * NO disk, NO closure, NO EntitySchema, NO repository, NO CLI, NO runtime.
 */
final class Hasher
{
    /**
     * @param array<string,mixed> $canonicalDefinition the Canonicalizer output
     * @return string lowercase 64-char SHA-256 hex digest
     */
    public function hash(array $canonicalDefinition): string
    {
        $json = json_encode(
            $canonicalDefinition,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash('sha256', $json);
    }
}

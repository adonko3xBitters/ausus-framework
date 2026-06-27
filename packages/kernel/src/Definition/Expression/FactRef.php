<?php
declare(strict_types=1);

namespace Ausus\Definition\Expression;

use Ausus\Definition\Enum\FactSource;

/**
 * RFC-012 §Q5 — a reference to a fact ⟨source, path⟩.
 *
 * One of the two operand shapes (the other being {@see Literal}); operands are
 * typed as the union `FactRef|Literal`, so no marker interface is introduced.
 */
final readonly class FactRef
{
    public function __construct(
        public FactSource $source,
        public string $path,
    ) {
    }
}

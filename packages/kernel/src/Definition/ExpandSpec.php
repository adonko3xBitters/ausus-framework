<?php
declare(strict_types=1);

namespace Ausus\Definition;

/**
 * RFC-012 §Q4 — single-hop inclusion of a referenced entity's read-shape.
 *
 * `$via` names a `reference`-typed field of the owner entity; `$projection`
 * names a Projection of the target entity. Depth ≤ 1 and no expand-of-expand
 * are enforced at compile.
 */
final readonly class ExpandSpec
{
    public function __construct(
        public string $via,
        public string $projection,
    ) {
    }
}

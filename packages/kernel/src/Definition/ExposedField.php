<?php
declare(strict_types=1);

namespace Ausus\Definition;

use Ausus\Definition\Expression\Expression;

/**
 * RFC-012 §Q4 — a field revealed by a Projection, with optional per-field
 * read authorization.
 *
 * `$field` is a FieldRef into the owner (or an expanded) entity. `$visibility`
 * is the embedded AuthorizationRule; absent ⇒ always visible.
 */
final readonly class ExposedField
{
    public function __construct(
        public string $field,
        public ?Expression $visibility = null,
    ) {
    }
}

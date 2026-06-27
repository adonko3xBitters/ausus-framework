<?php
declare(strict_types=1);

namespace Ausus\Definition\Enum;

/**
 * RFC-012 §Q5 — comparison operators of the Expression sub-language.
 *
 * Irreducible primitives: {Eq, Lt}. The remaining {Ne, Lte, Gt, Gte, In} are
 * surface sugar normalized to primitives BEFORE hashing. Normalization is the
 * Canonicalizer's job (Phase 2); this enum only carries the author-written op.
 */
enum Comparator: string
{
    case Eq  = 'eq';
    case Ne  = 'ne';
    case Lt  = 'lt';
    case Lte = 'lte';
    case Gt  = 'gt';
    case Gte = 'gte';
    case In  = 'in';
}

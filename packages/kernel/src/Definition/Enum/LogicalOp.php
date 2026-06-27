<?php
declare(strict_types=1);

namespace Ausus\Definition\Enum;

/**
 * RFC-012 §Q5 — logical connectors of the Expression sub-language.
 *
 * Irreducible primitives: {Not, And}. `Or` is surface sugar normalized to
 * {Not, And} before hashing (Phase 2). This enum only carries the author op.
 */
enum LogicalOp: string
{
    case Not = 'not';
    case And = 'and';
    case Or  = 'or';
}

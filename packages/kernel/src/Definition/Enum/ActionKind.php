<?php
declare(strict_types=1);

namespace Ausus\Definition\Enum;

/**
 * RFC-012 §Q3 — the kind of mutation an Action performs. Determines the
 * effect semantics (reconstructed from kind + fields + transition); no
 * separate effectClass is declared.
 */
enum ActionKind: string
{
    case Create     = 'create';
    case Update     = 'update';
    case Transition = 'transition';
}

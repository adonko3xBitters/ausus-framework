<?php
declare(strict_types=1);

namespace Ausus\Definition\Enum;

/**
 * RFC-012 §Q5 — the origin of a fact referenced by an Expression.
 *
 * Context supplies who/where/when (Actor, Tenant, Now); Subject is the targeted
 * instance's fields; Input is the action's proposed input values.
 */
enum FactSource: string
{
    case Actor   = 'actor';
    case Tenant  = 'tenant';
    case Now     = 'now';
    case Subject = 'subject';
    case Input   = 'input';
}

<?php
declare(strict_types=1);

namespace Ausus\Definition\Expression;

/**
 * RFC-012 §Q5 — the embedded AuthorizationRule predicate.
 *
 * Marker for the closed set {Comparison, Logical}. It carries no kernel concept
 * beyond the frozen AuthorizationRule; it is a surface tree, not a new concept.
 * An Expression is embedded at exactly two sites: ActionDefinition::$guard and
 * ExposedField::$visibility.
 */
interface Expression
{
}

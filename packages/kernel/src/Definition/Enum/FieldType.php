<?php
declare(strict_types=1);

namespace Ausus\Definition\Enum;

/**
 * RFC-012 §Q2 — closed family of field value types.
 *
 * Type-specific parameters (enum value set, reference target, numeric scale,
 * string length) are carried by `FieldDefinition::$typeOptions`, NOT here.
 * `Identity` is the system identity field injected by the Compiler (Phase 4);
 * authors never declare it.
 */
enum FieldType: string
{
    case String    = 'string';
    case Integer   = 'integer';
    case Decimal   = 'decimal';
    case Boolean   = 'boolean';
    case Date      = 'date';
    case Enum      = 'enum';
    case Reference = 'reference';
    case Identity  = 'identity';
}

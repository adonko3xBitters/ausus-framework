<?php
declare(strict_types=1);

namespace Ausus\Authoring\Dsl;

use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\FieldDefinition;

/**
 * IMPLEMENTATION-001 Phase 5A — translates `(name, type, options)` into a frozen
 * RFC-012 {@see FieldDefinition}. Pure notation: no concept is added.
 *
 * Supported options: nullable, default, writeProtected, typeOptions.
 */
final class FieldBuilder
{
    /**
     * @param array{nullable?: bool, default?: string|int|float|bool|null, writeProtected?: bool, typeOptions?: array<string,mixed>} $options
     */
    public static function build(string $name, FieldType $type, array $options = []): FieldDefinition
    {
        return new FieldDefinition(
            name: $name,
            type: $type,
            nullable: $options['nullable'] ?? false,
            default: $options['default'] ?? null,
            writeProtected: $options['writeProtected'] ?? false,
            typeOptions: $options['typeOptions'] ?? [],
        );
    }
}

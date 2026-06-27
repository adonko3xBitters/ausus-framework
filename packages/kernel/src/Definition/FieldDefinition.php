<?php
declare(strict_types=1);

namespace Ausus\Definition;

use Ausus\Definition\Enum\FieldType;

/**
 * RFC-012 §Q2 — a typed data slot of an Entity.
 *
 * Shape: {name, type, nullable, default?, writeProtected, typeOptions}. `enum`
 * values and `reference` target live in `$typeOptions` (the single
 * type-parameter mechanism). Required params first per PHP; trailing params are
 * optional and match the RFC field order under named-argument construction.
 */
final readonly class FieldDefinition
{
    /** @param array<string,mixed> $typeOptions enum:{values:list<string>}, reference:{target:string}, … */
    public function __construct(
        public string $name,
        public FieldType $type,
        public bool $nullable,
        public string|int|float|bool|null $default = null,
        public bool $writeProtected = false,
        public array $typeOptions = [],
    ) {
    }
}

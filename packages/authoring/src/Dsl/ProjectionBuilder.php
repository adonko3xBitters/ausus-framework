<?php
declare(strict_types=1);

namespace Ausus\Authoring\Dsl;

use Ausus\Definition\ExpandSpec;
use Ausus\Definition\ExposedField;
use Ausus\Definition\Expression\Expression;
use Ausus\Definition\ProjectionDefinition;

/**
 * IMPLEMENTATION-001 Phase 5A — translates `(name, options)` into a frozen
 * RFC-012 {@see ProjectionDefinition}. Pure notation.
 *
 * Supported options: fields[{field, visibility?}], expand[{via, projection}].
 */
final class ProjectionBuilder
{
    /**
     * @param array{fields?: list<array{field: string, visibility?: ?Expression}>, expand?: list<array{via: string, projection: string}>} $options
     */
    public static function build(string $name, array $options): ProjectionDefinition
    {
        $fields = [];
        foreach ($options['fields'] ?? [] as $f) {
            $fields[] = new ExposedField(
                field: $f['field'],
                visibility: $f['visibility'] ?? null,
            );
        }

        $expand = [];
        foreach ($options['expand'] ?? [] as $e) {
            $expand[] = new ExpandSpec(
                via: $e['via'],
                projection: $e['projection'],
            );
        }

        return new ProjectionDefinition(name: $name, fields: $fields, expand: $expand);
    }
}

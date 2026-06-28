<?php
declare(strict_types=1);

namespace Ausus\Authoring\Dsl;

use Ausus\Definition\ActionDefinition;
use Ausus\Definition\EntityDefinition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\FieldDefinition;
use Ausus\Definition\ProjectionDefinition;

/**
 * IMPLEMENTATION-001 Phase 5A — closed fluent root of the authoring DSL.
 *
 * Its only product is a frozen RFC-012 {@see EntityDefinition} via `build()` —
 * no conversion, no adapter, no wrapper. The DSL is a notation; the model stays
 * RFC-012. It introduces no concept and depends only on the kernel.
 */
final class Definition
{
    /** @var list<FieldDefinition> */
    private array $fields = [];
    /** @var list<ActionDefinition> */
    private array $actions = [];
    /** @var list<ProjectionDefinition> */
    private array $projections = [];

    private function __construct(
        private readonly string $identity,
        private readonly bool $tenantScoped,
    ) {
    }

    public static function make(string $identity, bool $tenantScoped = false): self
    {
        return new self($identity, $tenantScoped);
    }

    /**
     * @param array{nullable?: bool, default?: string|int|float|bool|null, writeProtected?: bool, typeOptions?: array<string,mixed>} $options
     */
    public function field(string $name, FieldType $type, array $options = []): self
    {
        $this->fields[] = FieldBuilder::build($name, $type, $options);

        return $this;
    }

    /**
     * @param array{inputs?: list<string>, guard?: ?\Ausus\Definition\Expression\Expression, transition?: array{field: string, from: string|list<string>, to: string}} $options
     */
    public function action(string $name, ActionKind $kind, array $options = []): self
    {
        $this->actions[] = ActionBuilder::build($name, $kind, $options);

        return $this;
    }

    /**
     * @param array{fields?: list<array{field: string, visibility?: ?\Ausus\Definition\Expression\Expression}>, expand?: list<array{via: string, projection: string}>} $options
     */
    public function projection(string $name, array $options): self
    {
        $this->projections[] = ProjectionBuilder::build($name, $options);

        return $this;
    }

    public function build(): EntityDefinition
    {
        return new EntityDefinition(
            identity: $this->identity,
            tenantScoped: $this->tenantScoped,
            fields: $this->fields,
            actions: $this->actions,
            projections: $this->projections,
        );
    }
}

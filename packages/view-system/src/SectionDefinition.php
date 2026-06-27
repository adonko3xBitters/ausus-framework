<?php
declare(strict_types=1);

namespace Ausus\View;

/**
 * IMPLEMENTATION-004 — a section displays EITHER a projection OR an action of an
 * entity, never both. The exclusivity is structural: the private constructor is
 * only reachable through the {@see self::projection()} / {@see self::action()}
 * factories, each of which sets exactly one. Pure metadata — no compile, no
 * hash, no business knowledge.
 */
final readonly class SectionDefinition
{
    private function __construct(
        public string $title,
        public string $entity,
        public ?string $projection,
        public ?string $action,
    ) {
    }

    public static function projection(string $title, string $entity, string $projection): self
    {
        return new self($title, $entity, $projection, null);
    }

    public static function action(string $title, string $entity, string $action): self
    {
        return new self($title, $entity, null, $action);
    }

    /** @return 'projection'|'action' */
    public function kind(): string
    {
        return $this->projection !== null ? 'projection' : 'action';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'entity' => $this->entity,
            'kind' => $this->kind(),
            'projection' => $this->projection,
            'action' => $this->action,
        ];
    }
}

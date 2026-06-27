<?php
declare(strict_types=1);

namespace Ausus\View;

/**
 * IMPLEMENTATION-004 — a named view: a title plus a list of pages. Pure
 * presentation metadata, assembled (not compiled) and serialisable to JSON for
 * the React Renderer.
 */
final readonly class ViewDefinition
{
    /** @param list<PageDefinition> $pages */
    public function __construct(
        public string $identity,
        public string $title,
        public array $pages = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity,
            'title' => $this->title,
            'pages' => array_map(static fn (PageDefinition $p): array => $p->toArray(), $this->pages),
        ];
    }
}

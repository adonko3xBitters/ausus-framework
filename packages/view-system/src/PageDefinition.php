<?php
declare(strict_types=1);

namespace Ausus\View;

/**
 * IMPLEMENTATION-004 — a page groups sections under a title. Pure metadata.
 */
final readonly class PageDefinition
{
    /** @param list<SectionDefinition> $sections */
    public function __construct(
        public string $identity,
        public string $title,
        public array $sections = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity,
            'title' => $this->title,
            'sections' => array_map(static fn (SectionDefinition $s): array => $s->toArray(), $this->sections),
        ];
    }
}

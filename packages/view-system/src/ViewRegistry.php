<?php
declare(strict_types=1);

namespace Ausus\View;

use RuntimeException;

/**
 * IMPLEMENTATION-004 — holds the registered views and serialises them for the
 * Renderer. Assembly of metadata only: no compilation, no hash, no repository
 * of schemas. Navigation is derived from the views' pages.
 */
final class ViewRegistry
{
    /** @var array<string,ViewDefinition> */
    private array $views = [];

    public function register(ViewDefinition $view): void
    {
        $this->views[$view->identity] = $view;
    }

    public function get(string $identity): ViewDefinition
    {
        return $this->views[$identity] ?? throw new RuntimeException("unknown view '{$identity}'");
    }

    /** @return list<ViewDefinition> */
    public function all(): array
    {
        return array_values($this->views);
    }

    /**
     * @return list<array{view: string, title: string, pages: list<array{identity: string, title: string}>}>
     */
    public function navigation(): array
    {
        $out = [];
        foreach ($this->views as $view) {
            $out[] = [
                'view' => $view->identity,
                'title' => $view->title,
                'pages' => array_map(
                    static fn (PageDefinition $p): array => ['identity' => $p->identity, 'title' => $p->title],
                    $view->pages,
                ),
            ];
        }

        return $out;
    }

    /** @return array{views: list<array<string,mixed>>} */
    public function toArray(): array
    {
        return [
            'views' => array_map(static fn (ViewDefinition $v): array => $v->toArray(), array_values($this->views)),
        ];
    }
}

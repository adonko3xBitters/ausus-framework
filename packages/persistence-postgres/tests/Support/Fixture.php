<?php
declare(strict_types=1);
namespace Ausus\Persistence\Postgres\Tests;

use Ausus\{Compiler, DslPlugin, Dsl, Field, Action, MetadataGraph};

function compat_plugin(): DslPlugin {
    return new class extends DslPlugin {
        public function name(): string { return 'compat'; }
        public function phpNamespace(): string { return 'CompatTest'; }
        public function dsl(Dsl $dsl): void {
            $dsl->entity('doc')->fields([
                'title'     => Field::string(),
                'count'     => Field::integer(),
                'due'       => Field::datetime(),
                'status'    => Field::enum('DRAFT', 'PUBLISHED')->default('DRAFT'),
                'price'     => Field::money()->currency('EUR'),
                'parent_id' => Field::reference('compat.doc')->nullable(),
                'note'      => Field::string()->nullable(),
            ])->workflow(field: 'status', initial: 'DRAFT')
              ->actions(['create' => Action::create('title', 'count', 'due', 'price', 'note')->requireRole('compat.user')])
              ->projection('detail', fields: ['id', 'title', 'status', 'price'], role: 'compat.user');
        }
    };
}

function compat_graph(): MetadataGraph {
    return (new Compiler())->compile([compat_plugin()]);
}

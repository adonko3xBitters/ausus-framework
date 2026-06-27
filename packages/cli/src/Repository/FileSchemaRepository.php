<?php
declare(strict_types=1);

namespace Ausus\Cli\Repository;

use Ausus\Compiled\EntitySchema;
use Ausus\Compiled\SchemaVersion;
use Ausus\Contracts\SchemaRepository;
use Ausus\Definition\ActionDefinition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\Comparator;
use Ausus\Definition\Enum\FactSource;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\Enum\LogicalOp;
use Ausus\Definition\ExpandSpec;
use Ausus\Definition\ExposedField;
use Ausus\Definition\Expression\Comparison;
use Ausus\Definition\Expression\Expression;
use Ausus\Definition\Expression\FactRef;
use Ausus\Definition\Expression\Literal;
use Ausus\Definition\Expression\Logical;
use Ausus\Definition\FieldDefinition;
use Ausus\Definition\ProjectionDefinition;
use Ausus\Definition\TransitionSpec;
use RuntimeException;

/**
 * IMPLEMENTATION-001 Phase 6 — content-addressed schema store on disk
 * (RFC-CLI-001 §Q4/Q5/Q7):
 *
 *   <root>/schemas/<hash>.json   one compiled EntitySchema, addressed by hash
 *   <root>/index.json            { EntityId: hash } — nothing else
 *
 * Persists the EntitySchema exactly as it leaves the Compiler: no semantic
 * transformation, no hash recomputation, no recompilation. Re-storing an
 * existing hash leaves its file (and timestamp) untouched (Q5).
 */
final class FileSchemaRepository implements SchemaRepository
{
    public function __construct(private readonly string $root)
    {
    }

    public function putByHash(EntitySchema $schema): void
    {
        $dir = $this->root . '/schemas';
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create schema directory: {$dir}");
        }

        $path = $dir . '/' . $schema->hash . '.json';
        // Q5: identical hash already present ⇒ no rewrite (timestamp preserved).
        if (!is_file($path)) {
            $json = json_encode($this->encode($schema), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->atomicWrite($path, (string) $json);
        }

        $index = $this->readIndex();
        if (($index[$schema->identity] ?? null) !== $schema->hash) {
            $index[$schema->identity] = $schema->hash;
            ksort($index);
            $this->atomicWrite($this->root . '/index.json', (string) json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    public function getByHash(string $hash): EntitySchema
    {
        $path = $this->root . '/schemas/' . $hash . '.json';
        if (!is_file($path)) {
            throw new RuntimeException("no schema file for hash '{$hash}'");
        }
        /** @var array<string,mixed> $data */
        $data = json_decode((string) file_get_contents($path), true);

        return $this->decode($data);
    }

    public function resolve(string $entityId): EntitySchema
    {
        $index = $this->readIndex();
        $hash = $index[$entityId] ?? throw new RuntimeException("no schema for entity '{$entityId}'");

        return $this->getByHash($hash);
    }

    // ── index ────────────────────────────────────────────────────────────────

    /** @return array<string,string> */
    private function readIndex(): array
    {
        $path = $this->root . '/index.json';
        if (!is_file($path)) {
            return [];
        }
        /** @var array<string,string> $index */
        $index = json_decode((string) file_get_contents($path), true) ?: [];

        return $index;
    }

    private function atomicWrite(string $path, string $bytes): void
    {
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $bytes) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("cannot write: {$path}");
        }
    }

    // ── faithful (de)serialization of EntitySchema ───────────────────────────

    /** @return array<string,mixed> */
    private function encode(EntitySchema $s): array
    {
        return [
            'version' => [
                'schemaVersion' => $s->version->schemaVersion,
                'kernelVersion' => $s->version->kernelVersion,
                'engineVersion' => $s->version->engineVersion,
            ],
            'hash' => $s->hash,
            'identity' => $s->identity,
            'tenantScoped' => $s->tenantScoped,
            'fields' => array_map($this->encodeField(...), $s->fields),
            'actions' => array_map($this->encodeAction(...), $s->actions),
            'projections' => array_map($this->encodeProjection(...), $s->projections),
        ];
    }

    /** @param array<string,mixed> $d */
    private function decode(array $d): EntitySchema
    {
        /** @var array{schemaVersion:string,kernelVersion:string,engineVersion:string} $v */
        $v = $d['version'];

        return new EntitySchema(
            new SchemaVersion($v['schemaVersion'], $v['kernelVersion'], $v['engineVersion']),
            $d['hash'],
            $d['identity'],
            $d['tenantScoped'],
            array_map($this->decodeField(...), $d['fields']),
            array_map($this->decodeAction(...), $d['actions']),
            array_map($this->decodeProjection(...), $d['projections']),
        );
    }

    /** @return array<string,mixed> */
    private function encodeField(FieldDefinition $f): array
    {
        return [
            'name' => $f->name,
            'type' => $f->type->value,
            'nullable' => $f->nullable,
            'default' => $f->default,
            'writeProtected' => $f->writeProtected,
            'typeOptions' => $f->typeOptions,
        ];
    }

    /** @param array<string,mixed> $d */
    private function decodeField(array $d): FieldDefinition
    {
        return new FieldDefinition(
            $d['name'],
            FieldType::from($d['type']),
            $d['nullable'],
            $d['default'],
            $d['writeProtected'],
            $d['typeOptions'],
        );
    }

    /** @return array<string,mixed> */
    private function encodeAction(ActionDefinition $a): array
    {
        return [
            'name' => $a->name,
            'kind' => $a->kind->value,
            'inputs' => $a->inputs,
            'guard' => $a->guard !== null ? $this->encodeExpr($a->guard) : null,
            'transition' => $a->transition !== null
                ? ['field' => $a->transition->field, 'from' => $a->transition->from, 'to' => $a->transition->to]
                : null,
        ];
    }

    /** @param array<string,mixed> $d */
    private function decodeAction(array $d): ActionDefinition
    {
        $transition = null;
        if ($d['transition'] !== null) {
            $t = $d['transition'];
            $transition = new TransitionSpec($t['field'], $t['from'], $t['to']);
        }

        return new ActionDefinition(
            $d['name'],
            ActionKind::from($d['kind']),
            $d['inputs'],
            $d['guard'] !== null ? $this->decodeExpr($d['guard']) : null,
            $transition,
        );
    }

    /** @return array<string,mixed> */
    private function encodeProjection(ProjectionDefinition $p): array
    {
        return [
            'name' => $p->name,
            'fields' => array_map(
                fn (ExposedField $e): array => [
                    'field' => $e->field,
                    'visibility' => $e->visibility !== null ? $this->encodeExpr($e->visibility) : null,
                ],
                $p->fields,
            ),
            'expand' => array_map(
                fn (ExpandSpec $e): array => ['via' => $e->via, 'projection' => $e->projection],
                $p->expand,
            ),
        ];
    }

    /** @param array<string,mixed> $d */
    private function decodeProjection(array $d): ProjectionDefinition
    {
        $fields = array_map(
            fn (array $e): ExposedField => new ExposedField(
                $e['field'],
                $e['visibility'] !== null ? $this->decodeExpr($e['visibility']) : null,
            ),
            $d['fields'],
        );
        $expand = array_map(
            fn (array $e): ExpandSpec => new ExpandSpec($e['via'], $e['projection']),
            $d['expand'],
        );

        return new ProjectionDefinition($d['name'], $fields, $expand);
    }

    /** @return array<string,mixed> */
    private function encodeExpr(Expression $e): array
    {
        if ($e instanceof Comparison) {
            return [
                'node' => 'comparison',
                'op' => $e->op->value,
                'left' => $this->encodeOperand($e->left),
                'right' => $this->encodeOperand($e->right),
            ];
        }

        /** @var Logical $e */
        return [
            'node' => 'logical',
            'op' => $e->op->value,
            'operands' => array_map($this->encodeExpr(...), $e->operands),
        ];
    }

    /** @param array<string,mixed> $d */
    private function decodeExpr(array $d): Expression
    {
        if ($d['node'] === 'comparison') {
            return new Comparison(
                Comparator::from($d['op']),
                $this->decodeOperand($d['left']),
                $this->decodeOperand($d['right']),
            );
        }

        return new Logical(
            LogicalOp::from($d['op']),
            array_map($this->decodeExpr(...), $d['operands']),
        );
    }

    /** @return array<string,mixed> */
    private function encodeOperand(FactRef|Literal $o): array
    {
        return $o instanceof FactRef
            ? ['node' => 'factref', 'source' => $o->source->value, 'path' => $o->path]
            : ['node' => 'literal', 'value' => $o->value];
    }

    /** @param array<string,mixed> $d */
    private function decodeOperand(array $d): FactRef|Literal
    {
        return $d['node'] === 'factref'
            ? new FactRef(FactSource::from($d['source']), $d['path'])
            : new Literal($d['value']);
    }
}

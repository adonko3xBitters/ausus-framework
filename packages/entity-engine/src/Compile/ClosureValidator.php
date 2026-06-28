<?php
declare(strict_types=1);

namespace Ausus\Engine\Compile;

use Ausus\Definition\ActionDefinition;
use Ausus\Definition\EntityDefinition;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\Comparator;
use Ausus\Definition\Enum\FactSource;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\Enum\LogicalOp;
use Ausus\Definition\Expression\Comparison;
use Ausus\Definition\Expression\Expression;
use Ausus\Definition\Expression\FactRef;
use Ausus\Definition\Expression\Literal;
use Ausus\Definition\Expression\Logical;
use Ausus\Definition\FieldDefinition;
use Ausus\Definition\ProjectionDefinition;

/**
 * IMPLEMENTATION-001 Phase 4 — the 16 RFC-012 §Q6 closure invariants.
 *
 * `validate()` is atomic: it throws {@see CompilationError} on the first
 * violation and returns void on success. No disk, no schema, no runtime.
 *
 * Invariants:
 *   A. References   1 reference target exists · 2 enum coherent · 3 FieldRefs resolve
 *   B. Actions      4 transition valid · 5 writeProtected respected ·
 *                   6 kind/transition coherent · 7 input ≠ identity
 *   C. Expressions  8 FactRefs resolve · 9 operator/type soundness · 10 well-formed
 *   D. Projections  11 exposed fields resolve · 12 expand valid · 13 visibility valid
 *   E. Cycles       14 expand depth ≤ 1 · 15 reference cycles allowed (no rejection)
 *   F. Identity     16 unique names
 */
final class ClosureValidator
{
    /** @param list<EntityDefinition> $definitions */
    public function validate(array $definitions): void
    {
        // [16] unique EntityId + build registry; per-entity name uniqueness.
        /** @var array<string,EntityDefinition> $byId */
        $byId = [];
        foreach ($definitions as $def) {
            if (isset($byId[$def->identity])) {
                throw new CompilationError("[16] duplicate EntityId '{$def->identity}'");
            }
            $byId[$def->identity] = $def;
        }

        foreach ($definitions as $def) {
            $fields = $this->fieldsByName($def);
            $this->validateFields($def, $byId);
            $this->validateActions($def, $fields);
            $this->validateProjections($def, $fields, $byId);
        }
    }

    // ── registry ─────────────────────────────────────────────────────────────

    /** @return array<string,FieldDefinition> */
    private function fieldsByName(EntityDefinition $def): array
    {
        $map = [];
        foreach ($def->fields as $f) {
            if (isset($map[$f->name])) {
                throw new CompilationError("[16] duplicate field '{$f->name}' in entity '{$def->identity}'");
            }
            $map[$f->name] = $f;
        }
        $seenAction = [];
        foreach ($def->actions as $a) {
            if (isset($seenAction[$a->name])) {
                throw new CompilationError("[16] duplicate action '{$a->name}' in entity '{$def->identity}'");
            }
            $seenAction[$a->name] = true;
        }
        $seenProj = [];
        foreach ($def->projections as $p) {
            if (isset($seenProj[$p->name])) {
                throw new CompilationError("[16] duplicate projection '{$p->name}' in entity '{$def->identity}'");
            }
            $seenProj[$p->name] = true;
        }

        return $map;
    }

    // ── A. references ────────────────────────────────────────────────────────

    /** @param array<string,EntityDefinition> $byId */
    private function validateFields(EntityDefinition $def, array $byId): void
    {
        foreach ($def->fields as $f) {
            if ($f->type === FieldType::Reference) {
                // [1] reference target exists
                $target = $f->typeOptions['target'] ?? null;
                if (!is_string($target) || $target === '') {
                    throw new CompilationError("[1] field '{$f->name}' (entity '{$def->identity}') is a reference without a 'target'");
                }
                if (!isset($byId[$target])) {
                    throw new CompilationError("[1] field '{$f->name}' (entity '{$def->identity}') references unknown entity '{$target}'");
                }
            }
            if ($f->type === FieldType::Enum) {
                // [2] enum coherent
                $values = $f->typeOptions['values'] ?? null;
                if (!is_array($values) || $values === []) {
                    throw new CompilationError("[2] enum field '{$f->name}' (entity '{$def->identity}') has no values");
                }
                foreach ($values as $v) {
                    if (!is_string($v)) {
                        throw new CompilationError("[2] enum field '{$f->name}' (entity '{$def->identity}') has a non-string value");
                    }
                }
                if ($f->default !== null && !in_array($f->default, $values, true)) {
                    throw new CompilationError("[2] enum field '{$f->name}' (entity '{$def->identity}') default '{$f->default}' is not a member");
                }
            }
        }
    }

    // ── B. actions ───────────────────────────────────────────────────────────

    /** @param array<string,FieldDefinition> $fields */
    private function validateActions(EntityDefinition $def, array $fields): void
    {
        foreach ($def->actions as $a) {
            // [3] inputs resolve
            foreach ($a->inputs as $in) {
                if (!isset($fields[$in])) {
                    throw new CompilationError("[3] action '{$a->name}' (entity '{$def->identity}') input '{$in}' resolves to no field");
                }
                // [7] input must not target an identity field
                if ($fields[$in]->type === FieldType::Identity) {
                    throw new CompilationError("[7] action '{$a->name}' (entity '{$def->identity}') input '{$in}' targets the identity field");
                }
            }

            // [6] kind/transition coherence
            if ($a->kind === ActionKind::Transition) {
                if ($a->transition === null) {
                    throw new CompilationError("[6] transition action '{$a->name}' (entity '{$def->identity}') has no transition spec");
                }
            } elseif ($a->transition !== null) {
                throw new CompilationError("[6] {$a->kind->value} action '{$a->name}' (entity '{$def->identity}') must not carry a transition spec");
            }

            // [5] writeProtected respected (create/update inputs)
            if ($a->kind === ActionKind::Create || $a->kind === ActionKind::Update) {
                foreach ($a->inputs as $in) {
                    if ($fields[$in]->writeProtected) {
                        throw new CompilationError("[5] {$a->kind->value} action '{$a->name}' (entity '{$def->identity}') writes write-protected field '{$in}'");
                    }
                }
            }

            // [4] transition valid
            if ($a->transition !== null) {
                $t = $a->transition;
                $stateField = $fields[$t->field] ?? null;
                if ($stateField === null || $stateField->type !== FieldType::Enum) {
                    throw new CompilationError("[4] transition action '{$a->name}' (entity '{$def->identity}') field '{$t->field}' is not an enum field");
                }
                /** @var list<string> $values */
                $values = $stateField->typeOptions['values'] ?? [];
                if ($t->from === []) {
                    throw new CompilationError("[4] transition action '{$a->name}' (entity '{$def->identity}') has empty 'from'");
                }
                foreach ($t->from as $from) {
                    if (!in_array($from, $values, true)) {
                        throw new CompilationError("[4] transition action '{$a->name}' (entity '{$def->identity}') from-state '{$from}' is not an enum member");
                    }
                    if ($from === $t->to) {
                        throw new CompilationError("[4] transition action '{$a->name}' (entity '{$def->identity}') from === to ('{$from}')");
                    }
                }
                if (!in_array($t->to, $values, true)) {
                    throw new CompilationError("[4] transition action '{$a->name}' (entity '{$def->identity}') to-state '{$t->to}' is not an enum member");
                }
            }

            // [8][9][10] guard expression
            if ($a->guard !== null) {
                $this->validateExpression($a->guard, $def, $fields, $a->inputs, false, "action '{$a->name}'");
            }
        }
    }

    // ── D. projections ───────────────────────────────────────────────────────

    /**
     * @param array<string,FieldDefinition> $fields
     * @param array<string,EntityDefinition> $byId
     */
    private function validateProjections(EntityDefinition $def, array $fields, array $byId): void
    {
        foreach ($def->projections as $p) {
            foreach ($p->fields as $ef) {
                // [11] exposed fields resolve (to owner fields)
                if (!isset($fields[$ef->field])) {
                    throw new CompilationError("[11] projection '{$p->name}' (entity '{$def->identity}') exposes unknown field '{$ef->field}'");
                }
                // [13] visibility valid (read context — no input source)
                if ($ef->visibility !== null) {
                    $this->validateExpression($ef->visibility, $def, $fields, [], true, "projection '{$p->name}'");
                }
            }

            foreach ($p->expand as $ex) {
                // [12] expand valid: via is a reference field; target projection exists
                $viaField = $fields[$ex->via] ?? null;
                if ($viaField === null || $viaField->type !== FieldType::Reference) {
                    throw new CompilationError("[12] projection '{$p->name}' (entity '{$def->identity}') expand 'via' '{$ex->via}' is not a reference field");
                }
                /** @var string $target */
                $target = $viaField->typeOptions['target'] ?? '';
                $targetEntity = $byId[$target] ?? null;
                if ($targetEntity === null) {
                    throw new CompilationError("[12] projection '{$p->name}' (entity '{$def->identity}') expand target entity '{$target}' is unknown");
                }
                $targetProjection = $this->projection($targetEntity, $ex->projection);
                if ($targetProjection === null) {
                    throw new CompilationError("[12] projection '{$p->name}' (entity '{$def->identity}') expand references unknown projection '{$ex->projection}' on '{$target}'");
                }
                // [14] expand depth ≤ 1: the target projection must not itself expand
                if ($targetProjection->expand !== []) {
                    throw new CompilationError("[14] projection '{$p->name}' (entity '{$def->identity}') expands into '{$target}.{$ex->projection}' which itself expands (depth > 1)");
                }
            }
        }
        // [15] reference cycles among entities are allowed — intentionally not rejected.
    }

    private function projection(EntityDefinition $entity, string $name): ?ProjectionDefinition
    {
        foreach ($entity->projections as $p) {
            if ($p->name === $name) {
                return $p;
            }
        }

        return null;
    }

    // ── C. expressions ───────────────────────────────────────────────────────

    /**
     * @param array<string,FieldDefinition> $fields owner-entity fields (subject scope)
     * @param list<string> $inputs action inputs (for source=Input); empty in visibility
     */
    private function validateExpression(
        Expression $e,
        EntityDefinition $def,
        array $fields,
        array $inputs,
        bool $isVisibility,
        string $where,
    ): void {
        if ($e instanceof Comparison) {
            // [8] FactRefs resolve
            $this->resolveOperand($e->left, $def, $fields, $inputs, $isVisibility, $where);
            $this->resolveOperand($e->right, $def, $fields, $inputs, $isVisibility, $where);

            // [9] operator/type soundness — ordered comparators need ordered operands
            if (in_array($e->op, [Comparator::Lt, Comparator::Lte, Comparator::Gt, Comparator::Gte], true)) {
                foreach ([$e->left, $e->right] as $op) {
                    if ($this->isKnownNonOrdered($op, $fields)) {
                        throw new CompilationError("[9] {$where} (entity '{$def->identity}') applies '{$e->op->value}' to a non-ordered operand");
                    }
                }
            }

            return;
        }

        /** @var Logical $e */
        // [10] well-formed
        $count = count($e->operands);
        if ($e->op === LogicalOp::Not && $count !== 1) {
            throw new CompilationError("[10] {$where} (entity '{$def->identity}') 'not' must have exactly one operand, has {$count}");
        }
        if (($e->op === LogicalOp::And || $e->op === LogicalOp::Or) && $count < 1) {
            throw new CompilationError("[10] {$where} (entity '{$def->identity}') '{$e->op->value}' must have at least one operand");
        }
        foreach ($e->operands as $child) {
            $this->validateExpression($child, $def, $fields, $inputs, $isVisibility, $where);
        }
    }

    /**
     * [8] Resolve a single operand's FactRef against the available facts.
     *
     * @param array<string,FieldDefinition> $fields
     * @param list<string> $inputs
     */
    private function resolveOperand(
        FactRef|Literal $op,
        EntityDefinition $def,
        array $fields,
        array $inputs,
        bool $isVisibility,
        string $where,
    ): void {
        if (!$op instanceof FactRef) {
            return; // literals always resolve
        }
        switch ($op->source) {
            case FactSource::Subject:
                if (!isset($fields[$op->path])) {
                    throw new CompilationError("[8] {$where} (entity '{$def->identity}') subject fact 'subject.{$op->path}' resolves to no field");
                }
                break;
            case FactSource::Input:
                if ($isVisibility) {
                    throw new CompilationError("[8] {$where} (entity '{$def->identity}') references 'input.{$op->path}' in a read (no inputs in a projection)");
                }
                if (!in_array($op->path, $inputs, true)) {
                    throw new CompilationError("[8] {$where} (entity '{$def->identity}') input fact 'input.{$op->path}' is not a declared input");
                }
                break;
            case FactSource::Actor:
            case FactSource::Tenant:
            case FactSource::Now:
                // Context attributes — no declared schema in v0.1; path is accepted.
                break;
        }
    }

    /**
     * True only when an operand is PROVABLY non-ordered (literal bool/string/null,
     * or a subject/input fact whose field type is not Integer/Decimal/Date).
     * Actor/Tenant/Now facts are of unknown type and are NOT rejected.
     *
     * @param array<string,FieldDefinition> $fields
     */
    private function isKnownNonOrdered(FactRef|Literal $op, array $fields): bool
    {
        if ($op instanceof Literal) {
            return !is_int($op->value) && !is_float($op->value);
        }
        if ($op->source === FactSource::Subject || $op->source === FactSource::Input) {
            $field = $fields[$op->path] ?? null;
            if ($field === null) {
                return false; // unresolved → already an [8] error elsewhere
            }

            return !$this->isOrderedType($field->type);
        }

        return false; // actor/tenant/now — unknown, allow
    }

    private function isOrderedType(FieldType $t): bool
    {
        return $t === FieldType::Integer || $t === FieldType::Decimal || $t === FieldType::Date;
    }
}

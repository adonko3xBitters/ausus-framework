<?php
declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

/**
 * IMPLEMENTATION-001 Phase 0 — kernel DTO + contract surface.
 *
 * Scope: ONLY that the RFC-012 definition DTOs, the compiled artefacts, the
 * enums, and the RFC-011 contracts exist, construct, are immutable, and wire to
 * each other (zero behaviour — no compile, no bind, no evaluation here).
 */

use Ausus\Definition\EntityDefinition;
use Ausus\Definition\FieldDefinition;
use Ausus\Definition\ActionDefinition;
use Ausus\Definition\TransitionSpec;
use Ausus\Definition\ProjectionDefinition;
use Ausus\Definition\ExposedField;
use Ausus\Definition\ExpandSpec;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\Enum\ActionKind;
use Ausus\Definition\Enum\Comparator;
use Ausus\Definition\Enum\LogicalOp;
use Ausus\Definition\Enum\FactSource;
use Ausus\Definition\Expression\Expression;
use Ausus\Definition\Expression\Comparison;
use Ausus\Definition\Expression\Logical;
use Ausus\Definition\Expression\FactRef;
use Ausus\Definition\Expression\Literal;
use Ausus\Compiled\EntitySchema;
use Ausus\Compiled\SchemaVersion;
use Ausus\Compiled\SchemaIndex;
use Ausus\Contracts\EntityEngine;
use Ausus\Contracts\RuntimeEntity;
use Ausus\Contracts\AuthorizationEvaluator;
use Ausus\Contracts\SchemaRepository;
use Ausus\Contracts\Context;

$pass = 0;
$fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; }
    else       { $fail++; echo "  ✗ {$label}\n"; }
};

echo "── Expression sub-language ─────────────────────────────────\n";
// guard: actor.limit >= input.amount  (sugar Gte; normalized later, carried verbatim now)
$guard = new Comparison(
    Comparator::Gte,
    new FactRef(FactSource::Actor, 'limit'),
    new FactRef(FactSource::Input, 'amount'),
);
$ok('Comparison implements Expression', $guard instanceof Expression);
$ok('Comparator carried verbatim (sugar)', $guard->op === Comparator::Gte);
$ok('FactRef source/path', $guard->left instanceof FactRef && $guard->left->source === FactSource::Actor && $guard->left->path === 'limit');

$vis = new Logical(LogicalOp::Not, [
    new Comparison(Comparator::Eq, new FactRef(FactSource::Subject, 'secret'), new Literal(true)),
]);
$ok('Logical implements Expression', $vis instanceof Expression);
$ok('Logical Not carries one operand', $vis->op === LogicalOp::Not && count($vis->operands) === 1);
$ok('Literal scalar carried', (new Literal(42))->value === 42);

echo "── FieldDefinition / typeOptions ───────────────────────────\n";
$amount = new FieldDefinition('amount', FieldType::Decimal, false);
$status = new FieldDefinition('status', FieldType::Enum, false, 'draft', true, ['values' => ['draft', 'approved']]);
$buyer  = new FieldDefinition('buyer', FieldType::Reference, true, null, false, ['target' => 'customer']);
$ok('required-only field', $amount->name === 'amount' && $amount->type === FieldType::Decimal && $amount->nullable === false);
$ok('enum via typeOptions', $status->typeOptions['values'] === ['draft', 'approved'] && $status->default === 'draft');
$ok('writeProtected flag', $status->writeProtected === true && $amount->writeProtected === false);
$ok('reference target via typeOptions', $buyer->typeOptions['target'] === 'customer');

echo "── ActionDefinition + TransitionSpec ───────────────────────\n";
$create = new ActionDefinition('create', ActionKind::Create, ['amount', 'buyer']);
$approve = new ActionDefinition(
    'approve',
    ActionKind::Transition,
    [],
    $guard,
    new TransitionSpec('status', ['draft'], 'approved'),
);
$ok('create kind + inputs', $create->kind === ActionKind::Create && $create->inputs === ['amount', 'buyer']);
$ok('create has no guard/transition', $create->guard === null && $create->transition === null);
$ok('transition spec from/to', $approve->transition instanceof TransitionSpec && $approve->transition->from === ['draft'] && $approve->transition->to === 'approved');
$ok('guard embedded on action', $approve->guard === $guard);

echo "── ProjectionDefinition + ExposedField + ExpandSpec ────────\n";
$board = new ProjectionDefinition(
    'board',
    [new ExposedField('amount'), new ExposedField('secret', $vis)],
    [new ExpandSpec('buyer', 'customer_card')],
);
$ok('exposed fields', count($board->fields) === 2 && $board->fields[0]->field === 'amount');
$ok('visibility embedded', $board->fields[1]->visibility === $vis);
$ok('expand single-hop spec', $board->expand[0]->via === 'buyer' && $board->expand[0]->projection === 'customer_card');

echo "── EntityDefinition composition ────────────────────────────\n";
$invoice = new EntityDefinition('invoice', true, [$amount, $status, $buyer], [$create, $approve], [$board]);
$ok('identity + tenantScoped', $invoice->identity === 'invoice' && $invoice->tenantScoped === true);
$ok('composes field/action/projection lists', count($invoice->fields) === 3 && count($invoice->actions) === 2 && count($invoice->projections) === 1);

echo "── Compiled artefacts ──────────────────────────────────────\n";
$ver = new SchemaVersion('0.1', '1.0', '0.1');
$schema = new EntitySchema($ver, 'deadbeef', 'invoice', true, [$amount], [$create], [$board]);
$ok('EntitySchema carries version + hash', $schema->version === $ver && $schema->hash === 'deadbeef' && $schema->identity === 'invoice');
$index = new SchemaIndex(['invoice' => 'deadbeef']);
$ok('SchemaIndex hashFor hit', $index->hashFor('invoice') === 'deadbeef');
$ok('SchemaIndex hashFor miss → null', $index->hashFor('absent') === null);

echo "── Immutability (readonly) ─────────────────────────────────\n";
$threw = false;
try { /** @phpstan-ignore-next-line */ $amount->name = 'mutated'; }
catch (\Error $e) { $threw = true; }
$ok('FieldDefinition is readonly (write throws)', $threw);

echo "── Contracts present (interfaces, bind-only engine) ────────\n";
$ok('EntityEngine is an interface', interface_exists(EntityEngine::class));
$ok('EntityEngine exposes bind only', method_exists(EntityEngine::class, 'bind') && !method_exists(EntityEngine::class, 'compile'));
$ok('RuntimeEntity is an interface', interface_exists(RuntimeEntity::class));
$ok('RuntimeEntity has invoke + read', method_exists(RuntimeEntity::class, 'invoke') && method_exists(RuntimeEntity::class, 'read'));
$ok('AuthorizationEvaluator is an interface', interface_exists(AuthorizationEvaluator::class));
$ok('SchemaRepository is an interface', interface_exists(SchemaRepository::class));
$ok('Context is an interface', interface_exists(Context::class));

echo "\n";
echo $fail === 0
    ? "PHASE 0 OK — {$pass} checks passed\n"
    : "PHASE 0 FAIL — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

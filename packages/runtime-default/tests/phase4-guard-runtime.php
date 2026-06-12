<?php
declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Ausus\{Fact, Cond, Provenance, FactRef, Decision, Context, Tenant, TenantId, Instant,
    StubActor, ActorRef, Reference, Repository, PersistenceContext,
    Application, DslPlugin, Dsl, Field, Action, PolicyDenied};
use Ausus\Runtime\{ImmutableFactSet, CondEvaluator, CondGuard, FactResolver, GuardComposer};

$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "  ✓ {$label}\n"; } else { $fail++; echo "  ✗ {$label}\n"; }
};

echo "── 1. CondEvaluator (all 11 ops) ───────────────────────────\n";
$fs = new ImmutableFactSet([
    new Fact(Provenance::SubjectField, 'claim_amount', 5000),
    new Fact(Provenance::Actor, 'authority_limit', 10000),
]);
$S = Fact::subject('claim_amount'); $A = Fact::actor('authority_limit');
$ok('eq',  CondEvaluator::eval(Cond::eq($S, 5000), $fs) === true);
$ok('ne',  CondEvaluator::eval(Cond::ne($S, 1), $fs) === true);
$ok('lt',  CondEvaluator::eval(Cond::lt($S, $A), $fs) === true);
$ok('lte', CondEvaluator::eval(Cond::lte($S, $A), $fs) === true);
$ok('gt',  CondEvaluator::eval(Cond::gt($A, $S), $fs) === true);
$ok('gte', CondEvaluator::eval(Cond::gte($A, $S), $fs) === true);
$ok('in',  CondEvaluator::eval(Cond::in($S, [1, 5000, 9]), $fs) === true);
$ok('in (miss)', CondEvaluator::eval(Cond::in($S, [1, 2]), $fs) === false);
$ok('and (T,T)', CondEvaluator::eval(Cond::and(Cond::lt($S, $A), Cond::eq($S, 5000)), $fs) === true);
$ok('and (T,F)', CondEvaluator::eval(Cond::and(Cond::lt($S, $A), Cond::eq($S, 1)), $fs) === false);
$ok('or  (F,T)', CondEvaluator::eval(Cond::or(Cond::eq($S, 1), Cond::eq($S, 5000)), $fs) === true);
$ok('not (F)',   CondEvaluator::eval(Cond::not(Cond::eq($S, 1)), $fs) === true);
$ok('mul (5000 ≤ 10000*2)', CondEvaluator::eval(Cond::lte($S, Cond::mul($A, 2)), $fs) === true);
$ok('mul (5000 > 10000*2 is false)', CondEvaluator::eval(Cond::gt($S, Cond::mul($A, 2)), $fs) === false);

echo "── 2. FactResolver (Actor / Input / Context / SubjectIdentity) ──\n";
$resolver = new FactResolver();
$ctx = new Context(new Tenant(new TenantId('acme')), 'corr', null, new Instant(1_700_000_000.0));
$actor = new StubActor(new ActorRef('user', 'maya', 'acme'), ['claims.adjuster'], [], ['authority_limit' => 10000]);
$noLoad = new class implements PersistenceContext {
    public function repository(string $entityFqn): Repository { throw new \RuntimeException('must not load subject'); }
    public function tenant(): Tenant { return new Tenant(new TenantId('acme')); }
};
$subject = new Reference('acme', 'claims.claim', 'c1');
$rs = $resolver->resolve(
    [Fact::actor('authority_limit'), Fact::actor('id'), Fact::input('amount'),
     new FactRef(Provenance::Context, 'tenant'), new FactRef(Provenance::SubjectIdentity, 'id')],
    $actor, $subject, ['amount' => 7], $ctx, $noLoad
);
$ok('Actor attribute  → 10000', $rs->get(Provenance::Actor, 'authority_limit') === 10000);
$ok('Actor id         → maya',  $rs->get(Provenance::Actor, 'id') === 'maya');
$ok('OperationInput   → 7',     $rs->get(Provenance::OperationInput, 'amount') === 7);
$ok('Context tenant   → acme',  $rs->get(Provenance::Context, 'tenant') === 'acme');
$ok('SubjectIdentity id→ c1',   $rs->get(Provenance::SubjectIdentity, 'id') === 'c1');
$ok('unresolved fact  → null',  $rs->get(Provenance::Actor, 'nope') === null);

echo "── 3. GuardComposer (deny-overrides, abstain-neutral) ──────\n";
$fs2 = new ImmutableFactSet([new Fact(Provenance::Actor, 'x', 5)]);
$permit = new CondGuard(Cond::eq(Fact::actor('x'), 5));    // true  → Permit
$deny   = new CondGuard(Cond::eq(Fact::actor('x'), 99));   // false → Deny
$gc = new GuardComposer();
$ok('Permit + Permit → Permit', $gc->compose([$permit, $permit], $fs2) === Decision::Permit);
$ok('Permit + Deny   → Deny',   $gc->compose([$permit, $deny],   $fs2) === Decision::Deny);
$ok('Deny + Permit   → Deny',   $gc->compose([$deny,   $permit], $fs2) === Decision::Deny);

echo "── 4. E2E Claims (real Invoker, in-transaction guard) ──────\n";
$plugin = new class extends DslPlugin {
    public function name(): string { return 'claims'; }
    public function phpNamespace(): string { return 'Test'; }
    public function dsl(Dsl $dsl): void {
        // Phase 4 is scalar-only: use an integer amount (Field::money() is a
        // structured {amount,currency} value, which is out of scalar scope and
        // correctly resolves to null under the fail-safe FactResolver).
        $dsl->actorAttributes(['authority_limit' => Field::integer()]);
        $dsl->entity('claim')
            ->fields([
                'claim_amount' => Field::integer(),
                'status'       => Field::enum('FILED', 'APPROVED')->default('FILED'),
            ])
            ->workflow(field: 'status', initial: 'FILED')
            ->actions([
                'file'    => Action::create('claim_amount')->requireRole('claims.adjuster'),
                'approve' => Action::transition('status', from: 'FILED', to: 'APPROVED')
                    ->requireRole('claims.adjuster')
                    ->requireThat(Cond::lte(Fact::subject('claim_amount'), Fact::actor('authority_limit'))),
            ])
            ->projection('detail', fields: ['id', 'claim_amount', 'status'], role: 'claims.adjuster');
    }
};
$app = Application::create([
    'tenant'          => 'acme',
    'roles'           => ['claims.adjuster'],
    'actorAttributes' => ['authority_limit' => 10000],
    'database'        => ':memory:',
])->register($plugin)->boot();

$out = $app->invoke('claims.claim.file', null, ['claim_amount' => 5000]);
$ref = new Reference('acme', 'claims.claim', (string) $out['id']);
$threw = false;
try { $app->invoke('claims.claim.approve', $ref); } catch (PolicyDenied) { $threw = true; }
$ok('claim_amount=5000 ≤ limit=10000 → Permit (approved)', !$threw);

$out2 = $app->invoke('claims.claim.file', null, ['claim_amount' => 50000]);
$ref2 = new Reference('acme', 'claims.claim', (string) $out2['id']);
$threw2 = false;
try { $app->invoke('claims.claim.approve', $ref2); } catch (PolicyDenied) { $threw2 = true; }
$ok('claim_amount=50000 > limit=10000 → PolicyDenied', $threw2);

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed={$pass} failed={$fail}\n";
exit($fail === 0 ? 0 : 1);

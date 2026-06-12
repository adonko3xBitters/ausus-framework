<?php
declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Ausus\{DslPlugin, Dsl, Field, Action, Cond, Fact, Compiler, DanglingFactReference};

/**
 * RFC-018 Phase 3 — DSL requireThat() + actorAttributes() + Compiler closure.
 * STATIC validation only — no runtime guard evaluation is exercised here.
 */
$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail): void {
    $cond ? ($pass++ . print("  ✓ {$label}\n")) : ($fail++ . print("  ✗ {$label}\n"));
};

/** Build a one-off DslPlugin from a definition closure. */
$plugin = static function (callable $define): DslPlugin {
    return new class($define) extends DslPlugin {
        /** @param callable $d */
        public function __construct(private $d) {}
        public function name(): string { return 'claims'; }
        public function phpNamespace(): string { return 'Test'; }
        public function dsl(Dsl $dsl): void { ($this->d)($dsl); }
    };
};

$compiles = static function (DslPlugin $p): ?string {
    try { (new Compiler())->compile([$p]); return null; }
    catch (DanglingFactReference $e) { return $e->getMessage(); }
};

// A reusable entity shape: claims.claim with fields claim_amount + amount + status.
$entity = static function (Dsl $dsl): \Ausus\EntityBuilder {
    return $dsl->entity('claim')
        ->fields([
            'claim_amount' => Field::money(),
            'amount'       => Field::money(),
            'status'       => Field::enum('FILED', 'APPROVED')->default('FILED'),
        ])
        ->workflow(field: 'status', initial: 'FILED');
};

echo "── GREEN cases (must compile) ──────────────────────────────\n";

// 1. Fact::subject + Fact::actor (declared attribute)
$p = $plugin(function (Dsl $dsl) use ($entity) {
    $dsl->actorAttributes(['authority_limit' => Field::money()]);
    $entity($dsl)->actions([
        'approve' => Action::transition('status', from: 'FILED', to: 'APPROVED')
            ->requireRole('claims.adjuster')
            ->requireThat(Cond::lte(Fact::subject('claim_amount'), Fact::actor('authority_limit'))),
    ]);
});
$ok("subject('claim_amount') + actor('authority_limit') compiles", $compiles($p) === null);

// 2. Fact::input on a create action whose input exists
$p = $plugin(function (Dsl $dsl) use ($entity) {
    $entity($dsl)->actions([
        'file' => Action::create('amount', 'claim_amount', 'status')
            ->requireRole('claims.intake')
            ->requireThat(Cond::gt(Fact::input('amount'), 0)),
    ]);
});
$ok("input('amount') (declared create input) compiles", $compiles($p) === null);

// 3. Reserved actor + context + subject-identity keys compile
$p = $plugin(function (Dsl $dsl) use ($entity) {
    $entity($dsl)->actions([
        'approve' => Action::transition('status', from: 'FILED', to: 'APPROVED')
            ->requireThat(Cond::ne(Fact::actor('id'), Fact::subject('status'))),
    ]);
});
$ok("reserved actor('id') + subject field compiles", $compiles($p) === null);

echo "── RED cases (must fail at compile) ────────────────────────\n";

// 4. subject field that does not exist
$p = $plugin(function (Dsl $dsl) use ($entity) {
    $entity($dsl)->actions([
        'approve' => Action::transition('status', from: 'FILED', to: 'APPROVED')
            ->requireThat(Cond::eq(Fact::subject('does_not_exist'), 1)),
    ]);
});
$ok("subject('does_not_exist') → DanglingFactReference", str_contains((string) $compiles($p), 'DanglingFactReference'));

// 5. actor attribute not declared
$p = $plugin(function (Dsl $dsl) use ($entity) {
    $entity($dsl)->actions([
        'approve' => Action::transition('status', from: 'FILED', to: 'APPROVED')
            ->requireThat(Cond::eq(Fact::actor('unknown_attribute'), 1)),
    ]);
});
$ok("actor('unknown_attribute') → DanglingFactReference", str_contains((string) $compiles($p), 'DanglingFactReference'));

// 6. operation input that does not exist (transition has no inputs)
$p = $plugin(function (Dsl $dsl) use ($entity) {
    $entity($dsl)->actions([
        'approve' => Action::transition('status', from: 'FILED', to: 'APPROVED')
            ->requireThat(Cond::eq(Fact::input('missing_input'), 1)),
    ]);
});
$ok("input('missing_input') → DanglingFactReference", str_contains((string) $compiles($p), 'DanglingFactReference'));

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed={$pass} failed={$fail}\n";
exit($fail === 0 ? 0 : 1);

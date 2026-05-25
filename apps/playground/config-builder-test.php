<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Application, ApplicationConfig, ActorRef, StubActor, Tenant, TenantId};
use Acme\Billing\HelloInvoiceDsl;

/**
 * AUSUS — ApplicationConfig fluent typed builder tests.
 *
 *  - `make()` produces a config; setters return new instances (immutability).
 *  - `toArray()` produces the minimal canonical array.
 *  - Each typed setter validates its input.
 *  - `Application::create()` accepts both an ApplicationConfig and the legacy
 *    array form, and the two paths are observationally equivalent.
 *  - Conflicting persistence config is rejected.
 */

$BANNER = "═══ AUSUS — ApplicationConfig builder ══════════════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

function _throws(string $name, callable $fn, string $needle): void {
    $caught = null;
    try { $fn(); } catch (\Throwable $e) { $caught = $e; }
    global $passed, $failed;
    if ($caught instanceof \InvalidArgumentException && str_contains($caught->getMessage(), $needle)) {
        echo "  ✓ {$name}\n"; $passed++;
    } else {
        echo "  ✗ {$name} — expected InvalidArgumentException containing \"{$needle}\", got "
            . ($caught === null ? 'no exception' : get_class($caught) . ': ' . $caught->getMessage()) . "\n";
        $failed++;
    }
}

// ── test 1: make() returns a fresh ApplicationConfig ──────────────────────────
echo "\n── test 1: ApplicationConfig::make() ─────────────────────────\n";
$base = ApplicationConfig::make();
_assert('make() returns ApplicationConfig', $base instanceof ApplicationConfig);
_assert('default tenant is null',           $base->tenant === null);
_assert('default actorId is "app"',         $base->actorId === 'app');
_assert('default roles is empty array',     $base->roles === []);
_assert('default migrate is true',          $base->migrate === true);
_assert('default kernelVersion is 1.0.0',   $base->kernelVersion === '1.0.0');
_assert('default toArray() is empty',       $base->toArray() === []);

// ── test 2: setters return NEW instances (immutability) ───────────────────────
echo "\n── test 2: setters return new instances ──────────────────────\n";
$step1 = $base->tenant('acme');
_assert('tenant() returns a new instance',  $step1 !== $base);
_assert('original config unchanged',        $base->tenant === null);
_assert('new config has the tenant',
        $step1->tenant instanceof Tenant && $step1->tenant->value() === 'acme');

$step2 = $step1->roles(['invoice.creator']);
_assert('roles() returns yet another instance', $step2 !== $step1);
_assert('previous step still has []',           $step1->roles === []);
_assert('new step carries the roles',           $step2->roles === ['invoice.creator']);

// ── test 3: fluent chain produces the canonical array ─────────────────────────
echo "\n── test 3: fluent chain → toArray() ──────────────────────────\n";
$config = ApplicationConfig::make()
    ->tenant('acme')
    ->actor('boot')
    ->roles(['invoice.creator', 'invoice.issuer'])
    ->permissions(['invoice.read'])
    ->sqlite('/tmp/app-builder-test.sqlite')
    ->migrate(true)
    ->kernelVersion('1.0.0');
$arr = $config->toArray();

_assert('toArray.tenant is a Tenant',         $arr['tenant'] instanceof Tenant && $arr['tenant']->value() === 'acme');
_assert('toArray.actorId is "boot"',          ($arr['actorId'] ?? null) === 'boot');
_assert('toArray.roles is exact list',        ($arr['roles'] ?? null) === ['invoice.creator', 'invoice.issuer']);
_assert('toArray.permissions is exact list',  ($arr['permissions'] ?? null) === ['invoice.read']);
_assert('toArray.database is sqlite path',    ($arr['database'] ?? null) === '/tmp/app-builder-test.sqlite');
_assert('toArray skips defaulted "migrate"',  !array_key_exists('migrate', $arr));
_assert('toArray skips defaulted "kernelVersion"', !array_key_exists('kernelVersion', $arr));

// ── test 4: actor() overloading — string vs Actor ─────────────────────────────
echo "\n── test 4: actor() string|Actor overloading ──────────────────\n";
$cfgStr = ApplicationConfig::make()->actor('bob');
_assert('actor(string) sets actorId',  $cfgStr->actorId === 'bob');
_assert('actor(string) leaves actor null', $cfgStr->actor === null);

$customActor = new StubActor(new ActorRef('user', 'svc', 'acme'), ['x']);
$cfgObj = ApplicationConfig::make()->actor($customActor);
_assert('actor(Actor) sets the actor',   $cfgObj->actor === $customActor);
_assert('actor(Actor) leaves actorId default', $cfgObj->actorId === 'app');

// ── test 5: pdo() takes a live connection; conflict with sqlite() ─────────────
echo "\n── test 5: pdo() and conflict detection ──────────────────────\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cfgPdo = ApplicationConfig::make()->pdo($pdo);
_assert('pdo() stores the PDO',                 $cfgPdo->pdo === $pdo);
_assert('toArray.database is the PDO',          $cfgPdo->toArray()['database'] === $pdo);

_throws('sqlite() after pdo() throws',
    fn() => ApplicationConfig::make()->pdo($pdo)->sqlite('/tmp/x.sqlite'),
    'mutually exclusive');

_throws('pdo() after sqlite() throws',
    fn() => ApplicationConfig::make()->sqlite('/tmp/x.sqlite')->pdo($pdo),
    'mutually exclusive');

// ── test 6: per-setter validation ─────────────────────────────────────────────
echo "\n── test 6: validation rejects invalid inputs ─────────────────\n";
_throws('tenant("") throws',           fn() => ApplicationConfig::make()->tenant(''),                  'tenant id must be a non-empty');
_throws('actorId("") throws',          fn() => ApplicationConfig::make()->actorId(''),                 'id must be non-empty');
_throws('sqlite("") throws',           fn() => ApplicationConfig::make()->sqlite(''),                  'path must be non-empty');
_throws('kernelVersion("") throws',    fn() => ApplicationConfig::make()->kernelVersion(''),           'version must be non-empty');
_throws('roles with non-string entry', fn() => ApplicationConfig::make()->roles(['ok', 42]),           'must be a string');
_throws('roles with empty-string',     fn() => ApplicationConfig::make()->roles(['ok', '']),           'must be a non-empty string');
_throws('permissions invalid entry',   fn() => ApplicationConfig::make()->permissions(['ok', null]),   'must be a string');

// ── test 7: Application::create accepts an ApplicationConfig end-to-end ───────
echo "\n── test 7: Application::create(ApplicationConfig) E2E ────────\n";
$app = Application::create(
    ApplicationConfig::make()
        ->tenant('acme')
        ->actorId('boot')
        ->roles(['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'])
)->register(new HelloInvoiceDsl())->boot();

_assert('booted Application',                 $app->isBooted());
_assert('tenant flowed through',              $app->tenant()->value() === 'acme');
_assert('actor id flowed through',            $app->actor()->ref()->id === 'boot');

$created = $app->invoke('billing.invoice.create', null, [
    'number' => 'INV-CFG-001', 'customer_name' => 'Builder Co',
    'amount' => ['amount' => '100.00', 'currency' => 'USD'],
]);
_assert('invoke() works through the builder', ($created['status'] ?? null) === 'DRAFT');

// ── test 8: backward compatibility — array form still works ───────────────────
echo "\n── test 8: backward compat: array form is unchanged ──────────\n";
$appArr = Application::create([
    'tenant' => 'acme',
    'roles'  => ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'],
])->register(new HelloInvoiceDsl())->boot();
$createdArr = $appArr->invoke('billing.invoice.create', null, [
    'number' => 'INV-CFG-002', 'customer_name' => 'Array Co',
    'amount' => ['amount' => '200.00', 'currency' => 'USD'],
]);
_assert('array form still creates a ticket',  ($createdArr['status'] ?? null) === 'DRAFT');

// Equivalence: same fluent chain and same array yield equivalent graphs.
$cfgFluent = ApplicationConfig::make()
    ->tenant('acme')
    ->roles(['invoice.creator']);
$cfgArray  = ['tenant' => new Tenant(new TenantId('acme')), 'roles' => ['invoice.creator']];
_assert('builder toArray equals equivalent array',
        $cfgFluent->toArray() == $cfgArray);

// ── test 9: unknown array key still rejected by Application ───────────────────
echo "\n── test 9: unknown array key still rejected ──────────────────\n";
_throws('Application::create(["tenent"=>"x"]) throws',
    fn() => Application::create(['tenent' => 'acme']),
    'unknown config key');

// ── test 10: empty builder boots an in-memory app ─────────────────────────────
echo "\n── test 10: empty builder + plugin → working app ─────────────\n";
$empty = Application::create(ApplicationConfig::make())
    ->register(new HelloInvoiceDsl());
// No roles configured → policy denial expected on a roled action.
$caught = null;
try { $empty->invoke('billing.invoice.create', null, [
    'number' => 'X', 'customer_name' => 'Y', 'amount' => ['amount' => '1.00', 'currency' => 'USD'],
]); } catch (\Throwable $e) { $caught = $e; }
_assert('empty builder boots',                $empty->isBooted());
_assert('empty roles → PolicyDenied (consistent with array form)',
        $caught instanceof \Ausus\PolicyDenied);

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed={$passed} failed={$failed}\n";
echo "{$BANNER}\n";

exit($failed > 0 ? 1 : 0);

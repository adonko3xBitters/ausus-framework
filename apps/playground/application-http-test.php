<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ausus\{Application, ApplicationConfig};
use Ausus\Api\Http\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Acme\Billing\HelloInvoiceDsl;

/**
 * AUSUS — Application::http() convenience entry point tests.
 *
 *  - One-call request handling (PSR-7 in → PSR-7 out).
 *  - Router instance is built once and reused across calls.
 *  - Auto-detected PSR-17 (nyholm) works when no factory is configured.
 *  - Explicit psr17() / responseFactory() / streamFactory() override autodetect.
 *  - ApplicationConfig::apiPrefix() changes the mount path.
 *  - X-Tenant-ID is required; X-Actor-* headers drive per-request actor
 *    resolution (preserved behavior).
 *  - Projection endpoint returns a valid ViewSchema.
 *  - Action endpoint invokes through the runtime under the action's policy.
 */

$BANNER = "═══ AUSUS — Application::http() ═══════════════════════════";
echo "{$BANNER}\n";

$passed = 0; $failed = 0;
function _assert(string $name, bool $cond, ?string $detail = null): void {
    global $passed, $failed;
    if ($cond) { echo "  ✓ {$name}\n"; $passed++; }
    else        { echo "  ✗ {$name}" . ($detail ? " — {$detail}" : "") . "\n"; $failed++; }
}

$ROLES = ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'];
$factory = new Psr17Factory();

function bodyJson($response): array {
    $body = (string) $response->getBody();
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

// ── test 1: one-call PSR-7 round-trip on /_health ─────────────────────────────
echo "\n── test 1: PSR-7 round-trip on /_health ─────────────────────\n";
$app = Application::create(
        ApplicationConfig::make()->tenant('acme')->roles($ROLES)->psr17($factory)
    )
    ->register(new HelloInvoiceDsl())
    ->boot();

$req  = $factory->createServerRequest('GET', '/api/_health');
$res  = $app->http($req);
$body = bodyJson($res);
_assert('http() returns a ResponseInterface', $res instanceof \Psr\Http\Message\ResponseInterface);
_assert('/_health → 200',                     $res->getStatusCode() === 200);
_assert('/_health graphHash matches',         ($body['graphHash'] ?? null) === $app->graph()->hash);
_assert('/_health Content-Type is JSON',
        str_starts_with($res->getHeaderLine('Content-Type'), 'application/json'));

// ── test 2: Router instance is built once and reused ──────────────────────────
echo "\n── test 2: Router instance is cached across calls ────────────\n";
$reflProp = new ReflectionProperty(Application::class, 'httpRouter');
_assert('httpRouter property exists',         $reflProp->getName() === 'httpRouter');
$first  = $reflProp->getValue($app);
$res2   = $app->http($factory->createServerRequest('GET', '/api/_health'));
$second = $reflProp->getValue($app);
_assert('second http() call did not rebuild', $first === $second && $first instanceof Router);

// ── test 3: PSR-17 auto-detection when no factory is configured ───────────────
echo "\n── test 3: PSR-17 auto-detection (nyholm) ────────────────────\n";
$auto = Application::create(
        ApplicationConfig::make()->tenant('acme')->roles($ROLES)
    )
    ->register(new HelloInvoiceDsl())
    ->boot();
$autoRes = $auto->http($factory->createServerRequest('GET', '/api/_health'));
_assert('http() works with autodetected nyholm', $autoRes->getStatusCode() === 200);

// ── test 4: ApplicationConfig::apiPrefix() changes the mount path ─────────────
echo "\n── test 4: apiPrefix('/v2') mounts on /v2 ────────────────────\n";
$pref = Application::create(
        ApplicationConfig::make()->tenant('acme')->roles($ROLES)->psr17($factory)->apiPrefix('/v2')
    )
    ->register(new HelloInvoiceDsl())
    ->boot();
_assert('/v2/_health → 200',
        $pref->http($factory->createServerRequest('GET', '/v2/_health'))->getStatusCode() === 200);
_assert('/api/_health → 404 (no longer mounted under /api)',
        $pref->http($factory->createServerRequest('GET', '/api/_health'))->getStatusCode() === 404);

// ── test 5: missing X-Tenant-ID returns 400 ───────────────────────────────────
echo "\n── test 5: projection without X-Tenant-ID → 400 ──────────────\n";
$noTenant = $app->http($factory->createServerRequest('GET', '/api/projections/billing.invoice.summary'));
_assert('projection w/o X-Tenant-ID → 400',   $noTenant->getStatusCode() === 400);
$nt = bodyJson($noTenant);
_assert('error.kind == BadRequest',           ($nt['error']['kind'] ?? null) === 'BadRequest');

// ── test 6: projection endpoint returns a ViewSchema ──────────────────────────
echo "\n── test 6: projection endpoint returns ViewSchema ────────────\n";
$app->invoke('billing.invoice.create', null, [
    'number' => 'INV-HTTP-001', 'customer_name' => 'Http Co',
    'amount' => ['amount' => '11.00', 'currency' => 'USD'],
]);
$projReq = $factory->createServerRequest('GET', '/api/projections/billing.invoice.summary')
    ->withHeader('X-Tenant-ID', 'acme');
$projRes = $app->http($projReq);
$schema  = bodyJson($projRes);
_assert('projection → 200',                   $projRes->getStatusCode() === 200);
_assert('schemaVersion 1.2.0',                ($schema['schemaVersion'] ?? null) === '1.2.0');
_assert('data.items is an array',             is_array($schema['data']['items'] ?? null));
_assert('items contain the seeded invoice',   count($schema['data']['items']) >= 1);

// ── test 7: action endpoint dispatches with X-Actor-Roles ─────────────────────
echo "\n── test 7: action endpoint invokes under X-Actor-Roles ───────\n";
// 7a: roleless caller → 403 PolicyDenied (preserved behaviour)
$denied = $app->http(
    $factory->createServerRequest('POST', '/api/actions/billing.invoice.create')
        ->withHeader('X-Tenant-ID', 'acme')
        ->withHeader('X-Actor-Roles', 'nobody')
        ->withHeader('Content-Type', 'application/json')
        ->withBody($factory->createStream(json_encode([
            'subject' => null,
            'inputs'  => ['number' => 'X', 'customer_name' => 'Y', 'amount' => ['amount' => '1.00', 'currency' => 'USD']],
        ])))
);
_assert('actor lacking the role → 403',       $denied->getStatusCode() === 403);
_assert('error.kind == PolicyDenied',         (bodyJson($denied)['error']['kind'] ?? null) === 'PolicyDenied');

// 7b: actor with the right role → 200 and outputs.id present
$ok = $app->http(
    $factory->createServerRequest('POST', '/api/actions/billing.invoice.create')
        ->withHeader('X-Tenant-ID', 'acme')
        ->withHeader('X-Actor-Roles', 'invoice.creator')
        ->withHeader('Content-Type', 'application/json')
        ->withBody($factory->createStream(json_encode([
            'subject' => null,
            'inputs'  => ['number' => 'INV-HTTP-002', 'customer_name' => 'Http Co 2', 'amount' => ['amount' => '12.00', 'currency' => 'USD']],
        ])))
);
$okBody = bodyJson($ok);
_assert('roled caller → 200',                 $ok->getStatusCode() === 200);
_assert('ok=true in payload',                 ($okBody['ok'] ?? null) === true);
_assert('outputs.id is a 26-char ULID',
        isset($okBody['outputs']['id']) && strlen((string) $okBody['outputs']['id']) === 26);

// ── test 8: split responseFactory()/streamFactory() override autodetect ───────
echo "\n── test 8: split response/stream factories override autodetect\n";
$split = Application::create(
        ApplicationConfig::make()
            ->tenant('acme')->roles($ROLES)
            ->responseFactory($factory)
            ->streamFactory($factory)
    )
    ->register(new HelloInvoiceDsl())
    ->boot();
_assert('split-factory app handles /_health', $split->http($factory->createServerRequest('GET', '/api/_health'))->getStatusCode() === 200);

// ── test 9: backward-compatible Router accessor still works ───────────────────
echo "\n── test 9: existing \$app->router(...) still works ────────────\n";
$explicit = $app->router($factory, $factory, '/api');
_assert('router() still returns a Router',    $explicit instanceof Router);
_assert('router() builds a NEW instance (stateless)', $explicit !== $reflProp->getValue($app));

// ── test 10: invalid apiPrefix is rejected at config time ─────────────────────
echo "\n── test 10: invalid apiPrefix rejected ───────────────────────\n";
$caught = null;
try { ApplicationConfig::make()->apiPrefix('api'); }
catch (\Throwable $e) { $caught = $e; }
_assert('apiPrefix("api") throws (must start with /)',
        $caught instanceof \InvalidArgumentException
        && str_contains($caught->getMessage(), "starting with '/'"));

$caught = null;
try { ApplicationConfig::make()->apiPrefix('/api/'); }
catch (\Throwable $e) { $caught = $e; }
_assert('apiPrefix with trailing / throws',
        $caught instanceof \InvalidArgumentException
        && str_contains($caught->getMessage(), "must not end with '/'"));

// ── test 11: resolveActor — no X-Actor-Roles header means no roles ───────────
// The Router used to substitute a HelloInvoice-specific role set
// (['invoice.creator', ...]) when the X-Actor-Roles header was missing;
// non-invoice domains would see confusing PolicyDenied messages naming roles
// they never declared. The fallback was removed: a missing header now yields
// a roleless actor, and every action that declares ->requireRole(...) returns
// 403 PolicyDenied. Public routes are unaffected.
echo "\n── test 11: missing X-Actor-Roles → roleless actor (403) ─────\n";

// 11a — public health probe works without any actor headers
$publicRes = $app->http($factory->createServerRequest('GET', '/api/_health'));
_assert('public /_health works without X-Tenant-ID or X-Actor-Roles',
        $publicRes->getStatusCode() === 200);

// 11b — POST without X-Actor-Roles is denied (was 200 pre-fix via the hardcoded fallback)
$noRoleReq = $factory->createServerRequest('POST', '/api/actions/billing.invoice.create')
    ->withHeader('X-Tenant-ID', 'acme')
    ->withHeader('Content-Type', 'application/json')
    ->withBody($factory->createStream(json_encode([
        'subject' => null,
        'inputs'  => ['number' => 'X', 'customer_name' => 'Y', 'amount' => ['amount' => '1.00', 'currency' => 'USD']],
    ])));
$noRoleRes = $app->http($noRoleReq);
$noRoleBody = bodyJson($noRoleRes);
_assert('POST without X-Actor-Roles → 403 (fallback removed)',
        $noRoleRes->getStatusCode() === 403, 'status=' . $noRoleRes->getStatusCode());
_assert('denial kind is PolicyDenied (the policy gate fired, not anything else)',
        ($noRoleBody['error']['kind'] ?? null) === 'PolicyDenied');

// 11c — same request WITH the right role goes through unchanged
$withRoleReq = $factory->createServerRequest('POST', '/api/actions/billing.invoice.create')
    ->withHeader('X-Tenant-ID', 'acme')
    ->withHeader('X-Actor-Roles', 'invoice.creator')
    ->withHeader('Content-Type', 'application/json')
    ->withBody($factory->createStream(json_encode([
        'subject' => null,
        'inputs'  => ['number' => 'INV-NOFB-001', 'customer_name' => 'Explicit', 'amount' => ['amount' => '5.00', 'currency' => 'USD']],
    ])));
$withRoleRes = $app->http($withRoleReq);
$withRoleBody = bodyJson($withRoleRes);
_assert('POST WITH X-Actor-Roles: invoice.creator → 200',
        $withRoleRes->getStatusCode() === 200, 'status=' . $withRoleRes->getStatusCode());
_assert('payload ok=true and outputs.id is a 26-char ULID',
        ($withRoleBody['ok'] ?? null) === true
        && isset($withRoleBody['outputs']['id'])
        && strlen((string) $withRoleBody['outputs']['id']) === 26);

// 11d — empty / whitespace X-Actor-Roles is equivalent to missing
$emptyRoleReq = $factory->createServerRequest('POST', '/api/actions/billing.invoice.create')
    ->withHeader('X-Tenant-ID', 'acme')
    ->withHeader('X-Actor-Roles', '   ,  ,   ')
    ->withHeader('Content-Type', 'application/json')
    ->withBody($factory->createStream(json_encode([
        'subject' => null,
        'inputs'  => ['number' => 'X', 'customer_name' => 'Y', 'amount' => ['amount' => '1.00', 'currency' => 'USD']],
    ])));
$emptyRoleRes = $app->http($emptyRoleReq);
_assert('whitespace-only X-Actor-Roles is equivalent to missing (403)',
        $emptyRoleRes->getStatusCode() === 403);

echo "\n══════════════════════════════════════════════════════════════\n";
echo "RESULT: passed={$passed} failed={$failed}\n";
echo "{$BANNER}\n";

exit($failed > 0 ? 1 : 0);

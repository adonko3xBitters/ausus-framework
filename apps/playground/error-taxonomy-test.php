<?php
declare(strict_types=1);

// =============================================================================
// AUSUS — Phase C error-taxonomy regression test
// -----------------------------------------------------------------------------
// Pins the marker-first ErrorMapper contract introduced in Phase C of the
// typed-exception design.
//
// Verifies:
//   1. Marker assignment      — every kernel exception implements its marker.
//   2. Exhaustive coverage    — every kernel exception implements EXACTLY one.
//   3. HTTP mapping parity    — (status, kind) bit-identical to v0.1.1.
//   4. Plugin opt-in          — a custom exception that implements a marker
//                               routes to the correct HTTP status with NO
//                               edit to ErrorMapper.
//   5. Legacy fallback        — out-of-tree class sharing a kernel short-name
//                               still routes via the legacy short-name table;
//                               truly-unknown exceptions fall through to
//                               (500, InternalError).
//
// Wire format is unchanged. No Invoker, DSL, persistence, renderer, or
// schemaVersion is touched by this test or by Phase C.
// =============================================================================

namespace {
    // Autoload must be registered before the legacy-namespace class is parsed,
    // because that class extends \Ausus\AususError (a kernel class).
    require __DIR__ . '/../../vendor/autoload.php';
}

namespace Ausus\PhaseC\Test\Legacy {
    // Out-of-tree class whose short-name collides with a kernel class but
    // does NOT implement any marker interface — exercises the legacy
    // short-name fallback in ErrorMapper.
    class PolicyDenied extends \Ausus\AususError {}
}

namespace {

use Ausus\{
    AususError,
    UnknownAction, PolicySubjectRequired, ActorRequired, TenantContextRequired,
    TenantBoundaryViolation, PolicyDenied, WorkflowStateMismatch,
    WorkflowSubjectNotFound, EffectFailed, ConcurrencyConflict, NotFound,
    AuditEmissionFailed, WorkflowGuardDenied,
    Reference
};
use Ausus\Errors\{
    BadRequestError, ForbiddenError, NotFoundError, ConflictError, InternalError
};
use Ausus\Api\Http\{ErrorMapper, BadRequest};

$passed = 0;
$failed = 0;
function _assert(string $name, bool $cond): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
    } else {
        $failed++;
        fwrite(STDERR, "  ✗ FAIL: {$name}\n");
    }
}

// Reflection bridge — `ErrorMapper::classify()` is private (intentional, no
// API surface change in Phase C). The test invokes it through reflection
// rather than expose a public proxy.
$method = new \ReflectionMethod(ErrorMapper::class, 'classify');
$method->setAccessible(true);
$classify = static fn(\Throwable $e): array => $method->invoke(null, $e);

$ref = new Reference('demo', 'billing.invoice', '01ABC');

// ─── Group 1 — marker assignment (14) ────────────────────────────────────────
_assert('PolicySubjectRequired implements BadRequestError',
    (new PolicySubjectRequired('m')) instanceof BadRequestError);
_assert('ActorRequired implements BadRequestError',
    (new ActorRequired('m')) instanceof BadRequestError);
_assert('TenantContextRequired implements BadRequestError',
    (new TenantContextRequired('m')) instanceof BadRequestError);
_assert('Api\Http\BadRequest implements BadRequestError',
    (new BadRequest('m')) instanceof BadRequestError);
_assert('PolicyDenied implements ForbiddenError',
    (new PolicyDenied('m')) instanceof ForbiddenError);
_assert('WorkflowGuardDenied implements ForbiddenError',
    (new WorkflowGuardDenied('m')) instanceof ForbiddenError);
_assert('TenantBoundaryViolation implements ForbiddenError',
    (new TenantBoundaryViolation('m')) instanceof ForbiddenError);
_assert('UnknownAction implements NotFoundError',
    (new UnknownAction('m')) instanceof NotFoundError);
_assert('NotFound implements NotFoundError',
    (new NotFound($ref)) instanceof NotFoundError);
_assert('WorkflowSubjectNotFound implements NotFoundError',
    (new WorkflowSubjectNotFound('m')) instanceof NotFoundError);
_assert('WorkflowStateMismatch implements ConflictError',
    (new WorkflowStateMismatch('m')) instanceof ConflictError);
_assert('ConcurrencyConflict implements ConflictError',
    (new ConcurrencyConflict($ref, 'a', 'b')) instanceof ConflictError);
_assert('EffectFailed implements InternalError',
    (new EffectFailed('a.b', new \RuntimeException('cause'))) instanceof InternalError);
_assert('AuditEmissionFailed implements InternalError',
    (new AuditEmissionFailed('m')) instanceof InternalError);

// ─── Group 2 — exhaustive coverage (14) ──────────────────────────────────────
// Every kernel exception class implements EXACTLY one marker interface
// (no class double-marked, no class unmarked).
$markers = [
    BadRequestError::class, ForbiddenError::class, NotFoundError::class,
    ConflictError::class, InternalError::class,
];
$kernelMap = [
    PolicySubjectRequired::class   => BadRequestError::class,
    ActorRequired::class           => BadRequestError::class,
    TenantContextRequired::class   => BadRequestError::class,
    BadRequest::class              => BadRequestError::class,
    PolicyDenied::class            => ForbiddenError::class,
    WorkflowGuardDenied::class     => ForbiddenError::class,
    TenantBoundaryViolation::class => ForbiddenError::class,
    UnknownAction::class           => NotFoundError::class,
    NotFound::class                => NotFoundError::class,
    WorkflowSubjectNotFound::class => NotFoundError::class,
    WorkflowStateMismatch::class   => ConflictError::class,
    ConcurrencyConflict::class     => ConflictError::class,
    EffectFailed::class            => InternalError::class,
    AuditEmissionFailed::class     => InternalError::class,
];
foreach ($kernelMap as $cls => $expectedMarker) {
    $impls = array_values(array_filter(
        $markers,
        static fn(string $m): bool => is_subclass_of($cls, $m)
    ));
    $shortCls = (new \ReflectionClass($cls))->getShortName();
    $shortMarker = (new \ReflectionClass($expectedMarker))->getShortName();
    _assert(
        "{$shortCls} implements exactly one marker ({$shortMarker})",
        count($impls) === 1 && in_array($expectedMarker, $impls, true)
    );
}

// ─── Group 3 — HTTP mapping parity (28) ──────────────────────────────────────
// Status + kind bit-identical to v0.1.1 for every kernel exception. Pins the
// wire-format guarantee: `error.kind` continues to be the short class name.
$cases = [
    [new PolicySubjectRequired('msg'),                       400, 'PolicySubjectRequired'],
    [new ActorRequired('msg'),                               400, 'ActorRequired'],
    [new TenantContextRequired('msg'),                       400, 'TenantContextRequired'],
    [new BadRequest('msg'),                                  400, 'BadRequest'],
    [new PolicyDenied('msg'),                                403, 'PolicyDenied'],
    [new WorkflowGuardDenied('msg'),                         403, 'WorkflowGuardDenied'],
    [new TenantBoundaryViolation('msg'),                     403, 'TenantBoundaryViolation'],
    [new UnknownAction('msg'),                               404, 'UnknownAction'],
    [new NotFound($ref),                                     404, 'NotFound'],
    [new WorkflowSubjectNotFound('msg'),                     404, 'WorkflowSubjectNotFound'],
    [new WorkflowStateMismatch('msg'),                       409, 'WorkflowStateMismatch'],
    [new ConcurrencyConflict($ref, 'a', 'b'),                409, 'ConcurrencyConflict'],
    [new EffectFailed('a.b', new \RuntimeException('cause')), 500, 'EffectFailed'],
    [new AuditEmissionFailed('msg'),                         500, 'AuditEmissionFailed'],
];
foreach ($cases as [$e, $expStatus, $expKind]) {
    [$status, $kind] = $classify($e);
    _assert("{$expKind} → HTTP status {$expStatus}", $status === $expStatus);
    _assert("{$expKind} → wire kind '{$expKind}'",   $kind   === $expKind);
}

// ─── Group 4 — plugin opt-in via marker only (10) ────────────────────────────
// A fresh anonymous exception class that does NOT exist in the kernel's
// short-name table routes to the correct HTTP status purely because it
// implements the relevant marker. No edit to ErrorMapper required.
$pluginCases = [
    [new class('m') extends AususError implements BadRequestError {}, 400, 'BadRequestError'],
    [new class('m') extends AususError implements ForbiddenError  {}, 403, 'ForbiddenError'],
    [new class('m') extends AususError implements NotFoundError   {}, 404, 'NotFoundError'],
    [new class('m') extends AususError implements ConflictError   {}, 409, 'ConflictError'],
    [new class('m') extends AususError implements InternalError   {}, 500, 'InternalError'],
];
foreach ($pluginCases as [$e, $expStatus, $markerName]) {
    [$status, $kind] = $classify($e);
    _assert("plugin opt-in via {$markerName} → HTTP {$expStatus}", $status === $expStatus);
    _assert("plugin opt-in via {$markerName} → kind is a non-empty short-name",
        is_string($kind) && $kind !== '');
}

// ─── Group 5 — legacy fallback (4) ───────────────────────────────────────────
// Out-of-tree class sharing a kernel short-name but NOT implementing a marker
// must still route via the legacy short-name table (back-compat).
$legacyShortName = new \Ausus\PhaseC\Test\Legacy\PolicyDenied('msg');
_assert('legacy fallback safety — out-of-tree class does NOT implement any marker',
    !($legacyShortName instanceof BadRequestError)
    && !($legacyShortName instanceof ForbiddenError)
    && !($legacyShortName instanceof NotFoundError)
    && !($legacyShortName instanceof ConflictError)
    && !($legacyShortName instanceof InternalError));
[$legacyStatus, $legacyKind] = $classify($legacyShortName);
_assert('legacy fallback — short-name "PolicyDenied" routes to HTTP 403',
    $legacyStatus === 403);
_assert('legacy fallback — wire kind preserved as "PolicyDenied"',
    $legacyKind === 'PolicyDenied');

// Final default arm — anonymous AususError that doesn't implement any marker
// and doesn't share a short-name with any kernel class — falls through to
// (500, InternalError) exactly as v0.1.1 did.
$unknown = new class('m') extends AususError {};
[$unknownStatus, $unknownKind] = $classify($unknown);
_assert('final default fallback — unmarked unknown throwable → 500/InternalError',
    $unknownStatus === 500 && $unknownKind === 'InternalError');

// ─── Done ────────────────────────────────────────────────────────────────────
echo "RESULT: passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);

} // namespace

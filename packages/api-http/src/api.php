<?php
declare(strict_types=1);

namespace Ausus\Api\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Ausus\{
    Tenant, TenantId, ActorRef, StubActor, Reference,
    MetadataGraph, PersistenceDriver, AuditSink,
};
use Ausus\Runtime\{
    Invoker, PolicyEngine, WorkflowRuntime, TransitionSetIndex,
    EffectDispatcher, DefaultAuditor, SequenceCounter, ProjectionRenderer,
};

// ─────────────────────────────────────────────────────────────────────────────
// Router — single PSR-15 handler that dispatches AUSUS HTTP routes.
//
// Routes (mirrors @ausus/renderer-react expectations from
// renderer/react/src/hooks.tsx):
//
//   GET     /_health                       → liveness probe + graph hash
//   GET     /projections/{fqn}             → ViewSchema (RFC-004) with data
//           Query: locale, renderer, acceptSchemaVersions, subject (Detail)
//   POST    /actions/{fqn}                 → invoke Action; ActionResult
//           Body: { "subject": Reference | null, "inputs": object }
//   OPTIONS *                              → CORS preflight
//
// Required header on /projections/* and /actions/*: X-Tenant-ID.
// Optional headers: X-Actor-Id, X-Actor-Roles (comma-separated). V0 stub-actor
// only — replace with a real auth middleware in front for production.
// ─────────────────────────────────────────────────────────────────────────────

final class Router implements RequestHandlerInterface
{
    private readonly PolicyEngine        $policies;
    private readonly WorkflowRuntime     $workflow;
    private readonly EffectDispatcher    $effects;
    private readonly SequenceCounter     $sequence;

    public function __construct(
        private readonly MetadataGraph              $graph,
        private readonly PersistenceDriver          $driver,
        private readonly AuditSink                  $auditSink,
        private readonly ResponseFactoryInterface   $responses,
        private readonly StreamFactoryInterface     $streams,
        private readonly string                     $pathPrefix = '/api',
    ) {
        // Stateless utilities — share across requests.
        $this->policies = new PolicyEngine($graph);
        $this->workflow = new WorkflowRuntime(new TransitionSetIndex($graph));
        $this->effects  = new EffectDispatcher();
        $this->sequence = new SequenceCounter();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $path   = $request->getUri()->getPath();

        if ($method === 'OPTIONS') {
            return $this->cors($this->json(204, null));
        }

        $relative = str_starts_with($path, $this->pathPrefix)
            ? substr($path, strlen($this->pathPrefix))
            : $path;

        if ($method === 'GET' && $relative === '/_health') {
            return $this->cors($this->json(200, [
                'ok'        => true,
                'service'   => 'ausus/api-http',
                'graphHash' => $this->graph->hash,
            ]));
        }

        try {
            if ($method === 'GET' && str_starts_with($relative, '/projections/')) {
                $fqn = rawurldecode(substr($relative, strlen('/projections/')));
                return $this->cors($this->getProjection($request, $fqn));
            }
            if ($method === 'POST' && str_starts_with($relative, '/actions/')) {
                $fqn = rawurldecode(substr($relative, strlen('/actions/')));
                return $this->cors($this->postAction($request, $fqn));
            }
            return $this->cors($this->errorJson(404, 'NotFound', "no route for $method $path"));
        } catch (\Throwable $e) {
            return $this->cors(ErrorMapper::toResponse($e, $this->responses, $this->streams));
        }
    }

    // ─── GET /projections/{fqn} ──────────────────────────────────────────────
    private function getProjection(ServerRequestInterface $request, string $fqn): ResponseInterface
    {
        $tenantId = $this->requireTenant($request);
        $tenant   = new Tenant(new TenantId($tenantId));

        $projection = $this->graph->projections[$fqn] ?? null;
        if ($projection === null) {
            return $this->errorJson(404, 'ProjectionNotFound', "projection $fqn not found");
        }

        $params           = $request->getQueryParams();
        $subjectIdentity  = $params['subject'] ?? null;
        $subjectRef       = null;
        if ($subjectIdentity !== null && $subjectIdentity !== '') {
            $subjectRef = new Reference(
                $tenantId,
                $projection->ownerEntityFqn,
                (string) $subjectIdentity,
            );
        }

        $renderer = new ProjectionRenderer($this->graph, $this->driver, $tenant);
        $schema   = $renderer->render($fqn, $subjectRef);

        return $this->json(200, $schema);
    }

    // ─── POST /actions/{fqn} ─────────────────────────────────────────────────
    private function postAction(ServerRequestInterface $request, string $actionFqn): ResponseInterface
    {
        $tenantId = $this->requireTenant($request);
        $tenant   = new Tenant(new TenantId($tenantId));
        $actor    = $this->resolveActor($request, $tenantId);

        $action = $this->graph->actions[$actionFqn] ?? null;
        if ($action === null) {
            return $this->errorJson(404, 'ActionNotFound', "action $actionFqn not found");
        }

        $raw  = (string) $request->getBody();
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) {
            throw new BadRequest('request body is not a valid JSON object');
        }

        $subject     = null;
        $subjectRaw  = $body['subject'] ?? null;
        if (is_array($subjectRaw)) {
            $subject = new Reference(
                (string) ($subjectRaw['tenantId']       ?? $tenantId),
                (string) ($subjectRaw['entityFqn']      ?? ''),
                (string) ($subjectRaw['identityHandle'] ?? ''),
            );
        }
        $inputs = $body['inputs'] ?? [];
        if (!is_array($inputs)) $inputs = [];

        $invoker = new Invoker(
            $this->graph,
            $this->driver,
            $this->policies,
            $this->workflow,
            $this->effects,
            new DefaultAuditor($this->auditSink),
            $this->sequence,
            $tenant,
            $actor,
        );
        $outputs = $invoker->invoke($actionFqn, $subject, $inputs);

        return $this->json(200, ['ok' => true, 'outputs' => $outputs]);
    }

    private function requireTenant(ServerRequestInterface $request): string
    {
        $hdr = $request->getHeaderLine('X-Tenant-ID');
        if ($hdr === '') {
            throw new BadRequest('missing required X-Tenant-ID header');
        }
        return $hdr;
    }

    /**
     * Build the per-request actor from the wire headers.
     *
     * A missing or empty `X-Actor-Roles` header yields a **roleless** actor
     * (`roles = []`). Every action that declares `->requireRole(...)` will
     * then return `403 PolicyDenied` — which is the safe and consistent
     * outcome for an unauthenticated caller.
     *
     * v0.1.0 used to substitute a HelloInvoice-specific demo role set
     * (`invoice.creator` + 3 others). That was generic-unfriendly (any
     * non-invoice domain saw a confusing 403 that named roles the consumer
     * never declared) and quietly attached privileges to anonymous callers.
     * Removed in favour of fail-closed behaviour; consumers must send
     * `X-Actor-Roles` explicitly (typically from an authenticated gateway in
     * front of the Router — see the danger admonition in backend/http-api.md).
     */
    private function resolveActor(ServerRequestInterface $request, string $tenantId): StubActor
    {
        $id       = $request->getHeaderLine('X-Actor-Id') ?: 'anon';
        $rolesRaw = $request->getHeaderLine('X-Actor-Roles');
        $roles    = $rolesRaw === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $rolesRaw))));
        return new StubActor(new ActorRef('user', $id, $tenantId), $roles);
    }

    // ─── response helpers ────────────────────────────────────────────────────
    private function json(int $status, mixed $payload): ResponseInterface
    {
        if ($status === 204) {
            return $this->responses->createResponse(204);
        }
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streams->createStream($body === false ? '{}' : $body));
    }

    private function errorJson(int $status, string $kind, string $message): ResponseInterface
    {
        return $this->json($status, [
            'ok' => false,
            'error' => ['kind' => $kind, 'message' => $message],
        ]);
    }

    private function cors(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers',
                'Content-Type, X-Tenant-ID, X-Actor-Id, X-Actor-Roles')
            ->withHeader('Access-Control-Max-Age', '600');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ErrorMapper — kernel exception taxonomy → HTTP status + envelope.
//
// Maps the actual class short-names declared in packages/kernel/src/kernel.php
// (the previous map referenced legacy names `PolicyDeniedException` and
// `EffectFailure`, which never matched the kernel's `PolicyDenied` /
// `EffectFailed` and silently fell through to 500).
//
//   BadRequest                → 400  Bad Request
//   PolicySubjectRequired     → 400
//   ActorRequired             → 400
//   TenantContextRequired     → 400
//   PolicyDenied              → 403  Forbidden
//   TenantBoundaryViolation   → 403
//   WorkflowGuardDenied       → 403
//   UnknownAction             → 404  Not Found
//   NotFound                  → 404
//   WorkflowSubjectNotFound   → 404
//   WorkflowStateMismatch     → 409  Conflict
//   ConcurrencyConflict       → 409
//   EffectFailed              → 500  Internal Server Error
//   AuditEmissionFailed       → 500
//   any other throwable       → 500  InternalError
// ─────────────────────────────────────────────────────────────────────────────

final class ErrorMapper
{
    public static function toResponse(
        \Throwable $e,
        ResponseFactoryInterface $responses,
        StreamFactoryInterface $streams,
    ): ResponseInterface {
        [$status, $kind] = self::classify($e);
        $body = json_encode([
            'ok'    => false,
            'error' => [
                'kind'    => $kind,
                'message' => $e->getMessage(),
            ],
        ], JSON_UNESCAPED_SLASHES);
        return $responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($streams->createStream($body === false ? '{}' : $body));
    }

    /** @return array{0:int,1:string} */
    private static function classify(\Throwable $e): array
    {
        $short = (new \ReflectionClass($e))->getShortName();
        return match ($short) {
            'BadRequest'              => [400, 'BadRequest'],
            'PolicySubjectRequired'   => [400, 'PolicySubjectRequired'],
            'ActorRequired'           => [400, 'ActorRequired'],
            'TenantContextRequired'   => [400, 'TenantContextRequired'],
            'PolicyDenied'            => [403, 'PolicyDenied'],
            'TenantBoundaryViolation' => [403, 'TenantBoundaryViolation'],
            'WorkflowGuardDenied'     => [403, 'WorkflowGuardDenied'],
            'UnknownAction'           => [404, 'UnknownAction'],
            'NotFound'                => [404, 'NotFound'],
            'WorkflowSubjectNotFound' => [404, 'WorkflowSubjectNotFound'],
            'WorkflowStateMismatch'   => [409, 'WorkflowStateMismatch'],
            'ConcurrencyConflict'     => [409, 'ConcurrencyConflict'],
            'EffectFailed'            => [500, 'EffectFailed'],
            'AuditEmissionFailed'     => [500, 'AuditEmissionFailed'],
            default                   => [500, 'InternalError'],
        };
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// BadRequest — internal exception thrown by the Router for protocol errors
// (missing header, malformed body). ErrorMapper turns it into a 400.
// ─────────────────────────────────────────────────────────────────────────────

final class BadRequest extends \RuntimeException {}

// ─────────────────────────────────────────────────────────────────────────────
// Emitter — minimal PSR-7 → SAPI emit. Used by the demo front controller.
// Production deployments can swap in laminas/laminas-httphandlerrunner for
// chunked-transfer + push support; this is the smallest correct version
// for the V0 demo.
// ─────────────────────────────────────────────────────────────────────────────

final class Emitter
{
    public static function emit(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            $statusLine = sprintf(
                'HTTP/%s %d %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $response->getReasonPhrase(),
            );
            header($statusLine, true, $response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
        }
        echo (string) $response->getBody();
    }
}

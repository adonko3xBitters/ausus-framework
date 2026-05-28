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
    Filter,
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

        // Pagination — list mode only. Defaults match the renderer's own
        // defaults; the renderer re-clamps defensively but the API layer is
        // the authoritative user-facing validator and emits 400s on garbage.
        $limit   = 50;
        $offset  = 0;
        $filters = [];
        if ($subjectRef === null) {
            if (array_key_exists('limit', $params)) {
                $rawLimit = $params['limit'];
                if (!is_string($rawLimit) || !preg_match('/^[0-9]+$/', $rawLimit)) {
                    return $this->errorJson(400, 'BadRequest', "?limit must be a non-negative integer");
                }
                $limit = (int) $rawLimit;
                if ($limit < 1) {
                    return $this->errorJson(400, 'BadRequest', "?limit must be >= 1");
                }
                if ($limit > 1000) {
                    return $this->errorJson(400, 'BadRequest', "?limit max is 1000 (got {$limit})");
                }
            }
            if (array_key_exists('offset', $params)) {
                $rawOffset = $params['offset'];
                if (!is_string($rawOffset) || !preg_match('/^[0-9]+$/', $rawOffset)) {
                    return $this->errorJson(400, 'BadRequest', "?offset must be a non-negative integer");
                }
                $offset = (int) $rawOffset;
            }

            // Filtering — parse '?filter.<field>.<op>=<value>' from the raw
            // query string. We deliberately bypass PSR-7's parse_str-based
            // getQueryParams() here because PHP's parse_str silently rewrites
            // '.' to '_' in top-level keys (legacy register_globals semantics),
            // which would mangle 'filter.status.eq' into 'filter_status_eq'.
            // Walking the raw query keeps the key shape stable across servers.
            $rawQuery = $request->getUri()->getQuery();
            $projectionFields = ['id', ...$projection->fields];
            try {
                $pairs = $this->parseFilterPairs($rawQuery);
            } catch (\InvalidArgumentException $e) {
                return $this->errorJson(400, 'BadRequest', $e->getMessage());
            }
            foreach ($pairs as $parsed) {
                [$field, $op, $rawVal] = $parsed;
                if (!in_array($field, $projectionFields, true)) {
                    return $this->errorJson(400, 'BadRequest',
                        "filter field '{$field}' is not declared on projection {$fqn}");
                }
                if (!in_array($op, Filter::OPS, true)) {
                    return $this->errorJson(400, 'BadRequest',
                        "filter operator '{$op}' is not supported (allowed: " . implode(',', Filter::OPS) . ')');
                }
                try {
                    $value = $op === Filter::OP_IN
                        ? $this->parseInList($rawVal)
                        : $rawVal;
                    $filters[] = new Filter($field, $op, $value);
                } catch (\InvalidArgumentException $e) {
                    return $this->errorJson(400, 'BadRequest', $e->getMessage());
                }
            }
        }

        $renderer = new ProjectionRenderer($this->graph, $this->driver, $tenant);
        $schema   = $renderer->render($fqn, $subjectRef, $limit, $offset, $filters);

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

    /**
     * Walk the raw query string and pull out `filter.<field>.<op>=<value>`
     * triples. Returns a list of `[field, op, decoded_value]`.
     *
     * Why not getQueryParams(): PHP's parse_str rewrites '.' to '_' in
     * top-level keys, which would mangle 'filter.status.eq' → 'filter_status_eq'.
     * Walking the raw URI query keeps the dotted key shape stable.
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    private function parseFilterPairs(string $rawQuery): array
    {
        $out = [];
        if ($rawQuery === '') {
            return $out;
        }
        foreach (explode('&', $rawQuery) as $pair) {
            if ($pair === '') continue;
            [$key, $val] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            $val = urldecode($val);
            if (!str_starts_with($key, 'filter.')) continue;
            // Expected shape: filter.<field>.<op>
            $segments = explode('.', $key);
            if (count($segments) !== 3 || $segments[1] === '' || $segments[2] === '') {
                throw new \InvalidArgumentException(
                    "malformed filter key '{$key}' (expected 'filter.<field>.<op>')"
                );
            }
            $out[] = [$segments[1], $segments[2], $val];
        }
        return $out;
    }

    /**
     * Parse a comma-separated `in` list. Empty entries are rejected so
     * `?filter.status.in=A,,B` does not silently smuggle an empty-string
     * scalar into the filter.
     *
     * @return list<string>
     */
    private function parseInList(string $raw): array
    {
        if ($raw === '') {
            throw new \InvalidArgumentException("filter '... in' value list must not be empty");
        }
        $out = [];
        foreach (explode(',', $raw) as $entry) {
            if ($entry === '') {
                throw new \InvalidArgumentException("filter '... in' value list contains an empty entry");
            }
            $out[] = $entry;
        }
        return $out;
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

        // ── 1. Marker-first dispatch (Phase C of the typed-exception design).
        //      A custom plugin exception implementing one of the marker
        //      interfaces in `Ausus\Errors\*` automatically routes to the
        //      marker's HTTP status — no edit to this method required. Every
        //      kernel exception implements exactly one marker (pinned by
        //      apps/playground/error-taxonomy-test.php). Wire `error.kind`
        //      remains the short class name, bit-identical to v0.1.1.
        if ($e instanceof \Ausus\Errors\BadRequestError) return [400, $short];
        if ($e instanceof \Ausus\Errors\ForbiddenError)  return [403, $short];
        if ($e instanceof \Ausus\Errors\NotFoundError)   return [404, $short];
        if ($e instanceof \Ausus\Errors\ConflictError)   return [409, $short];
        if ($e instanceof \Ausus\Errors\InternalError)   return [500, $short];

        // ── 2. Legacy short-name fallback — preserved verbatim. Dead path for
        //      kernel exceptions (all opted in to a marker), kept for forward
        //      compatibility with any out-of-tree exception whose short-name
        //      happens to collide with a kernel class. The final
        //      `default => [500, 'InternalError']` arm is the catch-all for
        //      every other throwable, identical to v0.1.1.
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

/**
 * @internal Internal exception type, raised only by {@see Router} for
 *           wire-protocol failures (missing headers, malformed JSON body,
 *           unsupported method on a known path). The HTTP boundary maps it
 *           to `400 Bad Request` via {@see ErrorMapper}. Consumers MUST
 *           NOT catch or reference it — the stable boundary is the HTTP
 *           status code and the JSON `{ error: { code, message } }` body.
 */
final class BadRequest extends \RuntimeException implements \Ausus\Errors\BadRequestError {}

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

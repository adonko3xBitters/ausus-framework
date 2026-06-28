<?php
declare(strict_types=1);

namespace Ausus\Api\Runtime\Http;

use Ausus\Api\Runtime\Action\InvokeActionHandler;
use Ausus\Api\Runtime\Projection\ReadProjectionHandler;
use Ausus\Api\Runtime\Schema\ReadSchemaHandler;
use Ausus\Contracts\EntityEngine;
use Ausus\Contracts\SchemaRepository;
use Ausus\PersistenceDriver;
use Throwable;

/**
 * IMPLEMENTATION-002 — AUSUS HTTP API Runtime (L4).
 *
 * A framework-agnostic dispatcher over the V1 routes. Consumes ONLY the
 * SchemaRepository, EntityEngine and PersistenceDriver contracts (the concrete
 * Driver is injected by the host). It never compiles, canonicalises, hashes, or
 * loads DSL — the runtime path is strictly resolve → bind → invoke/read.
 *
 * Routes:
 *   POST /api/entities/{entity}/actions/{action}
 *   GET  /api/entities/{entity}/projections/{projection}
 *   GET  /api/entities/{entity}
 *
 * dispatch() returns ['status' => int, 'body' => array]; the host serialises the
 * body to JSON. A trivial PSR-7 adapter can wrap this, but is out of scope.
 */
final class RuntimeApi
{
    private readonly InvokeActionHandler $invoke;
    private readonly ReadProjectionHandler $readProjection;
    private readonly ReadSchemaHandler $readSchema;

    public function __construct(
        SchemaRepository $schemas,
        EntityEngine $engine,
        PersistenceDriver $driver,
        private readonly RequestContextFactory $contextFactory = new RequestContextFactory(),
    ) {
        $this->invoke = new InvokeActionHandler($schemas, $engine, $driver);
        $this->readProjection = new ReadProjectionHandler($schemas, $engine, $driver);
        $this->readSchema = new ReadSchemaHandler($schemas);
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $body request body (POST) or query parameters (GET)
     * @return array{status: int, body: array<string,mixed>}
     */
    public function dispatch(string $method, string $path, array $headers = [], array $body = []): array
    {
        $method = strtoupper($method);
        $path = '/' . trim(parse_url($path, PHP_URL_PATH) ?: $path, '/');
        $context = $this->contextFactory->fromHeaders($headers);

        try {
            if ($method === 'POST' && preg_match('#^/api/entities/([^/]+)/actions/([^/]+)$#', $path, $m)) {
                /** @var array<string,mixed> $inputs */
                $inputs = $body['inputs'] ?? [];

                return $this->ok($this->invoke->handle($m[1], $m[2], $inputs, $context));
            }
            if ($method === 'GET' && preg_match('#^/api/entities/([^/]+)/projections/([^/]+)$#', $path, $m)) {
                return $this->ok($this->readProjection->handle($m[1], $m[2], $body, $context));
            }
            if ($method === 'GET' && preg_match('#^/api/entities/([^/]+)$#', $path, $m)) {
                return $this->ok($this->readSchema->handle($m[1], $context));
            }

            return ['status' => 404, 'body' => ['error' => "no route for {$method} {$path}"]];
        } catch (Throwable $e) {
            return ['status' => $this->statusFor($e), 'body' => ['error' => $e->getMessage()]];
        }
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status: int, body: array<string,mixed>}
     */
    private function ok(array $body): array
    {
        return ['status' => 200, 'body' => $body];
    }

    private function statusFor(Throwable $e): int
    {
        $m = strtolower($e->getMessage());
        if (str_contains($m, 'denied')) {
            return 403; // authorization deny
        }
        if (str_contains($m, 'no schema') || str_contains($m, 'unknown') || str_contains($m, 'not found')) {
            return 404; // unknown entity/action/projection/subject
        }
        if (str_contains($m, 'invalid transition') || str_contains($m, 'missing subject')) {
            return 422; // semantically invalid request
        }

        return 500; // persistence / unexpected failure (rolled back)
    }
}

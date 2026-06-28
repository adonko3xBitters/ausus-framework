<?php
declare(strict_types=1);

namespace Ausus\Api\Runtime\Http;

use Ausus\ActorRef;
use Ausus\Contracts\Context;
use Ausus\Tenant;
use Ausus\TenantId;
use DateTimeImmutable;

/**
 * IMPLEMENTATION-002 Phase API — builds a kernel {@see Context} (actor / tenant /
 * now) from request headers. Uses only frozen kernel value objects; introduces
 * no new DTO or concept (the returned Context is an anonymous implementation of
 * the existing contract).
 *
 * Headers (case-insensitive): X-Tenant-ID, X-Actor-Type, X-Actor-Id.
 */
final class RequestContextFactory
{
    public function __construct(private readonly ?DateTimeImmutable $now = null)
    {
    }

    /** @param array<string,string> $headers */
    public function fromHeaders(array $headers): Context
    {
        $h = array_change_key_case($headers, CASE_LOWER);
        $tenant = $h['x-tenant-id'] ?? 'public';
        $actorType = $h['x-actor-type'] ?? 'user';
        $actorId = $h['x-actor-id'] ?? 'anonymous';
        $now = $this->now ?? new DateTimeImmutable();

        return new class($actorType, $actorId, $tenant, $now) implements Context {
            public function __construct(
                private readonly string $actorType,
                private readonly string $actorId,
                private readonly string $tenant,
                private readonly DateTimeImmutable $now,
            ) {
            }

            public function actor(): ActorRef
            {
                return new ActorRef($this->actorType, $this->actorId, $this->tenant);
            }

            public function tenant(): Tenant
            {
                return new Tenant(new TenantId($this->tenant));
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}

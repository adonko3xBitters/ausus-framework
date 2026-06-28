<?php
declare(strict_types=1);

namespace Ausus\Contracts;

use Ausus\ActorRef;
use Ausus\Tenant;

/**
 * RFC-011 — runtime facts who/where/when, injected at invoke()/read().
 *
 * Reuses the frozen kernel value objects {@see ActorRef} (who) and
 * {@see Tenant} (where); `now()` (when) is a value clock read supplied by the
 * caller, never read from the system clock inside the engine.
 */
interface Context
{
    public function actor(): ActorRef;

    public function tenant(): Tenant;

    public function now(): \DateTimeImmutable;
}

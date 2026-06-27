<?php
declare(strict_types=1);

namespace Ausus\Contracts;

use Ausus\Entity;

/**
 * RFC-011 §4 — an executable, Driver-bound Entity.
 *
 * `invoke()` performs a mutation (gate → effect → persist) and returns the
 * affected instance as the frozen kernel {@see Entity}. `read()` resolves a
 * Projection and returns visibility-filtered rows. The result types reuse
 * frozen/primitive shapes — no InvocationResult/ReadResult concept is added.
 */
interface RuntimeEntity
{
    /** @param array<string,mixed> $inputs */
    public function invoke(string $action, array $inputs, Context $context): Entity;

    /**
     * @param array<string,mixed> $params filters/sort/pagination (selection)
     * @return list<array<string,mixed>>
     */
    public function read(string $projection, array $params, Context $context): array;
}

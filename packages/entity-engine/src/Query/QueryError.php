<?php

declare(strict_types=1);

namespace Ausus\Engine\Query;

use RuntimeException;

/**
 * L3 — raised when a projection query (filters / sorting / pagination) is
 * malformed or references something the projection does not expose.
 *
 * The query language is FAIL-CLOSED: an invalid query is rejected, never
 * silently ignored or coerced. The HTTP layer maps this to 400.
 */
final class QueryError extends RuntimeException
{
}

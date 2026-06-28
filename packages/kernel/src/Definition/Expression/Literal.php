<?php
declare(strict_types=1);

namespace Ausus\Definition\Expression;

/**
 * RFC-012 §Q5 — a constant operand (scalar or null).
 *
 * Enum symbols are carried as their string value. One of the two operand shapes
 * (the other being {@see FactRef}).
 */
final readonly class Literal
{
    public function __construct(
        public string|int|float|bool|null $value,
    ) {
    }
}

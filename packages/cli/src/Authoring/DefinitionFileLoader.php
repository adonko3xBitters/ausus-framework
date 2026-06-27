<?php
declare(strict_types=1);

namespace Ausus\Cli\Authoring;

use Ausus\Definition\EntityDefinition;
use Ausus\Engine\Compile\CompilationError;
use Throwable;

/**
 * IMPLEMENTATION-001 Phase 5B — load one authored entities/*.php file and return
 * its value, which MUST be exactly an {@see EntityDefinition}.
 *
 * The file is required inside a static closure (no `$this`, no access to loader
 * state) — a best-effort sandbox. True isolation is not achievable in pure PHP;
 * the {@see ForbiddenSymbolScanner} (run earlier by the frontend) is the primary
 * guarantee. Any non-EntityDefinition return — or no `return` at all (which
 * yields int 1) — is rejected.
 */
final class DefinitionFileLoader
{
    public function load(string $file): EntityDefinition
    {
        if (!is_file($file)) {
            throw new CompilationError("entities file not found: {$file}");
        }

        try {
            /** @var mixed $value */
            $value = (static fn (string $__aususFile): mixed => require $__aususFile)($file);
        } catch (CompilationError $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CompilationError("error loading '{$file}': " . $e->getMessage(), 0, $e);
        }

        if (!$value instanceof EntityDefinition) {
            throw new CompilationError(
                "entities file '{$file}' must return an EntityDefinition, got " . get_debug_type($value),
            );
        }

        return $value;
    }
}

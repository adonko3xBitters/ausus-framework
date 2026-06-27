<?php
declare(strict_types=1);

namespace Ausus\Cli\Authoring;

use Ausus\Definition\EntityDefinition;
use Ausus\Engine\Compile\CompilationError;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * IMPLEMENTATION-001 Phase 5B — the authoring frontend (RFC-CLI-002/003).
 *
 *   discover(root) :  glob entities/**\/*.php  →  scan  →  load  →  EntityDefinition[]
 *
 * It does NOT compile, hash, validate closure, or produce any EntitySchema — it
 * only loads author definitions, ready for the Compiler. The entity identity
 * comes from the file CONTENT, never from the filename. Each file is evaluated
 * exactly ONCE (no double evaluation): determinism rests on the static scan plus
 * the loader's best-effort sandbox.
 */
final class DslFrontend
{
    public function __construct(
        private readonly ForbiddenSymbolScanner $scanner = new ForbiddenSymbolScanner(),
        private readonly DefinitionFileLoader $loader = new DefinitionFileLoader(),
    ) {
    }

    /**
     * @return list<EntityDefinition>
     */
    public function discover(string $root): array
    {
        $definitions = [];
        foreach ($this->files($root) as $file) {
            // Scan BEFORE evaluation — forbidden symbols abort here.
            $this->scanner->scan($this->read($file));
            $definitions[] = $this->loader->load($file);
        }

        return $definitions;
    }

    /**
     * Recursive, deterministic discovery of *.php files under $root.
     *
     * @return list<string>
     */
    private function files(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $entry) {
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files, SORT_STRING); // stable, declaration-order-independent

        return $files;
    }

    private function read(string $file): string
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            throw new CompilationError("cannot read entities file: {$file}");
        }

        return $source;
    }
}

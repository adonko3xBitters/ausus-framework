<?php
declare(strict_types=1);

namespace Ausus\Cli\Command;

use Ausus\Cli\Authoring\DslFrontend;
use Ausus\Cli\Repository\FileSchemaRepository;
use Ausus\Engine\Compile\Compiler;
use RuntimeException;
use Throwable;

/**
 * IMPLEMENTATION-001 Phase 7 — `ausus compile` (RFC-CLI-001 §Q3/Q5/Q7).
 *
 * Pure orchestration, no business logic:
 *
 *   entities/*.php → DslFrontend::discover() → EntityDefinition[]
 *                  → Compiler::compile()     → CompiledGraph(EntitySchema[], SchemaIndex)
 *                  → FileSchemaRepository     → .ausus/schemas/<hash>.json + index.json
 *
 * Atomicity (RFC-CLI-001): discovery + compilation happen entirely in memory —
 * any DSL or closure error aborts BEFORE a single byte is written. Persistence
 * then stages the whole set in a sibling temp directory and promotes it: new
 * <hash>.json files are moved in additively (existing hashes are left untouched,
 * preserving their timestamps — Q5), and the index is swapped in by a single
 * atomic rename as the commit point. On any failure the previous .ausus is
 * intact and no partial index is ever visible.
 *
 * No bind, no runtime, no driver, no doctor, no guard/fact/Context evaluation.
 */
final class CompileEntitiesCommand
{
    public const SUCCESS = 0;
    public const FAILURE = 1;

    public function __construct(
        private readonly DslFrontend $frontend = new DslFrontend(),
        private readonly Compiler $compiler = new Compiler(),
    ) {
    }

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function run(string $entitiesDir, string $aususRoot, $stdout = null, $stderr = null): int
    {
        $stdout ??= STDOUT;
        $stderr ??= STDERR;

        // 1–2. Discover + compile — fully in memory; errors write nothing.
        try {
            $definitions = $this->frontend->discover($entitiesDir);
            $graph = $this->compiler->compile($definitions);
        } catch (Throwable $e) {
            fwrite($stderr, 'ausus:compile: ' . $e->getMessage() . PHP_EOL);

            return self::FAILURE;
        }

        // 3. Persist atomically: stage everything, then promote.
        $staging = $aususRoot . '.staging-' . bin2hex(random_bytes(5));
        try {
            $stagingRepo = new FileSchemaRepository($staging);
            foreach ($graph->schemas as $schema) {
                $stagingRepo->putByHash($schema);
            }
            $this->promote($staging, $aususRoot);
        } catch (Throwable $e) {
            $this->rrmdir($staging);
            fwrite($stderr, 'ausus:compile: ' . $e->getMessage() . PHP_EOL);

            return self::FAILURE; // previous .ausus untouched
        }
        $this->rrmdir($staging);

        fwrite($stdout, sprintf(
            "Compiled:%s  definitions : %d%s  schemas     : %d%s",
            PHP_EOL,
            count($definitions),
            PHP_EOL,
            count($graph->schemas),
            PHP_EOL,
        ));

        return self::SUCCESS;
    }

    /**
     * Promote a staged .ausus into place. New schema files are moved in
     * additively; existing hashes are kept as-is (Q5). The index is committed
     * last by a single atomic rename.
     */
    private function promote(string $staging, string $aususRoot): void
    {
        if (!is_dir($staging)) {
            return; // nothing was staged (e.g. empty entities/) — leave state as-is
        }

        $realSchemas = $aususRoot . '/schemas';
        if (!is_dir($realSchemas) && !@mkdir($realSchemas, 0o775, true) && !is_dir($realSchemas)) {
            throw new RuntimeException("cannot create schema directory: {$realSchemas}");
        }

        foreach (glob($staging . '/schemas/*.json') ?: [] as $staged) {
            $dest = $realSchemas . '/' . basename($staged);
            if (is_file($dest)) {
                continue; // Q5: identical hash already present — preserve it
            }
            if (!@rename($staged, $dest)) {
                throw new RuntimeException('cannot promote schema ' . basename($staged));
            }
        }

        // Commit point: atomic index swap.
        $stagedIndex = $staging . '/index.json';
        if (is_file($stagedIndex)) {
            $target = $aususRoot . '/index.json';
            if (!@rename($stagedIndex, $target)) {
                // Cross-filesystem fallback: write-then-rename within the target dir.
                $tmp = $target . '.tmp';
                if (file_put_contents($tmp, (string) file_get_contents($stagedIndex)) === false || !@rename($tmp, $target)) {
                    @unlink($tmp);
                    throw new RuntimeException("cannot commit index: {$target}");
                }
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

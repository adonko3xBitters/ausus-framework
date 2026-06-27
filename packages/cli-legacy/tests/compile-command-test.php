<?php
declare(strict_types=1);

// RFC-IMPL ApplicationProvider — test C3 de CompileCommand.
//
// Valide : injection du provider (M-12) ; succès read-only + cache écrit ;
// échec de provide() -> code non-zéro + AUCUN artefact (M-15/M-16).
// Le provider et le plugin sont des DOUBLES DE TEST (anonymes), pas des
// providers concrets d'application (C5 hors périmètre).

$autoload = [
    __DIR__ . '/../../../vendor/autoload.php',   // monorepo dev
    __DIR__ . '/../vendor/autoload.php',          // package installé
];
foreach ($autoload as $f) { if (file_exists($f)) { require $f; break; } }

use Ausus\{Application, ApplicationConfig, ApplicationProvider, MetadataGraph, Plugin, ProviderError};
use Ausus\Cli\CompileCommand;

$pass = 0; $fail = 0;
function check(string $label, bool $cond): void {
    global $pass, $fail;
    echo ($cond ? "  ✓ " : "  ✗ ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

// Double de test : provider conforme (contexte outillage — sqlite mémoire, migrate off).
$okProvider = new class implements ApplicationProvider {
    public function provide(): Application {
        $plugin = new class implements Plugin {
            public function name(): string { return 'cli-test'; }
            public function phpNamespace(): string { return 'CliTest'; }
            public function describe(): array { return []; }
        };
        return Application::create(
            ApplicationConfig::make()->sqlite(':memory:')->migrate(false)
        )->register($plugin);   // create+register ; PAS de boot()
    }
};

echo "── compile : succès + cache écrit ─────────────────────────────\n";
$out = sys_get_temp_dir() . '/ausus_compile_ok_' . getmypid() . '.cache';
@unlink($out);
$code = (new CompileCommand($okProvider))->run(["--out={$out}"]);
check('exit SUCCESS',                         $code === CompileCommand::SUCCESS);
check('fichier cache écrit',                  is_file($out));
$graph = is_file($out) ? unserialize(file_get_contents($out)) : null;
check('cache = MetadataGraph désérialisable', $graph instanceof MetadataGraph);
check('hash du cache == hash recomposé',      $graph !== null && $graph->hash === $okProvider->provide()->graph()->hash);
@unlink($out);

echo "── compile : provide() lève -> FAILURE, aucun artefact (M-15/M-16) ─\n";
$badProvider = new class implements ApplicationProvider {
    public function provide(): Application { throw new ProviderError('boom'); }
};
$out2 = sys_get_temp_dir() . '/ausus_compile_fail_' . getmypid() . '.cache';
@unlink($out2);
$code2 = (new CompileCommand($badProvider))->run(["--out={$out2}"]);
check('exit FAILURE',         $code2 === CompileCommand::FAILURE);
check('aucun artefact écrit', !file_exists($out2));
@unlink($out2);

echo "\nRESULT: passed={$pass} failed={$fail}\n";
exit($fail === 0 ? 0 : 1);

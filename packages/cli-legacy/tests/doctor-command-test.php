<?php
declare(strict_types=1);

// RFC-IMPL ApplicationProvider — test C4 de DoctorCommand.
//
// Valide : injection du provider (M-12) ; les 5 checks MVP ; le check
// "cache present" (présent -> SUCCESS, absent -> FAILURE) ; échec de provide()
// -> code non-zéro (M-16). Provider/plugin = doubles de test (anonymes).

$autoload = [
    __DIR__ . '/../../../vendor/autoload.php',   // monorepo dev
    __DIR__ . '/../vendor/autoload.php',          // package installé
];
foreach ($autoload as $f) { if (file_exists($f)) { require $f; break; } }

use Ausus\{Application, ApplicationConfig, ApplicationProvider, Plugin, ProviderError};
use Ausus\Cli\DoctorCommand;

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

$cache = sys_get_temp_dir() . '/ausus_doctor_' . getmypid() . '.cache';

echo "── doctor : cache présent -> 5 checks (les lignes PASS s'affichent) ─\n";
@unlink($cache); file_put_contents($cache, 'x');   // cache présent
$code = (new DoctorCommand($okProvider))->run(["--out={$cache}"]);
check('cache présent -> SUCCESS (5 PASS)', $code === DoctorCommand::SUCCESS);
@unlink($cache);

echo "── doctor : même provider, cache absent -> seul le check cache bascule ─\n";
$code2 = (new DoctorCommand($okProvider))->run(["--out={$cache}"]);   // cache absent
check('cache absent -> FAILURE (check cache present)', $code2 === DoctorCommand::FAILURE);

echo "── doctor : provide() lève -> FAILURE (M-16) ──────────────────────\n";
$badProvider = new class implements ApplicationProvider {
    public function provide(): Application { throw new ProviderError('boom'); }
};
$code3 = (new DoctorCommand($badProvider))->run([]);
check('provide() lève -> FAILURE', $code3 === DoctorCommand::FAILURE);

echo "\nRESULT: passed={$pass} failed={$fail}\n";
exit($fail === 0 ? 0 : 1);

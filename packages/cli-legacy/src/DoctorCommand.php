<?php
declare(strict_types=1);

namespace Ausus\Cli;

use Ausus\ApplicationProvider;
use Ausus\AuditSink;
use Ausus\MetadataGraph;
use Ausus\PersistenceDriver;

/**
 * RFC-IMPL ApplicationProvider — commande générique `doctor` (C4).
 *
 * Consomme un {@see ApplicationProvider} par injection (M-12), appelle
 * `provide()` UNE seule fois, puis exécute les cinq checks de santé MVP via
 * l'API publique de l'Application.
 *
 * Read-only : la non-mutation de schéma est garantie par la conformité M-11 du
 * provider (migrations désactivées) ; la commande ne mute rien (M-18/MN-9).
 * Sur échec de `provide()` : capture `\Throwable`, rapporte, retourne un code
 * non-zéro (M-16). Chaque check est isolé : une sonde qui lève vaut FAIL.
 *
 * Sans container, découverte ni réflexion ; n'accède jamais aux plugins/config.
 * Le chemin du cache est résolu via {@see GraphCachePath} (logique partagée avec
 * compile — aucune duplication).
 */
final class DoctorCommand
{
    public const SUCCESS = 0;
    public const FAILURE = 1;

    public function __construct(
        private readonly ApplicationProvider $provider,
    ) {}

    /** @param string[] $argv option supportée : `--out=PATH` (chemin du cache vérifié). */
    public function run(array $argv = []): int
    {
        try {
            $app = $this->provider->provide();   // M-12 : appel unique
        } catch (\Throwable $e) {
            \fwrite(\STDERR, 'ausus:doctor: échec — ' . $e->getMessage() . \PHP_EOL);
            return self::FAILURE;                 // M-16 : provide() lève -> code non-zéro
        }

        $cachePath = GraphCachePath::resolve($argv);   // logique partagée (pas de duplication)

        /** @var array<string,callable():bool> $checks — read-only ; auto-boot via accesseurs. */
        $checks = [
            'graph valid'           => static fn(): bool => $app->graph() instanceof MetadataGraph,
            'cache present'         => static fn(): bool => \is_file($cachePath),
            'persistence reachable' => static fn(): bool => $app->driver() instanceof PersistenceDriver,
            'audit sink reachable'  => static fn(): bool => $app->auditSink() instanceof AuditSink,
            'primary sink present'  => static fn(): bool => $app->auditSink() instanceof AuditSink,
        ];

        $allPass = true;
        foreach ($checks as $label => $probe) {
            try {
                $ok = $probe();
            } catch (\Throwable) {
                $ok = false;                       // sonde qui lève -> FAIL, jamais de crash
            }
            \fwrite(\STDOUT, ($ok ? '  PASS ' : '  FAIL ') . $label . \PHP_EOL);
            $allPass = $allPass && $ok;
        }

        return $allPass ? self::SUCCESS : self::FAILURE;
    }
}

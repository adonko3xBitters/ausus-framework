<?php
declare(strict_types=1);

namespace Ausus\Cli;

use Ausus\ApplicationProvider;

/**
 * RFC-IMPL ApplicationProvider — commande générique `compile` (C3).
 *
 * Consomme un {@see ApplicationProvider} par injection (M-12), appelle
 * `provide()` UNE seule fois, extrait le graphe via l'API publique de
 * l'Application, et écrit un cache sérialisé.
 *
 * Read-only : la non-mutation de schéma est garantie par la conformité M-11 du
 * provider (migrations désactivées) ; la commande ne mute rien (M-18/MN-9).
 * Sur échec de `provide()` / boot / compile, capture `\Throwable`, rapporte,
 * retourne un code non-zéro et n'écrit AUCUN artefact (M-15/M-16).
 *
 * Sans container, sans découverte, sans réflexion ; n'accède jamais aux
 * plugins/config de l'application (consommation : ApplicationProvider → Application).
 */
final class CompileCommand
{
    public const SUCCESS = 0;
    public const FAILURE = 1;

    public function __construct(
        private readonly ApplicationProvider $provider,
    ) {}

    /**
     * @param string[] $argv arguments bruts ; option supportée : `--out=PATH`.
     */
    public function run(array $argv = []): int
    {
        try {
            // M-12 : appel unique à provide(). M-16 : tout échec (provide(), boot
            // paresseux, compilation) est capturé ; sérialisation AVANT écriture
            // afin de ne produire aucun artefact partiel.
            $app   = $this->provider->provide();
            $graph = $app->graph();          // auto-boot ; read-only (M-11 côté provider)
            $bytes = \serialize($graph);
        } catch (\Throwable $e) {
            \fwrite(\STDERR, 'ausus:compile: échec — ' . $e->getMessage() . \PHP_EOL);
            return self::FAILURE;            // M-15/M-16 : code non-zéro, rien d'écrit
        }

        $path = GraphCachePath::resolve($argv);
        $dir  = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0o775, true) && !\is_dir($dir)) {
            \fwrite(\STDERR, "ausus:compile: répertoire de cache non créable: {$dir}" . \PHP_EOL);
            return self::FAILURE;
        }

        // Écriture atomique (temp + rename) : pas d'artefact partiel.
        $tmp = $path . '.tmp';
        if (\file_put_contents($tmp, $bytes) === false) {
            \fwrite(\STDERR, "ausus:compile: écriture du cache impossible: {$path}" . \PHP_EOL);
            return self::FAILURE;
        }
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            \fwrite(\STDERR, "ausus:compile: finalisation du cache impossible: {$path}" . \PHP_EOL);
            return self::FAILURE;
        }

        \fwrite(\STDOUT, \sprintf(
            "ausus:compile: graph %s (%d entities, %d actions) → %s%s",
            $graph->hash,
            \count($graph->entities),
            \count($graph->actions),
            $path,
            \PHP_EOL,
        ));
        return self::SUCCESS;
    }
}

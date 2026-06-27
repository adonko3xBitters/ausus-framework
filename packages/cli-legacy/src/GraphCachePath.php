<?php
declare(strict_types=1);

namespace Ausus\Cli;

/**
 * Résolution PARTAGÉE du chemin du cache de graphe (compile écrit, doctor lit).
 *
 * Priorité : option `--out=PATH` > variable d'environnement `AUSUS_GRAPH_CACHE`
 * > défaut `<cwd>/.ausus/graph.cache`.
 *
 * Logique unique pour {@see CompileCommand} et {@see DoctorCommand} — aucune
 * duplication (lève le risque DR-2 de la revue C3).
 */
final class GraphCachePath
{
    /** @param string[] $argv */
    public static function resolve(array $argv): string
    {
        foreach ($argv as $arg) {
            if (\is_string($arg) && \str_starts_with($arg, '--out=')) {
                return \substr($arg, 6);
            }
        }
        $env = \getenv('AUSUS_GRAPH_CACHE');
        if (\is_string($env) && $env !== '') {
            return $env;
        }
        return \getcwd() . '/.ausus/graph.cache';
    }
}

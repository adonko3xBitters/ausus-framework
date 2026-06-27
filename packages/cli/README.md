# ausus/cli

Outillage générique AUSUS (L6) — commandes consommant un
[`Ausus\ApplicationProvider`](../../docs/application-provider-adoption.md).

PHP-natif : **aucun** container, **aucune** découverte automatique, **aucune**
réflexion. Dépend uniquement de `ausus/standard-stack`.

## Modèle de consommation

Une application expose un `ApplicationProvider` et l'injecte dans une commande
(via son `bin/ausus.php`) :

```php
$provider = new MyProvider();                       // l'app construit
(new CompileCommand($provider))->run($argv);         // injection (M-12)
(new DoctorCommand($provider))->run($argv);
```

La commande appelle `provide()` **une seule fois** et consomme l'`Application`
uniquement via son API publique. Read-only : la non-mutation de schéma est
garantie par la conformité **M-11** du provider (`migrate(false)`).

## `CompileCommand`

`src/CompileCommand.php` — construit et met en cache le graphe de métadonnées.

- `__construct(ApplicationProvider $provider)`
- `run(array $argv = []): int`

Flux : `provide()` → `Application::graph()` (auto-boot) → `serialize($graph)` →
écriture **atomique** (temp + rename) du fichier de cache. Sur échec
(`provide()` / boot / compilation) : capture `\Throwable`, message sur `STDERR`,
code non-zéro, **aucun artefact partiel** (M-15/M-16).

Sortie (succès) :

```
ausus:compile: graph <hash> (<N> entities, <M> actions) → <chemin-cache>
```

## `DoctorCommand`

`src/DoctorCommand.php` — exécute cinq checks de santé MVP.

- `__construct(ApplicationProvider $provider)`
- `run(array $argv = []): int`

Checks (chacun isolé ; une sonde qui lève vaut `FAIL`) :

| Check | Source |
|---|---|
| `graph valid` | `Application::graph()` retourne `MetadataGraph` |
| `cache present` | le fichier de cache existe (`GraphCachePath::resolve`) |
| `persistence reachable` | `Application::driver()` est un `PersistenceDriver` |
| `audit sink reachable` | `Application::auditSink()` est un `AuditSink` |
| `primary sink present` | `Application::auditSink()` est un `AuditSink` |

Sortie : une ligne `PASS`/`FAIL` par check ; succès si **tous** PASS. Sur échec
de `provide()` : `STDERR` + code non-zéro (M-16).

## `GraphCachePath`

`src/GraphCachePath.php` — résolution **partagée** du chemin du cache (compile
écrit, doctor lit). Logique unique (aucune duplication).

```php
GraphCachePath::resolve(array $argv): string
```

**Priorité** :

1. option `--out=PATH` ;
2. variable d'environnement `AUSUS_GRAPH_CACHE` ;
3. défaut `<cwd>/.ausus/graph.cache`.

## Format du cache

`serialize($graph)` d'un `Ausus\MetadataGraph` (PHP natif). **Interne au package
`cli`** (compile écrit, doctor/compile lisent). Non versionné : la lisibilité
inter-versions est couplée à la structure de `MetadataGraph` (voir « engagements
implicites » du rapport C7).

## Codes de sortie

| Code | Signification |
|---|---|
| `0` | `CompileCommand::SUCCESS` / `DoctorCommand::SUCCESS` |
| `1` | `…::FAILURE` (échec de `provide()`/boot, écriture impossible, ou un check `FAIL`) |
| `2` | sous-commande inconnue (usage) — émis par le `bin/ausus.php` de l'application |

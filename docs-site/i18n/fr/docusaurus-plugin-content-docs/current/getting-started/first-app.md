---
id: first-app
title: Votre première application
sidebar_label: Votre première application
description: Câblez les couches AUSUS ensemble et invoquez une action.
---

# Votre première application

Cette page présente le plus petit programme AUSUS utile : compiler un domaine, appliquer un
schéma, invoquer une action et relire le résultat. C'est la même forme que le
script `composer boot` de `ausus/starter`.

Si vous voulez le domaine complet et annoté, passez directement au
[tutoriel HelloInvoice](hello-invoice.md). Cette page se concentre sur **la manière dont les
couches se connectent**.

## Les éléments {#the-pieces}

Une application AUSUS en cours d'exécution est assemblée à partir de cinq éléments :

1. Un **Plugin** — la description de votre domaine (voir [Plugins](../concepts/plugins.md)).
2. Le **Compilateur** — transforme les plugins en un `MetadataGraph`.
3. Un **PersistenceDriver** — dans la v0.1.0, le pilote SQLite.
4. Le **runtime** — `Invoker`, `PolicyEngine`, `WorkflowRuntime`, etc.
5. Un **Actor** et un **Tenant** — qui agit, et dans quel tenant.

## Étape 1 — compiler le graphe {#step-1--compile-the-graph}

```php
use Ausus\Compiler;

$compiler = new Compiler();
$graph    = $compiler->compile([new HelloInvoiceDsl()]);

echo substr($graph->hash, 0, 12);   // content-addressable graph hash
```

Le `MetadataGraph` est immuable et déterministe : les mêmes plugins produisent toujours
le même `hash`. Consultez [Le graphe de métadonnées](../concepts/metadata-graph.md).

## Étape 2 — appliquer le schéma {#step-2--apply-the-schema}

Le paquet SQL dérive les instructions `CREATE TABLE` directement depuis le graphe.

```php
use Ausus\Persistence\Sql\SchemaDeriver;

$pdo = new PDO('sqlite:' . sys_get_temp_dir() . '/myapp.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

foreach (SchemaDeriver::deriveAll($graph) as $stmt) {
    $pdo->exec($stmt);
}
```

Cela crée une table par entité, plus la table interne `kernel_audit_log`.

## Étape 3 — câbler le runtime {#step-3--wire-the-runtime}

```php
use Ausus\{Tenant, TenantId, ActorRef, StubActor};
use Ausus\Persistence\Sql\{SqlitePersistenceDriver, DatabaseAuditSink};
use Ausus\Runtime\{
    PolicyEngine, WorkflowRuntime, TransitionSetIndex,
    EffectDispatcher, DefaultAuditor, SequenceCounter, Invoker,
};

$tenant = new Tenant(new TenantId('acme'));
$actor  = new StubActor(
    new ActorRef('user', 'user42', 'acme'),
    ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'],
);

$driver  = new SqlitePersistenceDriver($pdo, $graph);
$invoker = new Invoker(
    $graph,
    $driver,
    new PolicyEngine($graph),
    new WorkflowRuntime(new TransitionSetIndex($graph)),
    new EffectDispatcher(),
    new DefaultAuditor(new DatabaseAuditSink($pdo)),
    new SequenceCounter(),
    $tenant,
    $actor,
);
```

:::note Mono-tenant, mono-acteur par invoker
Dans la v0.1.0, un `Invoker` est construit avec **un seul** `Tenant` et **un seul**
`Actor`. Pour agir en tant que tenant ou acteur différent, construisez un autre `Invoker`. Il
s'agit d'une simplification délibérée de la v0.1.0 — consultez [Le runtime](../backend/runtime.md).
:::

## Étape 4 — invoquer une action {#step-4--invoke-an-action}

```php
use Ausus\Reference;

// Create — no subject, just inputs.
$created = $invoker->invoke('billing.invoice.create', null, [
    'number'        => 'INV-2026-001',
    'customer_name' => 'ACME Corporation',
    'amount'        => ['amount' => '1500.00', 'currency' => 'USD'],
]);
// $created['id'] is a 26-char ULID; $created['status'] === 'DRAFT'

// Transition — subject required.
$ref = new Reference('acme', 'billing.invoice', $created['id']);
$invoker->invoke('billing.invoice.issue', $ref, []);
```

Chaque appel `invoke()` exécute la chaîne complète du runtime — vérification de politique,
garde de workflow, effet, audit — au sein d'une seule transaction de base de données. Consultez
[Le runtime](../backend/runtime.md).

## Étape 5 — rendre une projection {#step-5--render-a-projection}

```php
use Ausus\Runtime\ProjectionRenderer;

$renderer = new ProjectionRenderer($graph, $driver, $tenant);
$schema   = $renderer->render('billing.invoice.summary');
// $schema is a ViewSchema array: fields, actions, data.items, ...
```

Le résultat est un [ViewSchema](../frontend/viewschema.md) — le format de transport que le
[moteur de rendu React](../frontend/react-renderer.md) consomme.

## Ce que vous avez construit {#what-you-have-built}

Vous disposez maintenant de la tranche verticale complète : **graphe → schéma → runtime → projection**.
L'API HTTP ([ausus/api-http](../backend/http-api.md)) n'est tout simplement que ce même
câblage placé derrière le traitement de requêtes PSR-7/15.

## Limites actuelles de la v0.1.0 {#current-v010-limitations}

- Il n'y a pas de conteneur de services ni d'assistant d'auto-câblage dans la v0.1.0 — vous
  assemblez le runtime explicitement, comme ci-dessus. `ausus/starter` vous fournit ce
  câblage déjà écrit.
- `StubActor` est un acteur en mémoire fixe. Il n'y a pas de couche d'authentification.

## Étapes suivantes {#next}

- [Tutoriel HelloInvoice](hello-invoice.md) — le même flux avec un domaine réel
  et des assertions.
- [Structure du projet](project-structure.md) — où se trouvent les fichiers.

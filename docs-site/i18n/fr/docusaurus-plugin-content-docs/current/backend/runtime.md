---
id: runtime
title: Le Runtime
sidebar_label: Le Runtime
description: La chaîne Invoker qui exécute les actions.
---

# Le Runtime

Le runtime (`ausus/runtime-default`, couche L2) exécute les actions contre un
[graphe de métadonnées](../concepts/metadata-graph.md). Son centre est l'`Invoker`.

## La chaîne Invoker {#the-invoker-chain}

Chaque appel à `Invoker::invoke()` exécute la même chaîne ordonnée. Cette chaîne est la
garantie fondamentale d'AUSUS : une action soit complète toutes ces étapes, soit
ne change rien.

```
invoke(actionFqn, subject?, inputs)
  │
  1. Pre-flight   action exists? subject required & present?
  │               subject tenant == active tenant?
  2. Policy       PolicyEngine evaluates the action's policy
  │               not Permit  -> throw PolicyDenied  (nothing written)
  ── open transaction ──────────────────────────────────────
  3. Workflow     WorkflowRuntime guard — current state allows this transition?
  4. Effect       EffectDispatcher runs the built-in effect
  5. Audit        an AuditEntry is written in the same transaction
  ── commit ────────────────────────────────────────────────
  return outputs
```

Si une étape lève une exception, la transaction est **annulée** (rollback) — l'effet et
l'entrée d'audit sont atomiques ensemble.

```php
$outputs = $invoker->invoke('billing.invoice.create', null, [
    'number'        => 'INV-2026-001',
    'customer_name' => 'ACME Corporation',
    'amount'        => ['amount' => '1500.00', 'currency' => 'USD'],
]);
```

## Construire un Invoker {#constructing-an-invoker}

```php
use Ausus\Runtime\{
    PolicyEngine, WorkflowRuntime, TransitionSetIndex,
    EffectDispatcher, DefaultAuditor, SequenceCounter, Invoker,
};

$invoker = new Invoker(
    $graph,                                              // MetadataGraph
    $driver,                                             // PersistenceDriver
    new PolicyEngine($graph),
    new WorkflowRuntime(new TransitionSetIndex($graph)),
    new EffectDispatcher(),
    new DefaultAuditor(new DatabaseAuditSink($pdo)),
    new SequenceCounter(),
    $tenant,                                             // active Tenant
    $actor,                                              // acting Actor
);
```

:::note Un tenant, un acteur par Invoker
Un `Invoker` est lié à un seul `Tenant` et un seul `Actor` à la construction.
Pour agir en tant qu'un autre tenant ou acteur, construisez un autre `Invoker`. La v0.1.0 est
délibérément mono-processus et mono-tenant par invocation — il n'y a pas de runtime
distribué ni de runtime multi-tenant.
:::

## Les pièces {#the-pieces}

### `PolicyEngine` {#policyengine}

Résout et évalue la [politique](../concepts/policies.md) d'une action.
Deny par défaut et fail-closed : `Abstain` et les exceptions levées deviennent tous deux
`Deny`.

### `WorkflowRuntime` {#workflowruntime}

Exécute le garde du [workflow](../concepts/workflows.md). Il charge le sujet,
lit son état courant, et confirme que l'action invoquée déclare une transition
depuis cet état. Aucune correspondance → `WorkflowStateMismatch`.

### `EffectDispatcher` et effets intégrés {#effectdispatcher-and-built-in-effects}

Associe le `effectClass` d'une action à une instance d'`Effect` :

| `effectClass` | Effet | Comportement |
|---|---|---|
| `kernel.builtin.create` | `CreateEffect` | insère une ligne ; applique l'état initial du workflow |
| `kernel.builtin.transition` | `TransitionEffect` | met à jour le champ d'état, horodate et applique les patchs |

Le dispatcher peut aussi instancier une classe `Effect` personnalisée par FQN, bien que
le domaine d'exemple de la v0.1.0 n'utilise que les deux intégrés.

### `DefaultAuditor` et `SequenceCounter` {#defaultauditor-and-sequencecounter}

Chaque action réussie écrit une `AuditEntry` via l'`Auditor` dans le
puits d'audit — **à l'intérieur de la transaction de l'action**. Le `SequenceCounter` attribue
un numéro de séquence monotone par identifiant de corrélation. Voir
[Persistance SQL](sql-persistence.md#the-audit-log) pour la table d'audit.

## Sémantique des transactions {#transaction-semantics}

- La transaction s'ouvre **après** l'évaluation de la politique — une action refusée n'en
  ouvre jamais.
- Le garde du workflow, l'effet et l'écriture d'audit s'exécutent tous à l'intérieur.
- Le commit n'a lieu que si les trois réussissent. Tout échec annule tout.
- Si l'effet lève une exception non-AUSUS, elle est encapsulée en `EffectFailed`.

## `ProjectionRenderer` {#projectionrenderer}

`runtime-default` fournit aussi `ProjectionRenderer`, qui rend une
[projection](../concepts/projections.md) vers un
[ViewSchema](../frontend/viewschema.md). Il ouvre sa propre transaction en lecture.

## Limites actuelles de la v0.1.0 {#current-v010-limitations}

- Mono-processus, mono-tenant, mono-acteur par `Invoker` (voir la note ci-dessus).
- Le `SequenceCounter` d'audit est **par processus** — les numéros de séquence ne sont pas
  durables après un redémarrage de processus.
- Les politiques de garde par transition ne sont pas évaluées (voir [Workflows](../concepts/workflows.md)).
- Il n'y a ni nouvel essai, ni file d'attente, ni exécution asynchrone — `invoke()` est synchrone.

## Voir aussi {#related}

- [Politiques](../concepts/policies.md) · [Workflows](../concepts/workflows.md)
- [Persistance SQL](sql-persistence.md) — le driver via lequel le runtime écrit.
- [Référence des erreurs](../reference/errors.md) — la taxonomie des exceptions.

---
id: hello-invoice
title: Tutoriel HelloInvoice
sidebar_label: Tutoriel HelloInvoice
description: Construisez et exercez un domaine de facturation complet de bout en bout.
---

# Tutoriel HelloInvoice

`HelloInvoice` est le domaine de référence livré dans `ausus/starter` et
le bac à sable du dépôt. Il s'agit d'une seule entité `invoice` dotée d'un cycle de vie
à trois états. Ce tutoriel détaille sa déclaration, puis exerce contre lui chaque
garantie du runtime.

La version exécutable se trouve dans `apps/playground/run.php` du monorepo et est
couverte par 36 assertions dans la porte de validation.

## 1. Déclarer le domaine {#1-declare-the-domain}

Un domaine dans AUSUS est un **plugin**. Voici le plugin `HelloInvoice` complet
écrit avec le [DSL](../backend/php-dsl.md) :

```php
namespace Acme\Billing;

use Ausus\{DslPlugin, Dsl, Field, Action};

final class HelloInvoiceDsl extends DslPlugin
{
    public function name(): string        { return 'billing'; }
    public function phpNamespace(): string { return 'Acme\\Billing'; }

    public function dsl(Dsl $dsl): void
    {
        $dsl->entity('invoice')
            ->fields([
                'number'        => Field::string()->unique()->max(32),
                'customer_name' => Field::string()->max(200),
                'amount'        => Field::money()->currency('USD'),
                'status'        => Field::enum('DRAFT', 'ISSUED', 'CANCELLED')->default('DRAFT'),
                'issued_at'     => Field::datetime()->nullable(),
            ])
            ->actions([
                'create' => Action::create('number', 'customer_name', 'amount')
                              ->requireRole('invoice.creator'),
                'issue'  => Action::transition('status', from: 'DRAFT', to: 'ISSUED')
                              ->stamp('issued_at')
                              ->requireRole('invoice.issuer'),
                'cancel' => Action::transition('status', from: 'DRAFT',  to: 'CANCELLED')
                              ->andTransition('status', from: 'ISSUED', to: 'CANCELLED')
                              ->requireRole('invoice.canceler'),
            ])
            ->workflow('status')
            ->projection('summary',
                fields:  ['id', 'number', 'customer_name', 'status', 'amount'],
                actions: ['create', 'cancel'],
                role:    'invoice.viewer')
            ->projection('detail',
                fields:  ['id', 'number', 'customer_name', 'status', 'amount', 'issued_at', 'created_at', 'updated_at'],
                actions: ['issue', 'cancel'],
                role:    'invoice.viewer');
    }
}
```

Ce que cela déclare :

- Une **entité**, `billing.invoice`, avec cinq champs de domaine. Le kernel ajoute
  automatiquement cinq [champs système](../concepts/entities-fields-actions.md#system-fields)
  (`id`, `tenant_id`, `_version`, `created_at`, `updated_at`).
- Trois **actions** : `create` (une action de création) plus `issue` et `cancel`
  (des actions de transition).
- Un **workflow** sur le champ enum `status` — le runtime déduit les états et
  la valeur initiale à partir du champ.
- Deux **projections** — des vues en lecture nommées `summary` et `detail`.

## 2. Compiler et démarrer {#2-compile-and-boot}

```php
use Ausus\Compiler;
use Ausus\Persistence\Sql\SchemaDeriver;

$graph = (new Compiler())->compile([new HelloInvoiceDsl()]);
echo "entities=", count($graph->entities),
     " actions=",  count($graph->actions),
     " workflows=", count($graph->workflows), "\n";
// entities=1 actions=3 workflows=1

foreach (SchemaDeriver::deriveAll($graph) as $stmt) {
    $pdo->exec($stmt);
}
```

Consultez [Votre première application](first-app.md) pour le câblage complet du runtime ; les étapes
ci-dessous supposent qu'un `$invoker` et un `$renderer` sont dans la portée.

## 3. Créer une facture {#3-create-an-invoice}

```php
$created = $invoker->invoke('billing.invoice.create', null, [
    'number'        => 'INV-2026-001',
    'customer_name' => 'ACME Corporation',
    'amount'        => ['amount' => '1500.00', 'currency' => 'USD'],
]);
```

- `$created['id']` — un ULID de 26 caractères.
- `$created['status']` — `'DRAFT'`, appliqué automatiquement à partir de la valeur par
  défaut de l'enum. L'action `create` n'a pas passé `status`.

## 4. L'émettre (une transition de workflow) {#4-issue-it-a-workflow-transition}

```php
use Ausus\Reference;

$ref = new Reference('acme', 'billing.invoice', $created['id']);
$out = $invoker->invoke('billing.invoice.issue', $ref, []);
// $out['status']    === 'ISSUED'
// $out['issued_at']  is an RFC-3339 timestamp (the ->stamp('issued_at') effect)
```

L'action `issue` est déclarée `transition('status', from: 'DRAFT', to: 'ISSUED')`.
Le runtime de workflow vérifie que la facture est actuellement en `DRAFT` avant de l'autoriser.

## 5. Voir les gardes à l'œuvre {#5-watch-the-guards-work}

Ces appels sont *censés* échouer — ils démontrent les garanties du runtime.

```php
// Issue again, from ISSUED -> rejected: no transition declared from ISSUED.
try {
    $invoker->invoke('billing.invoice.issue', $ref, []);
} catch (\Ausus\WorkflowStateMismatch $e) {
    // expected
}

// Cross-tenant reference -> rejected before any work happens.
$wrong = new Reference('other-tenant', 'billing.invoice', $created['id']);
try {
    $invoker->invoke('billing.invoice.issue', $wrong, []);
} catch (\Ausus\TenantBoundaryViolation $e) {
    // expected
}
```

Puis une transition légale — `cancel` est déclarée à la fois depuis `DRAFT` et
`ISSUED` :

```php
$out = $invoker->invoke('billing.invoice.cancel', $ref, []);
// $out['status'] === 'CANCELLED'
```

## 6. Concurrence optimiste {#6-optimistic-concurrency}

Chaque ligne porte un ULID `_version`. Une `update` avec une version périmée est
rejetée :

```php
$repo    = $driver->context($tenant, $driver->beginTransaction($tenant))
                  ->repository('billing.invoice');
$current = $repo->find($ref);
$stale   = $current->version;

$repo->update($ref, ['customer_name' => 'New Name'], $stale);   // ok — bumps _version
$repo->update($ref, ['customer_name' => 'Bad Name'], $stale);   // throws ConcurrencyConflict
```

## 7. Rendre une projection {#7-render-a-projection}

```php
$summary = $renderer->render('billing.invoice.summary');
// $summary['schemaVersion']  === '1.0.0'
// $summary['fields']          -> 5 field descriptors
// $summary['actions']         -> 2 action descriptors (create, cancel)
// $summary['data']['items']   -> the invoices for this tenant
```

Ce [ViewSchema](../frontend/viewschema.md) est exactement ce que l'API HTTP
retourne et ce que le [moteur de rendu React](../frontend/react-renderer.md) dessine.

## Ce que HelloInvoice démontre {#what-helloinvoice-proves}

L'exécution complète des exercices du bac à sable couvre, dans l'ordre : l'aller-retour de
persistance, l'application de la valeur par défaut d'enum, les transitions de workflow, le rejet
de workflow, l'isolation des tenants, le verrouillage optimiste, l'émission de la piste d'audit
et le rendu de projection — et prouve aussi que le **plugin DSL et un plugin équivalent écrit
à la main compilent vers un hash de graphe identique octet pour octet**.

## Limites actuelles de la v0.1.0 {#current-v010-limitations}

- Le rendu de la **liste** de projection lit les lignes du tenant courant sans aucun
  filtrage ni véritable pagination — `pagination.nextCursor` est toujours `null`.
- Il n'existe pas de type d'action `delete` ni de validation d'entrée riche au-delà
  de la présence des champs et des types déclarés.
- `cancel` utilise `andTransition()` pour déclarer deux sources explicites ; les transitions
  avec joker (`from: '*'`) sont prises en charge par le runtime mais ne sont pas utilisées ici.

## Étapes suivantes {#next}

- [Concepts fondamentaux](../concepts/metadata-graph.md) — le modèle derrière tout cela.
- [Le DSL PHP](../backend/php-dsl.md) — chaque méthode du builder.

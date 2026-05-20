---
id: plugins
title: Plugins
sidebar_label: Plugins
description: L'unité de code de domaine dans AUSUS.
---

# Plugins

Un **plugin** est l'unité de code de domaine dans AUSUS. C'est une classe PHP
qui décrit une portion d'une application — ses entités, actions, politiques,
workflows et projections. Le [compilateur](metadata-graph.md) transforme un ou
plusieurs plugins en un `MetadataGraph`.

AUSUS est « plugin-first » : vous n'écrivez pas de contrôleurs ni de modèles,
vous écrivez un plugin et laissez le framework dériver le reste.

## Le contrat `Plugin` {#the-plugin-contract}

Le contrat de bas niveau (`ausus/kernel`) est minimal :

```php
interface Plugin
{
    public function name(): string;        // e.g. 'billing'
    public function phpNamespace(): string; // e.g. 'Acme\\Billing'
    public function describe(): array;       // normalized descriptor arrays
}
```

- `name()` est le nom court du plugin. Il préfixe chaque FQN produit par le
  plugin — l'entité `invoice` dans le plugin `billing` devient `billing.invoice`.
- `phpNamespace()` déclare le namespace PHP sous lequel vivent les classes de
  domaine du plugin.
- `describe()` retourne les tableaux de descripteurs consommés par le
  compilateur.

## Écrire des plugins avec le DSL {#writing-plugins-with-the-dsl}

Vous implémentez rarement `Plugin` directement. À la place, étendez `DslPlugin`
et implémentez `dsl()` — le DSL construit les tableaux de descripteurs pour vous :

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
            ->fields([ /* ... */ ])
            ->actions([ /* ... */ ])
            ->workflow('status')
            ->projection('summary', fields: [/* ... */]);
    }
}
```

`DslPlugin::describe()` est implémenté pour vous — il exécute votre méthode
`dsl()` contre un builder `Dsl` neuf et émet les tableaux de descripteurs. La
surface complète du builder est documentée dans [Le DSL PHP](../backend/php-dsl.md).

## Composer plusieurs plugins {#composing-multiple-plugins}

`Compiler::compile()` prend un tableau. Les plugins se composent par déclaration
— le compilateur fusionne leurs nœuds en un seul graphe :

```php
$graph = (new Compiler())->compile([
    new BillingPlugin(),
    new CrmPlugin(),
]);
```

Si deux plugins déclarent le même FQN d'action, le compilateur lève une erreur
**DuplicateRegistration**. Les entités et les politiques portant le même FQN
sont fusionnées (le dernier l'emporte) ; les actions sont strictes.

## Limites actuelles de la v0.1.0 {#current-v010-limitations}

- **Les références inter-plugins ne sont pas résolues de manière spéciale.**
  L'action d'un plugin ne peut référencer l'entité d'un autre plugin que si les
  deux sont compilés ensemble et que les FQN correspondent ; il n'existe aucun
  mécanisme d'import ou de dépendance entre plugins.
- La surface du DSL est le sous-ensemble minimal RFC-011. Les classes de
  politique et d'effet résolues par convention, la visibilité au niveau du
  champ et les diagnostics du DSL avec attribution fichier/ligne sont
  **reportés** — voir [Le DSL PHP](../backend/php-dsl.md).
- Il n'existe ni découverte ni registre de plugins. Vous passez les instances de
  plugin à `compile()` explicitement.

## Voir aussi {#related}

- [Le graphe de métadonnées](metadata-graph.md) — ce vers quoi les plugins compilent.
- [Le DSL PHP](../backend/php-dsl.md) — la référence complète du builder.
- [Entités, champs et actions](entities-fields-actions.md) — ce que déclare un plugin.

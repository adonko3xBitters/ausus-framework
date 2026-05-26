---
id: domain
title: 'Partie 2 — Le domaine'
sidebar_label: 2. Le domaine
description: Amorcer l'Application et déclarer l'entité ticket, ses champs, ses actions et son workflow.
---

# Partie 2 — Le domaine

**Pourquoi cette étape existe :** dans AUSUS, vous n'écrivez pas de modèles, de
contrôleurs ni de migrations. Vous écrivez un **plugin** — une classe unique qui
*décrit* votre domaine. Le framework compile cette description et l'exécute. Cette
partie écrit l'intégralité du domaine du système de tickets dans un seul fichier.

Cette partie couvre cinq choses à la fois, parce qu'elles vivent toutes dans le même
fichier : amorcer l'`Application`, créer l'entité, ajouter des champs, ajouter
des actions, et déclarer le workflow.

## 2.1 L'Application et le plugin {#21-the-application-and-the-plugin}

`Ausus\Application` est l'objet qui exécute votre domaine. Son cycle de vie tient en
quatre appels :

```php
$app = Application::create([...])   // configure tenant, actor, database
    ->register(new TicketSystem())  // hand it your plugin(s)
    ->boot();                       // compile + wire the runtime
```

`register()` prend un **plugin**. Un plugin est une classe quelconque qui étend
`Ausus\DslPlugin` et qui implémente trois méthodes : `name()`, `phpNamespace()`
et `dsl()`. La méthode `dsl()` est l'endroit où le domaine est décrit.

Créez le fichier `src/TicketSystem.php` avec le squelette vide :

```php
<?php
declare(strict_types=1);

namespace Helpdesk;

use Ausus\{DslPlugin, Dsl, Field, Action};

final class TicketSystem extends DslPlugin
{
    /** The plugin name — becomes the first segment of every FQN. */
    public function name(): string { return 'helpdesk'; }

    /** The PHP namespace this plugin's code lives in. */
    public function phpNamespace(): string { return 'Helpdesk'; }

    /** Describe the domain. AUSUS calls this when the Application boots. */
    public function dsl(Dsl $dsl): void
    {
        // entity, fields, actions and workflow go here
    }
}
```

**Pourquoi `name()` est important :** c'est le premier segment de chaque *nom
pleinement qualifié* (FQN). Avec `name()` qui renvoie `helpdesk`, l'entité que vous
créez ensuite est `helpdesk.ticket` et son action de création est `helpdesk.ticket.create`.
Les FQN sont la manière dont chaque couche — runtime, HTTP, renderer — fait référence
à votre domaine.

Le reste de cette partie remplit le corps de `dsl()`.

## 2.2 Créer l'entité {#22-create-the-entity}

Une **entité** est un type d'enregistrement que votre application stocke. Le système
de tickets en a exactement une : un ticket.

```php
$dsl->entity('ticket')
    // ->fields([...])  — next
;
```

`entity('ticket')` déclare une entité avec le FQN `helpdesk.ticket`. Elle
renvoie un `EntityBuilder` sur lequel vous continuez à chaîner des appels. **Pourquoi un
builder :** les champs, les actions et le workflow sont tous déclarés en chaînant à
partir de cet unique appel, de sorte que toute l'entité se lit comme une seule
expression.

## 2.3 Ajouter des champs {#23-add-fields}

Les **champs** sont les colonnes de l'entité. Vous déclarez chacun avec le
builder `Field`. Le système de tickets en a besoin de cinq :

```php
->fields([
    'title'     => Field::string()->max(200),
    'requester'   => Field::string()->max(120),
    'priority'    => Field::enum('LOW', 'NORMAL', 'HIGH')->default('NORMAL'),
    'status'      => Field::enum('OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED')->default('OPEN'),
    'resolved_at' => Field::datetime()->nullable(),
])
```

Chaque ligne, et **pourquoi** :

| Champ | Builder | Pourquoi |
|---|---|---|
| `title` | `Field::string()->max(200)` | Le titre du ticket. `max()` plafonne la longueur. |
| `requester` | `Field::string()->max(120)` | Qui l'a signalé. |
| `priority` | `Field::enum(...)->default('NORMAL')` | Un choix fixe. `default()` est utilisé lorsqu'aucune valeur n'est fournie. |
| `status` | `Field::enum(...)->default('OPEN')` | L'état du cycle de vie — le workflow sera attaché à ce champ. |
| `resolved_at` | `Field::datetime()->nullable()` | Renseigné uniquement quand le ticket est résolu, il doit donc être `nullable()`. |

**Pourquoi vous ne déclarez pas d'`id`, d'horodatages ni de colonne tenant :** AUSUS
ajoute automatiquement cinq **champs système** à chaque entité — `id`, `tenant_id`,
`_version`, `created_at`, `updated_at`. Vous ne les gérez jamais à la main.

Les types de champs disponibles en v0.1.x sont `string`, `integer`, `datetime`,
`money` et `enum`. Les méthodes du builder incluent `->max()`, `->nullable()`,
`->default()`, `->unique()` et `->currency()` (pour money).

## 2.4 Ajouter des actions {#24-add-actions}

Les enregistrements ne changent pas d'eux-mêmes. Chaque changement passe par une
**action**. Il en existe deux sortes, et le système de tickets utilise les deux.

Une **action de création** fait naître un nouvel enregistrement :

```php
'create' => Action::create('title', 'requester', 'priority')
                ->requireRole('ticket.agent'),
```

Les arguments d'`Action::create()` sont les noms des champs que l'appelant doit
fournir. `status` n'est pas listé — le workflow l'initialise. `resolved_at` n'est pas
listé — il est vide jusqu'à ce que le ticket soit résolu.

Une **action de transition** déplace un enregistrement d'un état à un autre :

```php
'start'   => Action::transition('status', from: 'OPEN', to: 'IN_PROGRESS')
                ->requireRole('ticket.agent'),
'resolve' => Action::transition('status', from: 'IN_PROGRESS', to: 'RESOLVED')
                ->stamp('resolved_at')
                ->requireRole('ticket.agent'),
'close'   => Action::transition('status', from: 'RESOLVED', to: 'CLOSED')
                ->requireRole('ticket.agent'),
```

`transition('status', from:, to:)` dit « cette action change `status` d'une
valeur à une autre. » Remarques, avec le **pourquoi** :

- **`->stamp('resolved_at')`** sur `resolve` écrit l'horodatage courant dans
  `resolved_at` dans le cadre de la transition. C'est pour cela que le champ existe.
- **`->requireRole('ticket.agent')`** attache une policy : seul un acteur détenant
  le rôle `ticket.agent` peut invoquer l'action. Sans un rôle correspondant, le
  runtime rejette l'appel avant qu'aucun travail ne soit effectué.

Mis bout à bout, le bloc `->actions([...])` est :

```php
->actions([
    'create'  => Action::create('title', 'requester', 'priority')
                    ->requireRole('ticket.agent'),
    'start'   => Action::transition('status', from: 'OPEN', to: 'IN_PROGRESS')
                    ->requireRole('ticket.agent'),
    'resolve' => Action::transition('status', from: 'IN_PROGRESS', to: 'RESOLVED')
                    ->stamp('resolved_at')
                    ->requireRole('ticket.agent'),
    'close'   => Action::transition('status', from: 'RESOLVED', to: 'CLOSED')
                    ->requireRole('ticket.agent'),
])
```

## 2.5 Déclarer le workflow {#25-declare-the-workflow}

Les actions de transition décrivent des mouvements individuels. Le **workflow** les
relie en une seule machine à états et indique à AUSUS où démarre un nouveau ticket :

![Machine à états du workflow ticket : un nouveau ticket démarre en OPEN ; start le passe à IN_PROGRESS ; resolve le passe à RESOLVED et estampille resolved_at ; close le passe à CLOSED.](/img/diagrams/ticket-workflow.svg)

Le runtime applique ce diagramme : appeler `start` sur un ticket `RESOLVED`,
ou `resolve` sur un ticket `OPEN`, lève `WorkflowStateMismatch` avant la
moindre écriture.

```php
->workflow(field: 'status', initial: 'OPEN')
```

- **`field: 'status'`** — le champ enum qui contient l'état. Ses options
  (`OPEN`, `IN_PROGRESS`, `RESOLVED`, `CLOSED`) deviennent les états du workflow.
- **`initial: 'OPEN'`** — l'état dans lequel démarre un ticket fraîchement créé.

**Pourquoi le déclarer explicitement :** le workflow est ce qui fait que le runtime
*protège* les transitions. Avec le workflow en place, appeler `resolve` sur un ticket
`OPEN` échoue avec un `WorkflowStateMismatch` — il n'y a pas de transition
`OPEN → RESOLVED`. Passez toujours `initial` explicitement ; l'omettre déclenche un
avis de dépréciation.

Enfin, ajoutez deux **projections** — des vues nommées, en forme de lecture, de
l'entité que l'API HTTP et l'interface React consomment :

```php
->projection('summary',
    fields:  ['id', 'title', 'requester', 'priority', 'status'],
    actions: ['create', 'start', 'resolve', 'close'],
    role:    'ticket.viewer')
->projection('detail',
    fields:  ['id', 'title', 'requester', 'priority', 'status', 'resolved_at', 'created_at', 'updated_at'],
    actions: ['start', 'resolve', 'close'],
    role:    'ticket.viewer');
```

`summary` est une vue de liste ; `detail` est une vue d'enregistrement unique. La
liste `fields` choisit quelles colonnes apparaissent — y compris les champs système
comme `id` et `created_at`.

## Le plugin complet {#the-complete-plugin}

Voici `src/TicketSystem.php` en entier. C'est l'intégralité du domaine — chaque
partie ultérieure du tutoriel ne fait que l'exécuter :

```php
<?php
declare(strict_types=1);

namespace Helpdesk;

use Ausus\{DslPlugin, Dsl, Field, Action};

/**
 * TicketSystem — the AUSUS tutorial domain.
 *
 * One entity (helpdesk.ticket) with a four-state lifecycle:
 *   OPEN → IN_PROGRESS → RESOLVED → CLOSED
 */
final class TicketSystem extends DslPlugin
{
    public function name(): string         { return 'helpdesk'; }
    public function phpNamespace(): string { return 'Helpdesk'; }

    public function dsl(Dsl $dsl): void
    {
        $dsl->entity('ticket')
            ->fields([
                'title'     => Field::string()->max(200),
                'requester'   => Field::string()->max(120),
                'priority'    => Field::enum('LOW', 'NORMAL', 'HIGH')->default('NORMAL'),
                'status'      => Field::enum('OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED')->default('OPEN'),
                'resolved_at' => Field::datetime()->nullable(),
            ])
            ->actions([
                'create'  => Action::create('title', 'requester', 'priority')
                                ->requireRole('ticket.agent'),
                'start'   => Action::transition('status', from: 'OPEN', to: 'IN_PROGRESS')
                                ->requireRole('ticket.agent'),
                'resolve' => Action::transition('status', from: 'IN_PROGRESS', to: 'RESOLVED')
                                ->stamp('resolved_at')
                                ->requireRole('ticket.agent'),
                'close'   => Action::transition('status', from: 'RESOLVED', to: 'CLOSED')
                                ->requireRole('ticket.agent'),
            ])
            ->workflow(field: 'status', initial: 'OPEN')
            ->projection('summary',
                fields:  ['id', 'title', 'requester', 'priority', 'status'],
                actions: ['create', 'start', 'resolve', 'close'],
                role:    'ticket.viewer')
            ->projection('detail',
                fields:  ['id', 'title', 'requester', 'priority', 'status', 'resolved_at', 'created_at', 'updated_at'],
                actions: ['start', 'resolve', 'close'],
                role:    'ticket.viewer');
    }
}
```

## Vérifier qu'il compile {#verify-it-compiles}

Avant de persister quoi que ce soit, confirmez que le plugin compile proprement. Créez
`compile-check.php` à la racine du projet :

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Ausus\Application;
use Helpdesk\TicketSystem;

// boot() compiles the plugin into a MetadataGraph and wires the runtime.
$app = Application::create([
    'tenant' => 'helpdesk',
    'roles'  => ['ticket.agent', 'ticket.viewer'],
])->register(new TicketSystem())->boot();

$graph = $app->graph();
echo 'graph hash : ', substr($graph->hash, 0, 16), "…\n";
echo 'entities   : ', count($graph->entities), "\n";
echo 'actions    : ', count($graph->actions), "\n";
echo 'workflows  : ', count($graph->workflows), "\n";
echo "plugin compiles cleanly.\n";
```

Exécutez-le :

```bash
php compile-check.php
```

Sortie attendue :

```
graph hash : 7c1e9b3a0f4d2e88…
entities   : 1
actions    : 4
workflows  : 1
plugin compiles cleanly.
```

Le hash exact différera ; les **comptes** doivent correspondre. Si vous voyez plutôt
une `RuntimeException`, lisez son message — le DSL valide votre déclaration et
nomme le problème (un champ mal orthographié, un mauvais état de workflow, …).
Vous pouvez supprimer `compile-check.php` une fois qu'il passe.

## Ce que vous avez maintenant {#what-you-have-now}

```
ticket-system/
├── composer.json
├── src/
│   └── TicketSystem.php   ← tout le domaine
└── vendor/
```

Le domaine existe, mais aucune donnée n'existe. Dans la partie suivante, vous donnez
une base de données à l'application.

**Suivant : [Partie 3 — Persistance](persistence.md).**

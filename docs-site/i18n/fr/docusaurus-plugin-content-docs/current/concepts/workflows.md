---
id: workflows
title: Workflows
sidebar_label: Workflows
description: Machines à états attachées à une entité dans AUSUS.
---

# Workflows

Un **workflow** est une machine à états attachée à une entité. Il déclare les
états légaux d'un enregistrement et quelles
[actions de transition](entities-fields-actions.md#transition-actions) le
déplacent entre eux.

## Comment un workflow est déclaré {#how-a-workflow-is-declared}

Un workflow est **inféré**, et non écrit à la main. Vous pointez l'entité vers
un champ `enum`, et les options de ce champ deviennent les états du workflow :

```php
$dsl->entity('invoice')
    ->fields([
        'status' => Field::enum('DRAFT', 'ISSUED', 'CANCELLED')->default('DRAFT'),
    ])
    ->actions([
        'issue'  => Action::transition('status', from: 'DRAFT', to: 'ISSUED'),
        'cancel' => Action::transition('status', from: 'DRAFT',  to: 'CANCELLED')
                      ->andTransition('status', from: 'ISSUED', to: 'CANCELLED'),
    ])
    ->workflow('status');     // <- the status enum drives the workflow
```

À partir de cela, le compilateur construit un `WorkflowNode` :

- **states** — `DRAFT`, `ISSUED`, `CANCELLED` (les options de l'enum).
- **initial** — `DRAFT` (la valeur par défaut de l'enum).
- **stateField** — `status`.
- **transitions** — un `TransitionNode` par paire `(from, to)` déclarée par une
  action de transition, chacun étiqueté avec l'action qui la réalise.

Le FQN du workflow est `{entity}.lifecycle`, par exemple
`billing.invoice.lifecycle`.

## Comment une transition est appliquée {#how-a-transition-is-enforced}

Lorsque vous invoquez une action de transition, le
[runtime](../backend/runtime.md) exécute une **garde de workflow** avant
l'effet :

1. L'enregistrement courant est chargé.
2. Son état courant (la valeur de `status`) est lu.
3. Le runtime trouve la transition de l'action invoquée dont le `source`
   correspond à l'état courant — soit une correspondance exacte, soit un joker
   (`*`).
4. Si aucune transition ne correspond, il lève `WorkflowStateMismatch` et toute
   l'invocation est annulée.

Ainsi, `issue` ne fonctionne que sur une facture `DRAFT` ; l'appeler sur une
facture `ISSUED` est rejeté. `cancel`, déclarée à la fois depuis `DRAFT` et
`ISSUED`, fonctionne sur l'une ou l'autre.

### Transitions joker {#wildcard-transitions}

La source d'une transition peut être `*`, ce qui signifie « depuis n'importe
quel état ». Le runtime considère qu'un joker correspond à l'état courant
lorsqu'aucune source exacte ne correspond.

:::warning Une transition par état
Pour un workflow donné et un état courant donné, **exactement une** transition
peut correspondre à une action. Si deux transitions déclarées correspondent
toutes deux (par exemple une source exacte et un joker qui se chevauchent), le
runtime lève une erreur de transition ambiguë. Déclarez les transitions de
sorte qu'au plus une s'applique par état.
:::

## Workflows multiples {#multiple-workflows}

Une action peut piloter des transitions sur plus d'un workflow. Lorsque cela se
produit, chaque workflow attaché doit avoir exactement une transition
correspondante pour l'état courant. Dans le domaine d'exemple de la v0.1.0,
chaque entité possède un seul workflow.

## Limites actuelles de la v0.1.0 {#current-v010-limitations}

- Les workflows sont inférés à partir d'un seul champ enum — il n'existe aucune
  syntaxe de déclaration de workflow autonome.
- **Les politiques de garde de transition ne sont pas évaluées en v0.1.0.**
  L'autorisation est appliquée par la [politique](policies.md) de l'action ;
  une politique de garde par transition fait partie de la conception mais
  n'est pas exécutée par le runtime de workflow de la v0.1.0.
- L'état initial est la valeur `default` de l'enum, ou la première option si
  aucune valeur par défaut n'est définie.

## Voir aussi {#related}

- [Entités, champs et actions](entities-fields-actions.md) — les actions de transition.
- [Le runtime](../backend/runtime.md) — où s'exécute la garde de workflow.
- [Politiques](policies.md) — l'autorisation des actions.

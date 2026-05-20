---
id: policies
title: Politiques
sidebar_label: Politiques
description: Décisions d'autorisation pour les actions dans AUSUS.
---

# Politiques

Une **politique** décide si un [acteur](#actors) peut invoquer une action.
Chaque action du graphe possède exactement une politique. Le
[runtime](../backend/runtime.md) l'évalue avant que la moindre donnée ne soit
touchée.

## Le contrat `Policy` {#the-policy-contract}

```php
interface Policy
{
    public function evaluate(
        Actor $actor,
        string $actionFqn,
        ?Subject $subject,
        Context $context,
    ): Decision;
}
```

Une politique retourne une valeur de l'enum `Decision` :

| Decision | Signification |
|---|---|
| `Permit` | l'action est autorisée |
| `Deny` | l'action est refusée |
| `Abstain` | la politique ne prend aucune décision |

## Refus par défaut, défaillance en mode fermé {#deny-by-default-fail-closed}

Le `PolicyEngine` applique deux règles de sécurité lorsqu'il évalue une
politique :

- **Abstain devient Deny.** Si une politique retourne `Abstain`, le moteur la
  traite comme `Deny`. Il n'y a pas de « autoriser parce que rien ne s'y est
  opposé ».
- **Une exception devient Deny.** Si une politique lève une exception, le moteur
  l'intercepte et retourne `Deny`. Une politique défectueuse défaille en mode
  *fermé*, jamais ouvert.

Si la décision est autre chose que `Permit`, le runtime lève `PolicyDenied` et
l'invocation s'arrête avant l'ouverture de la transaction.

## La politique intégrée : `RoleRequired` {#the-built-in-policy-rolerequired}

La v0.1.0 livre une seule implémentation de politique : `RoleRequired`. Elle
autorise l'action si l'acteur détient un rôle nommé.

Vous l'attachez via le DSL avec `->requireRole()` :

```php
'issue' => Action::transition('status', from: 'DRAFT', to: 'ISSUED')
             ->requireRole('invoice.issuer'),
```

Le compilateur crée un `PolicyNode` pour l'action qui construit une politique
`RoleRequired` avec `role: 'invoice.issuer'`. Au moment de l'invocation, le
moteur vérifie le rôle par rapport à la liste de rôles de l'acteur.

## Acteurs {#actors}

Un **acteur** est celui qui réalise l'action. Le contrat `Actor` expose une
référence, une liste de rôles, une liste de permissions et un `roleHash()`
canonique.

La v0.1.0 livre `StubActor` — un acteur fixe en mémoire :

```php
use Ausus\{StubActor, ActorRef};

$actor = new StubActor(
    new ActorRef('user', 'user42', 'acme'),
    ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'],
);
```

L'API HTTP construit un `StubActor` à partir des en-têtes de la requête
(`X-Actor-Id`, `X-Actor-Roles`) — voir [L'API HTTP](../backend/http-api.md).

## Limites actuelles de la v0.1.0 {#current-v010-limitations}

- `RoleRequired` est la **seule** implémentation de politique. Il n'existe ni
  politique basée sur les attributs, ni politique basée sur les permissions, ni
  combinaison de politiques (all-of / any-of) en v0.1.0.
- Il n'y a **aucune authentification**. `StubActor` est une identité de
  confiance, fournie par l'appelant. Tout ce qui expose le runtime — y compris
  l'API HTTP — doit placer une véritable couche d'authentification devant lui.
  Le paquet réservé `ausus/auth-bridge` est l'emplacement prévu pour cela et ne
  livre aucun code en v0.1.0.
- Les politiques de visibilité au niveau du champ et de la projection sont
  conçues mais ne sont pas appliquées en v0.1.0.

## Voir aussi {#related}

- [Le runtime](../backend/runtime.md) — où s'exécute le contrôle de politique.
- [Entités, champs et actions](entities-fields-actions.md) — les actions portent des politiques.
- [Référence des erreurs](../reference/errors.md) — `PolicyDenied` et les erreurs associées.

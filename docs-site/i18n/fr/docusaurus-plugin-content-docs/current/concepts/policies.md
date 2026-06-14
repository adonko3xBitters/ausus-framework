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

AUSUS livre `RoleRequired` pour l'autorisation **basée sur les rôles**. Elle
autorise l'action si l'acteur détient un rôle nommé.

Vous l'attachez via le DSL avec `->requireRole()` :

```php
'issue' => Action::transition('status', from: 'DRAFT', to: 'ISSUED')
             ->requireRole('invoice.issuer'),
```

Le compilateur crée un `PolicyNode` pour l'action qui construit une politique
`RoleRequired` avec `role: 'invoice.issuer'`. Au moment de l'invocation, le
moteur vérifie le rôle par rapport à la liste de rôles de l'acteur.

## Autorisation dépendante des données (RFC-018) {#data-dependent-authorization}

Les politiques basées sur les rôles décident sur la seule identité. **RFC-018**
ajoute des *gardes* qui lisent le **sujet** (l'enregistrement) et des **attributs
d'acteur structurés** au moment de l'autorisation — une règle comme « un
gestionnaire ne peut approuver un sinistre que jusqu'à sa limite d'autorité »
s'exprime alors en configuration, et non en code applicatif.

Un garde s'attache à une action avec `->requireThat(Cond)`, en complément (et non
à la place) de `->requireRole()` :

```php
$dsl->actorAttributes(['authority_limit' => Field::integer()]);

'approve' => Action::transition('status', from: 'ASSESSING', to: 'APPROVED')
    ->requireRole('claims.adjuster')
    ->requireThat(Cond::lte(Fact::subject('claim_amount'), Fact::actor('authority_limit'))),
```

- **Facts.** `Fact::subject(field)` lit un champ de l'entité sujet chargée ;
  `Fact::actor(attribute)` lit un attribut d'acteur structuré (déclaré avec
  `Dsl::actorAttributes(...)`) ; `Fact::input(key)` lit une entrée d'action.
- **Conditions.** `Cond::eq / ne / lt / lte / gt / gte / in`, composées avec
  `Cond::and / or / not`.
- **Fermeture à la compilation.** Un garde référençant un champ sujet, un attribut
  d'acteur ou une entrée inconnus est rejeté à la compilation du graphe
  (`DanglingFactReference`).
- **Mode fermé, dans la transaction.** Le sujet est chargé avant l'autorisation
  et le garde est évalué **à l'intérieur de la transaction de l'action**, avant
  tout effet. Un garde en échec lève `PolicyDenied` (HTTP `403`) et annule la
  transaction.

Les gardes ne modifient pas le contrat `Policy` ci-dessus — ils constituent un
mécanisme d'autorisation additionnel sur la même action. Les attributs d'acteur
sont injectés via `ApplicationConfig::actorAttributes(...)` et, en HTTP, un
en-tête `X-Actor-Attributes` analysé en mode sûr.

## Acteurs {#actors}

Un **acteur** est celui qui réalise l'action. Le contrat `Actor` expose une
référence, une liste de rôles, une liste de permissions et un `roleHash()`
canonique.

AUSUS livre `StubActor` — un acteur fixe en mémoire :

```php
use Ausus\{StubActor, ActorRef};

$actor = new StubActor(
    new ActorRef('user', 'user42', 'acme'),
    ['invoice.creator', 'invoice.issuer', 'invoice.canceler', 'invoice.viewer'],
);
```

L'API HTTP construit un `StubActor` à partir des en-têtes de la requête
(`X-Actor-Id`, `X-Actor-Roles`) — voir [L'API HTTP](../backend/http-api.md).

## Limites actuelles {#current-v010-limitations}

- L'autorisation est **basée sur les rôles** (`RoleRequired`) et, depuis RFC-018,
  **dépendante des données** (gardes `requireThat`, ci-dessus). Il n'existe pas de
  classe de politique distincte basée sur les permissions.
- Il n'y a **aucune authentification**. `StubActor` est une identité de
  confiance, fournie par l'appelant. Tout ce qui expose le runtime — y compris
  l'API HTTP — doit placer une véritable couche d'authentification devant lui.
  Le paquet réservé `ausus/auth-bridge` est l'emplacement prévu pour cela et ne
  livre aucun code.
- Les politiques de visibilité au niveau du champ et de la projection sont
  conçues mais ne sont pas appliquées.

## Voir aussi {#related}

- [Le runtime](../backend/runtime.md) — où s'exécute le contrôle de politique.
- [Entités, champs et actions](entities-fields-actions.md) — les actions portent des politiques.
- [Référence des erreurs](../reference/errors.md) — `PolicyDenied` et les erreurs associées.

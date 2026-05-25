---
id: troubleshooting
title: 'Partie 6 — Dépannage et récapitulatif'
sidebar_label: 6. Dépannage et récap.
description: Erreurs courantes, conseils de débogage et récapitulatif final de l'architecture du système de tickets.
---

# Partie 6 — Dépannage et récapitulatif

**Pourquoi cette étape :** construire l'application est une chose ; comprendre
ce qui casse et pourquoi, c'est ce qui vous rend productif. Cette partie
rassemble les erreurs que vous rencontrerez le plus, comment les déboguer, et
un récapitulatif de l'ensemble du système.

## Erreurs courantes {#common-mistakes}

Les exceptions AUSUS sont **nommées précisément** — le nom de classe est le
diagnostic. Voici celles qu'un débutant rencontre le plus souvent.

### `FieldRequired` {#fieldrequired}

```
FieldRequired: helpdesk.ticket.title not in payload and no default
```

Un `create` a été invoqué sans valeur pour un champ non nullable et sans
défaut. **Correction :** inclure tout champ requis dans `inputs`, ou donner
au champ un `->default(...)` ou `->nullable()`. C'est aussi pour cela que le
bouton **Create** du moteur ne fonctionne pas en v0.1.0 — voir
[la limitation ci-dessous](#remaining-documentation-gaps).

### `PolicyDenied` {#policydenied}

```
PolicyDenied: helpdesk.ticket.start
```

L'acteur ne possède pas le rôle requis par l'action (`ticket.agent`).
**En CLI :** passez le rôle dans `Application::create(['roles' => [...]])`.
**En HTTP :** envoyez l'en-tête `X-Actor-Roles`. Si vous l'omettez, le
Router v0.1.0 utilise un jeu de rôles de démonstration qui n'inclut pas
`ticket.agent`.

### `WorkflowStateMismatch` {#workflowstatemismatch}

```
WorkflowStateMismatch: current state 'OPEN' does not match any declared source
```

Vous avez invoqué une transition qui n'est pas légale depuis l'état actuel
de l'enregistrement — par exemple `resolve` sur un ticket `OPEN`.
**C'est la garde de workflow qui fait son travail.** Corrigez l'ordre des
appels, ou ajoutez la transition attendue.

### `BadRequest: missing required X-Tenant-ID header` {#missing-tenant}

Une requête `projections/*` ou `actions/*` est arrivée sans `X-Tenant-ID`.
**Correction :** envoyer `-H 'X-Tenant-ID: helpdesk'`. Le moteur React
l'envoie pour vous depuis la prop `tenant` d'`AususProvider`.

### `TenantBoundaryViolation` {#tenantboundaryviolation}

La référence `subject` nomme un tenant différent de celui de la requête.
**Correction :** le `tenantId` d'une référence sujet doit correspondre à
`X-Tenant-ID` (`helpdesk` dans tout ce tutoriel).

### `AmbiguousWorkflowField` {#ambiguousworkflowfield}

```
AmbiguousWorkflowField: entity 'helpdesk.ticket' has multiple enum fields
with a default (priority, status)
```

L'entité a deux champs `enum` avec des valeurs par défaut et **aucun appel
à `->workflow()`** ; AUSUS ne peut donc pas deviner lequel est le cycle de
vie. **Correction :** déclarez le workflow explicitement —
`->workflow(field: 'status', initial: 'OPEN')` — comme le fait ce tutoriel.
(Si vous supprimiez cette ligne, c'est l'erreur que vous verriez.)

### `ProjectionNotFound` / `ActionNotFound` {#fqn-typos}

Un `404` avec l'un de ces `kind` signifie que le FQN dans l'URL est faux.
Les FQN sont sensibles à la casse et entièrement qualifiés : c'est
`helpdesk.ticket.summary`, pas `ticket.summary` ni `summary`.

### Classe introuvable {#class-not-found}

```
Error: Class "Helpdesk\TicketSystem" not found
```

L'autoloader Composer n'a pas indexé la classe. **Correction :** vérifiez
que le fichier est `src/TicketSystem.php`, que le namespace est `Helpdesk`,
que la règle PSR-4 est dans `composer.json`, puis lancez
`composer dump-autoload`.

### SQLite ne peut pas ouvrir la base {#sqlite-open}

`unable to open database file` signifie que le **dossier** du chemin
`database` n'existe pas — SQLite crée des fichiers, pas des dossiers.
**Correction :** utiliser un chemin dont le dossier existe (ce tutoriel
garde `tickets.sqlite` à la racine du projet).

## Conseils de débogage {#debugging-tips}

Une courte liste quand quelque chose ne va pas :

1. **Lisez la classe d'exception, pas seulement le message.**
   `WorkflowStateMismatch`, `PolicyDenied`, `ConcurrencyConflict` pointent
   chacune vers une couche précise.
2. **Isolez en CLI.** Si le navigateur se comporte mal, reproduisez en
   petit script PHP avec `$app->invoke(...)`. Cela retire HTTP et React de
   l'équation et vous dit si le bug est dans le domaine.
3. **Inspectez la ViewSchema directement.** Avant d'incriminer le moteur,
   lancez
   `curl -H 'X-Tenant-ID: helpdesk' …/projections/helpdesk.ticket.summary`
   et lisez le JSON. Si la donnée est fausse là, le problème est côté serveur.
4. **Vérifiez le hash du graphe.** `GET /api/_health` renvoie `graphHash`.
   S'il ne change pas après modification du plugin, le serveur tourne sur
   du code obsolète — redémarrez `php -S`.
5. **Regardez la base de données.**
   `sqlite3 tickets.sqlite 'SELECT * FROM helpdesk_ticket;'` affiche les
   lignes réelles.
   `SELECT action_fqn, timestamp FROM kernel_audit_log;` montre chaque
   action exécutée — la piste d'audit est votre journal d'événements.
6. **Surveillez les notices de dépréciation.** Une ligne contenant
   `AUSUS deprecation:` au `boot()` signifie que vous reposez sur un
   fallback historique (par ex. un workflow implicite). Ça marche encore,
   mais corrigez-le avant que cela devienne une erreur.
7. **Lisez le terminal du serveur.** `php -S` affiche les erreurs PHP et
   les piles d'appel dans le terminal où il tourne — gardez cette fenêtre
   visible.

## Récapitulatif de l'architecture {#architecture-recap}

Vous avez écrit **un seul** fichier de code domaine —
`src/TicketSystem.php` — et obtenu une application persistée, exposée en
HTTP et rendue dans le navigateur. Voici pourquoi cela fonctionne.

### Les couches {#the-layers}

```
  TicketSystem plugin        ← vous l'avez écrit
        │  compilé par le Compiler
        ▼
  MetadataGraph              ← entités, champs, actions, workflows, projections
        │  consommé par
        ▼
  Runtime / Invoker          ← preflight → policy → workflow guard → effect → audit
        │  lit et écrit
        ▼
  Persistance SQLite         ← schéma dérivé du graphe ; + kernel_audit_log
```

Et la moitié orientée extérieur :

```
  HTTP Router (ausus/api-http)   ← mappe /projections + /actions sur le runtime
        │  émet
        ▼
  ViewSchema JSON                ← champs + actions + données
        │  consommé par
        ▼
  @ausus/renderer-react          ← dessine listes, vues de détail, boîtes d'action
```

### Ce qui se passe sur une action {#what-happens-on-one-action}

Quand vous avez cliqué sur **Start** dans le navigateur, voici le trajet
complet :

1. Le moteur a `POST`é `/api/actions/helpdesk.ticket.start` avec la
   référence du ticket et les en-têtes tenant/rôles.
2. Le `Router` l'a parsé et a appelé l'`Invoker`.
3. L'`Invoker` a exécuté sa chaîne dans une transaction unique :
   **preflight** (l'action existe, le sujet est dans le tenant) →
   **policy** (l'acteur possède `ticket.agent`) → **workflow guard** (le
   ticket est `OPEN`, donc `OPEN → IN_PROGRESS` est légal) → **effect**
   (écrire `status = IN_PROGRESS`) → **audit** (entrée dans
   `kernel_audit_log`).
4. La réponse est repartie en JSON ; le moteur a re-fetché la projection et
   redessiné la ligne.

Chaque garantie — tenancy, autorisation, vérification de transition légale,
entrée d'audit — provient des métadonnées que vous avez déclarées, pas du
code écrit par route.

### Ce que vous avez construit {#what-you-built}

- Un plugin de domaine : 1 entité, 5 champs, 4 actions, 1 workflow,
  2 projections.
- Une application SQLite exécutée en CLI.
- Une API HTTP avec les routes health, projection et action.
- Une UI React qui liste les tickets et pilote leur cycle de vie.

## Limites de documentation restantes {#remaining-documentation-gaps}

Notes honnêtes sur ce que ce tutoriel **n'a pas pu** montrer, parce que la
capacité n'existe pas en v0.1.x :

- **Créer des enregistrements depuis l'UI.** Les descripteurs d'action de
  ViewSchema ne portent pas encore les métadonnées des champs d'entrée ; le
  moteur ne peut donc pas dessiner un formulaire de création. La création
  se fait en PHP ou en `curl` ici.
- **Authentification.** La v0.1.0 n'a pas de couche d'auth ; les rôles
  passent en en-têtes / config. Un déploiement réel a besoin d'un
  middleware d'auth devant le `Router`.
- **Bases autres que SQLite.** La persistance est validée sur SQLite
  uniquement.
- **Filtrage et pagination de liste.** Les projections retournent toutes
  les lignes du tenant ; `pagination.nextCursor` est toujours `null`.
- **Politiques de garde par transition.** L'autorisation est par action ;
  une politique de garde attachée à une transition individuelle est conçue
  mais pas exécutée en v0.1.0.
- **Entités multiples et références croisées.** Ce tutoriel utilise une
  seule entité ; les relations entre entités ne sont pas couvertes.

## Où aller ensuite {#where-to-go-next}

- [Concepts fondamentaux](../concepts/metadata-graph.md) — le modèle
  derrière tout ce que vous venez d'utiliser.
- [Le DSL PHP](../backend/php-dsl.md) — chaque méthode du builder.
- [Workflows](../concepts/workflows.md) — transitions, gardes et l'API de
  workflow explicite en profondeur.
- [L'API HTTP](../backend/http-api.md) — la référence complète des routes
  et erreurs.

Vous avez construit une application AUSUS complète depuis zéro. Tout ce
qui est plus grand n'est que les quatre mêmes mouvements : **décrire le
domaine, amorcer l'Application, l'exposer, l'afficher.**

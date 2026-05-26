---
id: react-renderer
title: Le moteur de rendu React
sidebar_label: Le moteur de rendu React
description: "@ausus/renderer-react — le client React pour ViewSchema."
---

# Le moteur de rendu React

`@ausus/renderer-react` est le client React pour AUSUS. Il récupère un
[ViewSchema](viewschema.md) depuis l'[API HTTP](../backend/http-api.md) et
le rend. C'est la couche L6 de la pile.

React est traité comme un **moteur de rendu uniquement** — le moteur de rendu ne détient aucune
connaissance du domaine. Tout ce qu'il dessine provient du ViewSchema.

## Installation {#install}

```bash
npm install @ausus/renderer-react react@18 react-dom@18
# React 19 is also supported:
# npm install @ausus/renderer-react react@^19 react-dom@^19
```

`react` et `react-dom` sont des **dépendances de pair (peer dependencies)** (`^18 || ^19`). Le paquet
est **ESM uniquement** et n'embarque aucune dépendance.

## API publique {#public-api}

```ts
import {
  AususProvider, useAusus,
  useViewSchema, useAction,
  ViewSchemaConsumer,
  ListView, DetailView, ActionModal, WorkflowBadge, FieldDisplay,
} from "@ausus/renderer-react";
```

| Export | Genre | Rôle |
|---|---|---|
| `AususProvider` | composant | injecte l'URL de base de l'API, le tenant et le fetcher |
| `useAusus` | hook | lit le contexte du provider |
| `useViewSchema` | hook | récupère le ViewSchema d'une projection |
| `useAction` | hook | invoque une action |
| `ViewSchemaConsumer` | composant | récupère une projection et dispatche vers une vue |
| `ListView` / `DetailView` | composants | rendent un ViewSchema liste / détail |
| `ActionModal` | composant | confirmation + formulaire de saisie pour une action |
| `WorkflowBadge` | composant | badge coloré pour un état de workflow |
| `FieldDisplay` | composant | rend une cellule de champ selon le type |

## Flux de données {#data-flow}

Ce que fait une page rendue, de bout en bout :

![Flux de données du moteur de rendu React : l'API HTTP sert une ViewSchema ; ViewSchemaConsumer la dispatche vers ListView (items) ou DetailView (item), chacun exposant des actions ; un clic sur une action ouvre ActionModal, qui construit son formulaire à partir d'action.inputs et POSTe vers /actions/{fqn}.](/img/diagrams/renderer-flow.svg)

Le moteur de rendu n'inspecte jamais directement les types du domaine —
chaque choix est fait à partir de la ViewSchema.

## Utilisation {#usage}

Enveloppez votre application une fois dans `AususProvider`, puis rendez une projection :

```tsx
import { AususProvider, ViewSchemaConsumer } from "@ausus/renderer-react";

function App() {
  return (
    <AususProvider apiBaseUrl="http://localhost:8080/api" tenant="acme">
      <ViewSchemaConsumer projection="billing.invoice.summary" />
    </AususProvider>
  );
}
```

`ViewSchemaConsumer` récupère le ViewSchema, puis :

- rend `ListView` si le `data.items` du schéma est présent ;
- rend `DetailView` si `data.item` est présent (une prop `subject` est requise) ;
- affiche un état de chargement pendant la récupération et un état d'erreur avec un bouton de
  nouvel essai en cas d'échec.

### Le provider {#the-provider}

```tsx
<AususProvider
  apiBaseUrl="http://localhost:8080/api"
  tenant="acme"
  fetcher={customFetch}   // optional — defaults to window.fetch
/>
```

Le `fetcher` optionnel vous permet d'injecter des en-têtes d'authentification, des nouveaux essais ou un double de test.
C'est la couture où vous ajoutez l'authentification que le backend ne fournit pas.

### Les hooks directement {#hooks-directly}

```tsx
const { schema, loading, error, refetch } = useViewSchema("billing.invoice.summary");
const { invoke, pending, lastError }      = useAction("billing.invoice.issue");

await invoke({ subject: ref, inputs: {} });
```

`useAction` attend toujours le serveur — il n'y a pas d'UI optimiste dans la v0.1.0.

## Style {#styling}

Le moteur de rendu émet des noms de classe sémantiques (`ausus-table`, `ausus-badge`,
`ausus-modal`, `ausus-btn`, …) mais **n'embarque aucun fichier CSS**. Vous fournissez la
feuille de style. Les noms de classe sont stables et documentés par leur usage dans les
composants.

## Limites actuelles de la v0.1.0 {#current-v010-limitations}

- **Pas de CSS embarqué** — vous fournissez le style pour les noms de classe `ausus-*`.
- **Pas de router** — `ViewSchemaConsumer` rend une seule projection ; câbler la
  navigation liste → détail est le rôle de l'application hôte.
- **Pas d'UI optimiste** — chaque action attend la réponse du serveur.
- `ActionModal` ne rend que de simples champs de saisie texte ; les éditeurs de champs riches ne sont pas dans
  la v0.1.0.
- Les couleurs de `WorkflowBadge` forment une palette fixe indexée sur des noms d'états courants
  (`DRAFT`, `ISSUED`, `PAID`, `CANCELLED`) ; les autres états reçoivent une couleur par défaut.
- Le champ d'état du workflow est détecté par une heuristique (un champ `enum` nommé
  `status`).

## Voir aussi {#related}

- [ViewSchema](viewschema.md) — le format que ceci rend.
- [L'API HTTP](../backend/http-api.md) — d'où viennent les ViewSchemas.
- [Paquets](../packages/index.md) — l'entrée du paquet npm.

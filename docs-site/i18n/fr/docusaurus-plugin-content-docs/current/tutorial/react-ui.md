---
id: react-ui
title: 'Partie 5 — Interface React'
sidebar_label: 5. Interface React
description: Affichez le système de tickets dans le navigateur grâce au moteur de rendu React AUSUS.
---

# Partie 5 — Interface React

**Pourquoi cette étape :** l'API HTTP renvoie une **ViewSchema** — du JSON qui
décrit les champs, les actions et les données. Le moteur de rendu React
d'AUSUS transforme ce JSON en interface utilisateur fonctionnelle. Vous
n'écrirez ni composant de tableau ni formulaire : le moteur lit le schéma et
dessine l'UI.

## Comment fonctionne le moteur de rendu {#how-the-renderer-works}

`@ausus/renderer-react` est un petit paquet React. Les deux pièces dont vous
avez besoin :

- **`AususProvider`** — englobe votre application une fois ; il porte l'URL
  de base de l'API et le tenant.
- **`ViewSchemaConsumer`** — donné un nom de projection, il récupère la
  ViewSchema correspondante et la rend : une liste devient un tableau avec
  des boutons d'action, un enregistrement unique devient une vue de détail.

Le moteur est **piloté par les métadonnées** : il ne connaît rien des
« tickets ». Pointez-le sur `helpdesk.ticket.summary` et il dessine ce que la
projection décrit.

## Échafauder une application React {#scaffold-a-react-app}

Depuis la **racine du projet**, créez une application Vite React dans un
dossier `ui/` :

```bash
npm create vite@latest ui -- --template react-ts
cd ui
npm install
```

Puis ajoutez le moteur de rendu AUSUS (`react` et `react-dom` sont déjà
installés par le modèle et satisfont la dépendance pair du moteur) :

```bash
npm install @ausus/renderer-react
```

## Écrire le composant App {#write-the-app-component}

Remplacez le contenu de `ui/src/App.tsx` par :

```jsx
import { AususProvider, ViewSchemaConsumer } from "@ausus/renderer-react";
import type { Fetcher } from "@ausus/renderer-react";
import "./index.css";

/**
 * The renderer sends the X-Tenant-ID header on its own. We wrap fetch to also
 * send X-Actor-Roles, so action calls pass the policy check.
 *
 * v0.1.0 has no authentication layer — in a real deployment this header is set
 * by an authenticated gateway in front of the API, never in the browser.
 */
const fetcher: Fetcher = (url, init) =>
  fetch(url, {
    ...init,
    headers: {
      ...(init?.headers ?? {}),
      "X-Actor-Roles": "ticket.agent,ticket.viewer",
    },
  });

export default function App() {
  return (
    <AususProvider
      apiBaseUrl="http://localhost:8080/api"
      tenant="helpdesk"
      fetcher={fetcher}
    >
      <header className="ausus-header">
        <strong>Ticket System</strong> · tenant <code>helpdesk</code>
      </header>
      <ViewSchemaConsumer projection="helpdesk.ticket.summary" />
    </AususProvider>
  );
}
```

Trois points, et **pourquoi** :

- **`apiBaseUrl`** pointe vers le serveur PHP de la Partie 4. Le navigateur
  et l'API sont sur des ports différents, mais le `Router` envoie des
  en-têtes CORS permissifs ; les requêtes cross-origin fonctionnent.
- **`tenant="helpdesk"`** est envoyé en tant que `X-Tenant-ID` sur chaque
  requête.
- **`fetcher`** est un `fetch` enrobé qui ajoute `X-Actor-Roles`. Sans cela,
  les boutons d'action seraient refusés — le même `403` que vous obtiendriez
  depuis `curl`.

## Ajouter des styles minimaux {#add-minimal-styles}

Le moteur n'embarque **aucun CSS** en v0.1.0 — il pose seulement des noms de
classes. Remplacez `ui/src/index.css` par cette feuille de style minimale
pour que l'UI soit lisible :

```css
body { font-family: system-ui, sans-serif; margin: 0; background: #f6f7f9; color: #1a1a1a; }
.ausus-header { padding: 12px 20px; background: #fff; border-bottom: 1px solid #e3e3e3; }
.ausus-list { padding: 20px; }
.ausus-list__header { display: flex; justify-content: space-between; align-items: center; }
.ausus-table { width: 100%; border-collapse: collapse; background: #fff; margin-top: 12px; }
.ausus-table th, .ausus-table td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #eee; }
.ausus-badge { padding: 2px 8px; border-radius: 10px; font-size: 12px; background: #e6e6e6; }
.ausus-btn { padding: 5px 10px; margin: 0 3px; border: 1px solid #ccc; border-radius: 6px;
  background: #fff; cursor: pointer; }
.ausus-btn--primary { background: #2563eb; color: #fff; border-color: #2563eb; }
.ausus-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.4);
  display: flex; align-items: center; justify-content: center; }
.ausus-modal { background: #fff; padding: 20px; border-radius: 8px; min-width: 320px; }
.ausus-loading, .ausus-empty, .ausus-error { padding: 20px; }
```

## Exécuter l'application entière {#run-the-whole-application}

Vous avez maintenant besoin de **deux processus**. Dans le **premier
terminal**, depuis la racine du projet, démarrez l'API (et amorcez la base
si ce n'est pas déjà fait) :

```bash
php tickets.php          # amorce tickets.sqlite — à exécuter une fois
php -S localhost:8080 server.php
```

Dans un **second terminal**, lancez le serveur de développement React :

```bash
cd ui
npm run dev
```

Vite affiche une URL locale — ouvrez-la (par défaut
`http://localhost:5173`). Vous devriez voir les trois tickets de la
Partie 3, chacun avec un badge de statut coloré et des boutons de workflow
par ligne.

![Vue liste du système de tickets rendue par le moteur de rendu React d'AUSUS — trois tickets dans un tableau avec des badges de statut colorés et des boutons de workflow par ligne.](/img/tutorial/ticket-list.svg)

## Piloter le workflow depuis le navigateur {#drive-the-workflow-from-the-browser}

Cliquez sur **Start** pour le ticket `OPEN`. Une boîte de confirmation
apparaît ; confirmez-la. La ligne se rafraîchit et le badge de statut passe
à `IN_PROGRESS`. **Resolve** est désormais l'étape légale suivante ;
re-cliquer sur **Start** renverrait une erreur `WorkflowStateMismatch` dans
la boîte de dialogue — la garde de workflow, appliquée dans le navigateur
exactement comme elle l'était en CLI.

## Une limite de la v0.1.0 : créer depuis l'UI {#a-v010-limitation-creating-from-the-ui}

L'en-tête de la liste expose un bouton **Create**, mais en v0.1.0 il est
limité. La ViewSchema ne décrit pas encore les **champs d'entrée** d'une
action ; le moteur ne peut donc pas dessiner le formulaire de création —
confirmer la boîte enverrait des entrées vides et échouerait avec une
erreur `FieldRequired`.

En attendant, créez les tickets comme vous le savez déjà :

- en PHP — `$app->invoke('helpdesk.ticket.create', null, [...])` ;
- via HTTP — le `POST` curl de la
  [Partie 4](http-api.md#create-a-ticket-over-http).

Les actions de transition (`start`, `resolve`, `close`) ne nécessitent
aucune entrée et fonctionnent pleinement depuis l'UI. Cette limite est
rappelée dans [Dépannage](troubleshooting.md).

## Ce que vous avez maintenant {#what-you-have-now}

```
ticket-system/
├── src/TicketSystem.php
├── tickets.php
├── server.php
├── tickets.sqlite
├── vendor/
└── ui/                  ← Vite + React + @ausus/renderer-react
    └── src/{App.tsx,index.css}
```

Une tranche verticale complète : un domaine, une base de données, une API
HTTP et une UI navigateur — toutes pilotées par l'unique plugin que vous
avez écrit en Partie 2.

**Suivant : [Partie 6 — Dépannage et récapitulatif](troubleshooting.md).**

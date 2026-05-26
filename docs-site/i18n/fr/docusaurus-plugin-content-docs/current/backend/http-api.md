---
id: http-api
title: L'API HTTP
sidebar_label: L'API HTTP
description: La surface HTTP PSR-7/15 pour les projections et les actions.
---

# L'API HTTP

`ausus/api-http` (couche L4) expose le runtime via HTTP. C'est un unique
gestionnaire de requêtes PSR-15 — `Router` — qui sert les [projections](../concepts/projections.md)
et dispatche les [actions](../concepts/entities-fields-actions.md#actions).

## Routes {#routes}

| Méthode | Chemin | Rôle |
|---|---|---|
| `GET` | `/_health` | sonde de vivacité ; retourne le hash du graphe |
| `GET` | `/projections/{fqn}` | rend une projection vers un [ViewSchema](../frontend/viewschema.md) |
| `POST` | `/actions/{fqn}` | invoque une action ; retourne un résultat d'action |
| `OPTIONS` | `*` | preflight CORS |

Les chemins sont servis sous un préfixe configurable (par défaut `/api`).

Le trajet d'une requête à travers le système :

![Cycle de vie d'une requête HTTP : la requête entrante passe par le Router, qui la dispatche vers /_health (direct), /projections/{fqn} (via ProjectionRenderer) ou /actions/{fqn} (via le pipeline Invoker) ; toute exception du noyau est attrapée et classifiée par l'ErrorMapper en code HTTP, et la réponse finale est JSON avec CORS permissif.](/img/diagrams/http-lifecycle.svg)

### `GET /projections/{fqn}` {#get-projectionsfqn}

Paramètres de requête :

- `subject` — un handle d'identité. S'il est présent, la projection est rendue sous
  forme **détail** pour cet enregistrement ; s'il est absent, elle est rendue sous forme **liste**.
- `locale`, `renderer`, `acceptSchemaVersions` — acceptés et réservés ; le
  moteur de rendu de la v0.1.0 émet `locale: en-US`, `targetProfile: react.web.v1`,
  `schemaVersion: 1.0.0`.

```bash
curl -H 'X-Tenant-ID: acme' \
  'http://localhost:8080/api/projections/billing.invoice.summary'
```

### `POST /actions/{fqn}` {#post-actionsfqn}

Corps — un objet JSON :

```json
{
  "subject": { "tenantId": "acme", "entityFqn": "billing.invoice", "identityHandle": "..." },
  "inputs":  { "number": "INV-1", "customer_name": "ACME", "amount": { "amount": "10.00", "currency": "USD" } }
}
```

`subject` est `null` pour les actions de création. L'enveloppe de réponse est
`{ "ok": true, "outputs": { ... } }` en cas de succès.

```bash
curl -X POST -H 'X-Tenant-ID: acme' -H 'Content-Type: application/json' \
  -d '{"subject":null,"inputs":{"number":"INV-1","customer_name":"ACME","amount":{"amount":"10.00","currency":"USD"}}}' \
  http://localhost:8080/api/actions/billing.invoice.create
```

## En-têtes de requête {#request-headers}

| En-tête | Requis | Signification |
|---|---|---|
| `X-Tenant-ID` | oui (sur `/projections/*` et `/actions/*`) | le tenant actif |
| `X-Actor-Id` | non | l'id de l'acteur (par défaut `anon`) |
| `X-Actor-Roles` | non | liste de rôles séparés par des virgules |

Le router construit un `StubActor` à partir de `X-Actor-Id` et `X-Actor-Roles`.

:::danger Pas d'authentification — placez un garde en amont
L'API HTTP fait confiance à `X-Tenant-ID`, `X-Actor-Id` et `X-Actor-Roles` exactement
tels qu'envoyés. Il n'y a **aucune authentification** dans la v0.1.0 — un appelant peut revendiquer
n'importe quel tenant et n'importe quels rôles. Vous **devez** placer une véritable couche
d'authentification et d'autorisation en amont de ce gestionnaire avant de l'exposer. Traitez
`ausus/api-http` comme une surface interne tant que vous ne l'avez pas fait.
:::

## Réponses d'erreur {#error-responses}

`ErrorMapper` associe la taxonomie des exceptions du kernel aux codes de statut HTTP :

| Condition | Statut | `error.kind` |
|---|---|---|
| Requête invalide (en-tête manquant, corps incorrect) | 400 | `BadRequest` |
| Politique refusée | 403 | `PolicyDenied` |
| Violation de la frontière de tenant | 403 | `TenantBoundaryViolation` |
| Incohérence d'état de workflow | 409 | `WorkflowStateMismatch` |
| Conflit de concurrence | 409 | `ConcurrencyConflict` |
| Non mappé / échec d'effet | 500 | `InternalError` / `EffectFailure` |

L'enveloppe d'erreur est `{ "ok": false, "error": { "kind": "...", "message": "..." } }`.

## Interopérabilité PSR {#psr-interop}

`Router` implémente `Psr\Http\Server\RequestHandlerInterface` et prend en paramètre de
constructeur les `ResponseFactoryInterface` / `StreamFactoryInterface` PSR-17.
Il fonctionne avec n'importe quelle implémentation PSR-7. Un `Emitter` minimal est inclus pour le
contrôleur frontal de démonstration ; les déploiements en production peuvent y substituer un
émetteur PSR-7 plus complet.

## Limites actuelles de la v0.1.0 {#current-v010-limitations}

- **Pas d'authentification** (voir l'avertissement ci-dessus) et CORS est grand ouvert
  (`Access-Control-Allow-Origin: *`).
- Les routes d'action et de projection constituent toute la surface — il n'y a pas de
  point d'introspection de métadonnées/graphe au-delà de `/_health`.
- Le rôle par défaut du `StubActor`, lorsque `X-Actor-Roles` est omis, est l'ensemble
  de rôles HelloInvoice — pratique pour la démonstration, pas un défaut de production.

## Voir aussi {#related}

- [ViewSchema](../frontend/viewschema.md) — ce que retourne `/projections/*`.
- [Le moteur de rendu React](../frontend/react-renderer.md) — le client de cette API.
- [Référence des erreurs](../reference/errors.md) — la taxonomie complète des exceptions.

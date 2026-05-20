---
id: errors
title: Référence des erreurs
sidebar_label: Référence des erreurs
description: La taxonomie des exceptions du kernel AUSUS et son mappage HTTP.
---

# Référence des erreurs

AUSUS utilise une taxonomie d'exceptions réduite et explicite. Chaque erreur du
kernel étend une classe de base unique, ce qui permet d'intercepter largement ou
finement.

## La classe de base {#the-base-class}

```php
class AususError extends \RuntimeException {}
```

Chaque erreur ci-dessous étend `Ausus\AususError`. Intercepter `AususError` les
intercepte toutes ; le runtime distingue une erreur AUSUS d'une erreur
inattendue (une erreur inattendue levée à l'intérieur d'un effet est encapsulée
sous forme de `EffectFailed`).

## Exceptions du kernel {#kernel-exceptions}

| Exception | Levée lorsque |
|---|---|
| `UnknownAction` | `invoke()` reçoit un FQN d'action absent du graphe |
| `PolicySubjectRequired` | une action requiert un sujet mais aucun n'a été transmis |
| `ActorRequired` | un acteur est requis mais absent |
| `TenantContextRequired` | un contexte de tenant est requis mais absent |
| `TenantBoundaryViolation` | le tenant d'une `Reference` diffère du tenant actif |
| `PolicyDenied` | la politique a renvoyé une décision autre que `Permit` |
| `WorkflowStateMismatch` | aucune transition n'autorise l'action depuis l'état courant |
| `WorkflowSubjectNotFound` | l'enregistrement sujet du workflow n'existe pas |
| `WorkflowGuardDenied` | un garde de workflow a refusé la transition |
| `EffectFailed` | un effet a levé une exception ; porte la cause sous-jacente |
| `ConcurrencyConflict` | un `update` a été tenté avec un `_version` périmé |
| `NotFound` | un enregistrement référencé n'existe pas |
| `AuditEmissionFailed` | le sink d'audit a rejeté l'entrée d'audit |

`ConcurrencyConflict` et `NotFound` portent la `Reference` fautive ;
`ConcurrencyConflict` porte également les versions `expected` et `actual`.

## Mappage HTTP {#http-mapping}

`ausus/api-http` ajoute une exception de niveau transport, `BadRequest`, et
mappe la taxonomie vers des codes de statut HTTP via `ErrorMapper` :

| Exception | Statut HTTP | `error.kind` |
|---|---|---|
| `BadRequest` | 400 | `BadRequest` |
| `MalformedDescriptor` | 400 | `MalformedDescriptor` |
| `PolicyDenied` | 403 | `PolicyDenied` |
| `TenantBoundaryViolation` | 403 | `TenantBoundaryViolation` |
| `WorkflowStateMismatch` | 409 | `WorkflowStateMismatch` |
| `ConcurrencyConflict` | 409 | `ConcurrencyConflict` |
| `EffectFailure` | 500 | `EffectFailure` |
| toute autre erreur | 500 | `InternalError` |

L'enveloppe d'erreur HTTP :

```json
{ "ok": false, "error": { "kind": "WorkflowStateMismatch", "message": "..." } }
```

## Interception des erreurs {#catching-errors}

```php
use Ausus\{PolicyDenied, WorkflowStateMismatch, ConcurrencyConflict, AususError};

try {
    $invoker->invoke('billing.invoice.issue', $ref, []);
} catch (PolicyDenied $e) {
    // actor lacks the required role
} catch (WorkflowStateMismatch $e) {
    // the invoice is not in a state that allows `issue`
} catch (ConcurrencyConflict $e) {
    // someone else changed the record — re-read and retry
} catch (AususError $e) {
    // any other AUSUS error
}
```

## Limites actuelles de la v0.1.0 {#current-v010-limitations}

- La taxonomie est volontairement minimale. Elle est décrite dans le code source
  comme une « V0 minimal closed-ish taxonomy » — les versions ultérieures
  pourront ajouter des erreurs à granularité plus fine.
- Les erreurs portent un message et (pour certaines) une `Reference` ; il n'y a
  ni catalogue structuré de codes d'erreur ni i18n des messages d'erreur en
  v0.1.0.

## Voir aussi {#related}

- [Le runtime](../backend/runtime.md) — où la plupart des erreurs prennent naissance.
- [L'API HTTP](../backend/http-api.md) — le mappage des statuts HTTP.
- [Politiques](../concepts/policies.md) · [Workflows](../concepts/workflows.md)

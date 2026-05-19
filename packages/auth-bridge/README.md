# ausus/auth-bridge

Authorization plugin satisfying RFC-014 in two modes.

## Owned RFC surfaces

- **RFC-014** — Actor, ActorRef, ActorResolver contract.
- Includes the canonical `roleHash` algorithm of RFC-014 §3 (locked test vectors per §3.6).
- Built-in `RoleRequired` / `PermissionRequired` / `RolesRequired` Policies of RFC-011 §8.3 live here (consumed by the DSL via the `Ausus\` facade re-export).

## Modes

| Mode      | When                                                             | Source                                       |
|-----------|------------------------------------------------------------------|----------------------------------------------|
| `stub`    | `AUSUS_AUTH_MODE=stub` (default in development)                  | Hardcoded users in `config/ausus-auth-stub.php` + CLI commands |
| `laravel` | `AUSUS_AUTH_MODE=laravel` (default in production)                | Wraps `Auth::user()`; roles from Spatie\Permission OR `roles` model attribute OR custom resolver |

## Stub-mode CLI

```
php artisan auth:stub:create <username> --tenant=<id> --roles=<csv>
php artisan auth:stub:list
php artisan auth:stub:delete <username>
```

## Production safety

`AUSUS_AUTH_MODE=stub` is rejected at boot in production unless `AUSUS_AUTH_STUB_FORCE_PROD=true` is set (loud override for read-only demos).

## Allowed dependencies

- `ausus/kernel`
- `illuminate/auth`

## Forbidden

- Any other AUSUS package.
- Direct `Auth::user()` access from outside the bridge (plugin authors consume `Actor` only).

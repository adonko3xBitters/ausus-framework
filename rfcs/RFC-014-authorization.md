# RFC-014 — Authorization Plugin Contract

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Draft                                                  |
| Authors       | architect, kernel, challenger                          |
| Date          | 2026-05-18                                             |
| Depends on    | RFC-001 Draft-04 (incl. Amendments-01 / -02), RFC-002, RFC-003, RFC-004, RFC-005, RFC-007 Draft-02, RFC-011, RFC-012, RFC-013 |
| Mission       | Formalize the Authorization plugin contract. Make `Actor::roleHash()` byte-identical across implementations. Unblock RFC-000 F-V0-04. |
| Hard rule     | `roleHash` MUST be byte-identical across all conforming implementations given the same logical inputs. |

---

## 0. Problem statement

RFC-005 §1.3 specifies the minimum `Actor` surface that the Policy Engine consumes: `ref()` returning an `ActorRef`, and `roleHash()` returning an "opaque, deterministic" string. The minimum is sufficient for the Engine to construct cache keys (RFC-005 §8.2), but two operational gaps follow:

1. **RFC-005's "deterministic" claim is unenforced across implementations.** RFC-000 C-V0-02 surfaced this: two Authorization plugins could produce incompatible `roleHash` values for what users would consider "the same" identity. This breaks RFC-004 §12.1 ViewSchema cache correctness as soon as more than one Authorization plugin is in use, and breaks Policy Engine cache portability when a deployment migrates from one Authorization plugin to another.
2. **RFC-011 §8.3 built-in `RoleRequired` / `PermissionRequired` / `RolesRequired` Policies** depend on `Actor::roles()` and `Actor::permissions()` accessors that RFC-005 leaves out of the minimum surface. RFC-005 §1.3 acknowledges the coupling: "Plugins coupling to these methods are coupling to the bridge, not to the kernel. Documented as the expected V1 coupling per RFC-005 §1.3."

This RFC closes both gaps. It formalizes the full Authorization plugin contract for V1, defines a canonical `roleHash` algorithm that any conforming implementation can reproduce, and ships a test-vector suite that operationalizes the hard rule.

No new kernel primitives are introduced. The Authorization plugin is L7, consumed at the boundary by the Invoker (RFC-001 §A-1.4) and the Policy Engine (RFC-005). What this RFC adds is the contract that L7 implementations must satisfy plus the proof obligations required for the "byte-identical" promise.

Eleven decisions, all bound by the hard rule:

- Actor shape, roleHash algorithm, permissionHash, caching, session lifecycle, elevation integration, tenant scoping, impersonation, invalidation, doctor checks, conformance tests.

---

## 1. Scope and inherited constraints

### 1.1 Inherited (non-negotiable)

1. Authorization is L7 (plugin), not L0 (kernel). RBAC, ABAC, ReBAC are all expressible as Authorization plugin implementations (RFC-001 §8.2, §9.11; RFC-005 §14.1).
2. The kernel consumes `Actor` at the Policy Engine boundary and at the Audit emission boundary (RFC-005 §1.3, RFC-007 §2.1).
3. Policies are the kernel's authorization primitive. The Authorization plugin produces Actors; Policies (RFC-005) make decisions (RFC-001 §8.2).
4. `Actor::roleHash()` is part of the V1 cache key (RFC-005 §8.2, RFC-004 §12.1).
5. Authentication is out of scope (RFC-005 §1.2). The Authorization plugin assumes an authenticated identity; it does not establish one.
6. Cross-Tenant operations require elevation (RFC-003 §10). The Authorization plugin's role/permission resolution is Tenant-scoped per the active Tenant Context.
7. The kernel forbids service-locator escape from Policies (RFC-005 §10) and Effects (RFC-013 §3.7). The same prohibition applies to Authorization plugin code invoked during Actor resolution.
8. Only one Authorization plugin is bound per deployment in V1 (consistent with single-PersistenceDriver, single-ReportingDriver constraints in RFC-002 §14 and RFC-010 §2.7).

### 1.2 Out of scope

- Authentication (login, password verification, MFA, OAuth flows, SSO). Plugin assumes a session exists.
- Identity provider integration (LDAP, Azure AD, Okta connectors). Plugin-internal.
- Role storage UI / admin screens. Plugin- or application-internal.
- Permission grants UI. Plugin- or application-internal.
- User provisioning, deprovisioning, lifecycle management. Out of scope.
- Multi-factor identity assertions per request. Out of scope.
- Impersonation as a first-class kernel feature (§9 deferred).
- Cross-organization federation. Out of scope.
- Audit of authentication events. The audit subsystem (RFC-007) audits Actions; authentication is not an Action.

---

## 2. Actor contract

### 2.1 Interface

```php
interface Actor
{
    function ref(): ActorRef;                                  // RFC-007 §2.1; identity for audit
    function roleHash(): string;                                // §3; deterministic, byte-identical
    function roles(): array;                                    // string[]; §2.3
    function permissions(): array;                              // string[]; §2.3
    function attribute(string $key): mixed;                     // opaque ABAC attribute; §2.5
    function attributeKeys(): array;                            // string[]; declared at boot; §2.5
}
```

Six methods. Closed for V1. The first two are restated from RFC-005 §1.3 minimum; the remaining four are the V1 extensions this RFC adds.

### 2.2 `ref()`

Returns `ActorRef`:

```php
final class ActorRef
{
    public function __construct(
        public readonly string $type,           // 'user' | 'system' | 'service'
        public readonly string $id,             // identity within type; opaque to kernel
        public readonly string $homeTenant      // TenantId.value()
    ) {}
}
```

Shape unchanged from RFC-007 §2.1.

- `type` ∈ {`'user'`, `'system'`, `'service'`}. Closed set in V1. Authorization plugins MUST use one of these three.
- `id` is opaque to the kernel. Conventional: email for users, hostname for services, the literal `'system'` for system Actor.
- `homeTenant` is the Tenant the Actor primarily belongs to. During elevation, this is the Actor's origin (not the target).

### 2.3 `roles()` and `permissions()`

Both return `string[]` — arrays of role / permission identifiers. The identifiers are plugin-defined strings; the kernel treats them as opaque tokens.

Constraints (normative):

1. Returned arrays MUST contain only non-empty UTF-8 strings.
2. Returned arrays MUST NOT contain `null`, integers, booleans, objects, or any non-string values.
3. Returned arrays MAY contain duplicates; the canonical hashing algorithm (§3) deduplicates.
4. Returned arrays MAY be empty (an Actor with no roles or permissions is valid; cache hash is the fixed empty-input hash per §3.6).
5. Returned arrays are Tenant-scoped per the active Tenant Context. The same Actor in different Tenants MAY return different `roles()` / `permissions()` values; the plugin handles per-Tenant resolution internally.
6. Returned arrays MUST be stable for the duration of a single Invoker call. The plugin MUST NOT return different values for two consecutive calls within one Invoker invocation. Cross-call stability is per-request (§6).

### 2.4 `attribute($key)`

Returns the value of a named ABAC attribute. Value type is plugin-defined; the kernel treats it as opaque.

Constraints:

1. The attribute key MUST be one of the strings returned by `attributeKeys()`.
2. The returned value MUST be JSON-serializable (string, integer, float, boolean, array, object — no closures, no resources).
3. For an unknown key (not in `attributeKeys()`), the method MUST throw `UnknownAttribute(key)`.
4. Attribute values MAY change between Invoker calls. They are not part of `roleHash` (§3.2 rationale).

### 2.5 `attributeKeys()`

Returns the list of attribute keys this Actor exposes. Declared at boot time (typically derived from the plugin's configuration); stable for the process lifetime.

This list is used by:

- Policy authors who want to know what attributes are available without trial-and-error.
- The Policy Engine's `cacheable: false` enforcement: a Policy that reads attributes is cacheable: false unless declared otherwise (§5.4).

### 2.6 What `Actor` does NOT expose

- No password, no credential, no session token. Authentication state is the plugin's internal.
- No "current request" handle. Actor is request-scoped but does not carry request state.
- No service-container reference. Actor implementations MUST NOT call `app()` / `resolve()` from inside `roles()`, `permissions()`, `attribute()`, etc. (detection per §11.3).
- No mutation methods. Actor is immutable for the lifetime of the request.

### 2.7 Construction

Authorization plugins implement `Actor` and produce instances at request resolution. The plugin registers a `ActorResolver`:

```php
interface ActorResolver
{
    function resolve(): ?Actor;          // returns null if no Actor is authenticated
}
```

The `ActorResolver` is bound by the plugin's service provider. The Invoker calls `resolve()` once per request and passes the resulting `Actor` to every downstream consumer.

`resolve()` returning `null` is the unauthenticated case. The Invoker rejects any Action invocation when Actor is `null`, with `ActorRequired` (§13).

---

## 3. `roleHash` canonical algorithm

### 3.1 The hard rule

`Actor::roleHash()` MUST be byte-identical across all conforming Authorization plugin implementations given the same logical inputs. Two implementations producing different hashes for the same `(roles, permissions)` set are non-conformant.

### 3.2 What goes into the hash

Exactly two inputs:

- `roles()` — the array of role strings.
- `permissions()` — the array of permission strings.

Nothing else. Specifically, the hash does NOT include:

- `ref()` (type, id, homeTenant). Two Actors with the same roles and permissions but different identities produce the same hash. This is the intended caching property: cache entries are keyed by authorization profile, not by individual user.
- `attribute()` values. Attributes are NOT in the hash because their value type is plugin-defined and may include non-serializable shapes. Policies that depend on attributes are uncached (§5.4).
- The active Tenant. The Tenant is part of the cache key separately (RFC-005 §8.2); including it in `roleHash` would double-count.
- The Clock, CorrelationId, TraceId, RequestId. None of these are authorization-relevant.

### 3.3 Canonical input construction

Given `roles()` returning $R$ and `permissions()` returning $P$:

1. **Validate.** Verify every element of $R$ and $P$ is a non-empty UTF-8 string. If any element is null, non-string, or empty, raise `RoleHashInputInvalid` (§13).
2. **Normalize.** Apply Unicode NFC normalization to every string in $R$ and $P$.
3. **Deduplicate.** Remove duplicates from $R$ and $P$ independently. Duplicates are determined by byte-equality after NFC.
4. **Sort.** Sort $R$ and $P$ ascending by UTF-8 codepoint byte sequence (case-sensitive, no locale).
5. **Serialize.** Construct the canonical JSON object:

```json
{"permissions":["<p1>","<p2>",...],"roles":["<r1>","<r2>",...]}
```

with the following rules:

- The top-level object MUST contain exactly two keys: `"permissions"` and `"roles"`, in that order (alphabetical).
- Keys MUST be wrapped in double quotes with no escape characters beyond those required by RFC 8259.
- Array elements MUST be wrapped in double quotes with escape characters strictly per RFC 8259 (only `\"`, `\\`, `\/` optional, `\b`, `\f`, `\n`, `\r`, `\t`, `\uXXXX` for control characters U+0000 through U+001F).
- No whitespace anywhere in the output.
- No trailing newline.
- UTF-8 encoded; no BOM.

### 3.4 Hashing

The canonical JSON byte sequence is hashed with SHA-256. The hash output is encoded as a lowercase hexadecimal string of exactly 64 characters.

```
roleHash = lowercase_hex(SHA-256(UTF-8(canonical_json)))
```

### 3.5 Implementation pseudocode

```
function roleHash(roles, permissions):
  for r in roles: assert isNonEmptyString(r); else raise RoleHashInputInvalid
  for p in permissions: assert isNonEmptyString(p); else raise RoleHashInputInvalid

  R = sorted(unique(nfc(r) for r in roles))      // codepoint byte order
  P = sorted(unique(nfc(p) for p in permissions))

  json = '{"permissions":[' + ','.join('"' + escape_rfc8259(p) + '"' for p in P) + '],'
       + '"roles":['        + ','.join('"' + escape_rfc8259(r) + '"' for r in R) + ']}'

  return lowercase_hex(SHA256(utf8(json)))
```

This is the reference algorithm. Every conforming implementation produces output identical to this.

### 3.6 Test vectors

Implementations MUST reproduce the following hashes byte-identically:

| Input                                                                  | Canonical JSON                                                                                                     | SHA-256 hex                                                                          |
|------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------|
| roles=[], permissions=[]                                               | `{"permissions":[],"roles":[]}`                                                                                    | (to be computed and locked at acceptance per §18)                                    |
| roles=["admin"], permissions=[]                                        | `{"permissions":[],"roles":["admin"]}`                                                                             | (computed at acceptance)                                                             |
| roles=["admin","viewer"], permissions=[]                               | `{"permissions":[],"roles":["admin","viewer"]}`                                                                    | (computed at acceptance)                                                             |
| roles=["viewer","admin"], permissions=[]                               | `{"permissions":[],"roles":["admin","viewer"]}` *(sorted; same as previous)*                                       | (must match the previous row's hash)                                                 |
| roles=["admin","admin"], permissions=[]                                | `{"permissions":[],"roles":["admin"]}` *(deduplicated)*                                                            | (must match the "single admin" row)                                                  |
| roles=["admin"], permissions=["invoice.create"]                        | `{"permissions":["invoice.create"],"roles":["admin"]}`                                                             | (computed at acceptance)                                                             |
| roles=["admin"], permissions=["invoice.delete","invoice.create"]       | `{"permissions":["invoice.create","invoice.delete"],"roles":["admin"]}`                                            | (computed at acceptance)                                                             |
| roles=["Administrateur"], permissions=[] *(non-ASCII)*                 | `{"permissions":[],"roles":["Administrateur"]}`                                                                    | (computed at acceptance; UTF-8 byte order)                                           |
| roles=["a\\"b"], permissions=[] *(quote in name)*                      | `{"permissions":[],"roles":["a\\"b"]}` *(escaped)*                                                                 | (computed at acceptance)                                                             |
| roles=["管理者"], permissions=[] *(CJK)*                                | `{"permissions":[],"roles":["管理者"]}`                                                                            | (computed at acceptance)                                                             |

The actual hex values are computed once at acceptance time (§18 criterion 5) and locked. Implementations failing to reproduce any vector are non-conformant. Implementations MUST ship a test suite executing every vector at boot or as part of CI; `ausus:doctor` MAY verify on demand.

### 3.7 Forbidden algorithm variants

The following variants are explicitly forbidden:

- Truncating the SHA-256 output. The full 64-character hex is required.
- Encoding as base64, base32, or any encoding other than lowercase hex.
- Using SHA-1, SHA-512, BLAKE2, BLAKE3, or any algorithm other than SHA-256.
- Adding a salt, pepper, or any namespace prefix.
- Including the Tenant, ActorRef, or attributes in the hash input.
- Sorting case-insensitively, by locale, or in any order other than UTF-8 codepoint byte sequence.
- JSON serialization with whitespace, trailing newline, BOM, or any deviation from §3.3.
- Pre-hashing roles and permissions separately and concatenating hashes.

Each deviation breaks byte-identity across implementations. Conformance tests catch.

### 3.8 Why SHA-256

- 256 bits provides sufficient collision resistance for the cache-key use case (probability of collision in 10^9 cache entries is negligible).
- SHA-256 is universally available in PHP via `hash('sha256', ...)`.
- SHA-256 is faster than SHA-512 for input sizes typical of role/permission lists (rarely > 10 KB serialized).
- SHA-256 is not a security boundary (the hash is not used for authentication, only for cache keying); a faster non-cryptographic hash (xxHash, MurmurHash) was considered and rejected (§14.4).

---

## 4. `permissionHash`

### 4.1 Decision

V1 does NOT introduce a separate `permissionHash()` method on `Actor`. The canonical `roleHash` algorithm (§3) already incorporates both `roles()` and `permissions()`. A separate hash would either:

- Duplicate work (recompute over the same data), or
- Force Policy authors to use both hashes for cache keying, doubling the cache-key complexity.

A future RFC may introduce `permissionHash` if a use case proves it valuable. V1's single `roleHash` covers the documented use cases (RFC-004 §12.1 ViewSchema cache key, RFC-005 §8.2 Policy Engine cache key).

### 4.2 Renaming

The kernel terminology in RFC-005 §1.3 is `roleHash`, not `authorizationHash` or `actorHash`. This RFC preserves the name despite the hash including permissions, because:

- The name is already established in the cache-key surface (RFC-004 §12.1, RFC-005 §8.2).
- Renaming would require an Amendment to RFC-005 and break consumers.
- The semantic precedent ("role" in the AUSUS vocabulary encompasses both formal roles and permission grants) is acceptable.

Documentation and conformance test vectors clarify the inclusion of permissions.

---

## 5. Caching

### 5.1 Per-request hash computation

The Authorization plugin's `Actor::roleHash()` is invoked per Invoker call (typically once per HTTP request, once per scheduled job, once per queue job). The kernel does not cache the hash across requests; computing the SHA-256 of a small JSON payload is microsecond-scale work.

Plugins MAY cache internally (e.g., a per-process LRU of `(user_id, tenant_id) → hash`) for performance. The cache is plugin-internal; the kernel does not specify it.

### 5.2 Cache participation

`roleHash` is one component of:

- RFC-005 §8.2 Policy Engine cache key (`actorRoleHash`).
- RFC-004 §12.1 ViewSchema cache key (`actorRoleHash`).

Other components in those cache keys (`graphHash`, `tenantId`, `overrideVersion`, etc.) are not the Authorization plugin's concern.

### 5.3 Cache invalidation on role/permission change

When a user's roles or permissions change (admin updates them), the Authorization plugin's next `roles()` / `permissions()` call MUST reflect the new state. Per §2.3 constraint 6, the values are stable within an Invoker call but may change between calls.

The cache key changes as soon as the hash changes; old cache entries are naturally orphaned (LRU eviction handles cleanup). No explicit push-based invalidation is required; pull-based per-request hashing suffices.

### 5.4 Attribute-dependent Policies and cacheability

Policies that read `actor->attribute('X')` are NOT cacheable safely: the attribute value is not in `roleHash`, so a cached decision based on the attribute would not invalidate when the attribute changes.

Per RFC-005 §8.6, Policies declared `cacheable: false` bypass the evaluation cache entirely. Authorization-attribute-dependent Policies MUST declare `cacheable: false` in their `PolicyDescriptor`. Failure to do so produces stale decisions.

`ausus:doctor` MAY surface a warning when a Policy class references `Actor::attribute(...)` AND has `cacheable: true` (static analysis best-effort).

### 5.5 Cross-deployment cache portability

Two deployments with the same kernel version, the same Metadata Graph hash, the same Tenant overrides, and identical user role/permission assignments produce identical `roleHash` values. The cache key matches across deployments. This is the conformance promise (§3.1).

This is not the same as cache sharing across deployments (which would require a distributed cache; out of V1 per RFC-005 §8.8). It IS the property that a deployment migrating from `ausus/auth-bridge` to a different conforming Authorization plugin (e.g., `ausus/auth-azure-ad`) does NOT invalidate cached entries that pre-existed under the previous plugin — provided role/permission assignments translate one-to-one.

---

## 6. Session lifecycle

### 6.1 Request-scoped resolution

The Authorization plugin's `ActorResolver::resolve()` is called once per Invoker call. Within a single Invoker call (including nested invocations per RFC-013 §4), the same Actor instance is reused. Cross-call (next HTTP request, next queue job) is a fresh `resolve()`.

### 6.2 No session storage at the kernel level

The kernel does not manage sessions. Authorization plugins integrate with Laravel's session, JWT validation, OAuth tokens, or whatever authentication mechanism the deployment uses. The plugin returns an `Actor` representing the authenticated identity per the kernel's contract.

### 6.3 Logout / session end

When a session ends (logout, token expiry), the plugin's next `resolve()` call returns `null`. The Invoker rejects the Action with `ActorRequired`.

The kernel does not need to know about logout events; pull-based resolution per request handles it naturally.

### 6.4 Long-lived sessions and role changes

If a user's roles change mid-session, the next request's `resolve()` returns an Actor with the updated roles. The cache key changes; new evaluations against the cache miss; new entries are computed and cached. No explicit invalidation needed.

### 6.5 Concurrent sessions for the same user

A user with multiple concurrent sessions (web + mobile) has multiple Actors in flight at any time. Each session resolves independently; each produces a (potentially identical) Actor. The cache key is the same (same `roleHash` for the same user) — caching is at the authorization-profile level, not session level. Two simultaneous Policy evaluations for the same user share cache entries naturally.

---

## 7. Elevation integration

### 7.1 Actor during elevation

Per RFC-003 §10, `Ausus::elevate(targetTenant, reason)` opens an `ElevatedContext` bound to the target Tenant. The Actor remains the same (their home Tenant doesn't change), but the active Tenant Context (which the Policy Engine and Repository consult) is the target.

The Authorization plugin's behavior during elevation:

- `Actor::roles()` and `permissions()` return values for the **target** Tenant (the active Tenant Context). The plugin's per-Tenant resolution honors the active context, not the Actor's home.
- `Actor::ref().homeTenant` remains the Actor's home Tenant (origin, not target).
- `Actor::roleHash()` reflects the target-Tenant roles (the hash differs between elevated and non-elevated calls for users with per-Tenant role differences).

### 7.2 Cache implications

RFC-005 §8.2's cache key includes `tenantId` (which is the target during elevation). So elevated and non-elevated evaluations cache separately by Tenant. Additionally, the cache key includes `elevationOriginTenantId | null` (RFC-005 §8.7), so even if the user has the same roles in both contexts, elevated calls and non-elevated calls cache distinctly.

### 7.3 No special "elevated Actor" type

The Actor's type does not change during elevation. `ActorRef.type` remains `'user'` (or whatever it was). The elevation is an Invoker-context property, not an Actor property. Querying the elevation status from within Policies is done via `Context::elevation()` (RFC-005 §7.1).

### 7.4 Plugin responsibility for cross-Tenant grants

A user's permission to elevate to Tenant T is itself a Policy concern (RFC-003 §10.2: "The Kernel verifies the caller's Actor holds a Policy permitting elevation to target"). The Authorization plugin grants the relevant roles/permissions; the elevation Policy reads them and decides.

### 7.5 No automatic role merging across home + target

V1 does not auto-merge roles across the Actor's home Tenant and the target Tenant during elevation. The plugin returns the target-Tenant roles only. If the deployment wants "elevation grants a special cross-Tenant role," the plugin implements it explicitly (e.g., always grant `cross-tenant.admin` to the elevator during elevation).

---

## 8. Tenant scoping

### 8.1 Roles and permissions are Tenant-scoped by default

In SaaS multi-tenant deployments, the same user may have different roles in different Tenants:

- Tenant A: admin
- Tenant B: viewer
- Tenant C: no roles (excluded from access)

The Authorization plugin handles per-Tenant resolution internally. `Actor::roles()` returns the roles for the active Tenant Context only; the plugin does NOT expose a "list all roles across all my Tenants" method (out of V1; not in the contract).

### 8.2 Resolution mechanism is plugin-internal

How the plugin resolves per-Tenant roles is plugin-internal. Common patterns:

- Database join: `user_roles WHERE user_id = ? AND tenant_id = ?`
- JWT claim: token contains `{tenant: <id>, roles: [...]}`; plugin selects by active Tenant
- API call to an identity provider: plugin queries an external service

V1 does not standardize the mechanism. The plugin contract only requires that `roles()` / `permissions()` return the Tenant-correct values for the active Tenant Context.

### 8.3 Cross-Tenant Actor identity

`ActorRef.id` is the Actor's global identity. The same user ID across Tenants represents the same person. Audit trails (RFC-007) can correlate user activity across Tenants by `ActorRef.id`.

`homeTenant` is the user's primary Tenant. For users belonging to only one Tenant, `homeTenant` is that Tenant. For users with cross-Tenant access (e.g., MSPs, support agents), `homeTenant` is conventional — typically the Tenant where the user was created. The kernel does not enforce a `homeTenant` invariant beyond it being a valid `TenantId`.

### 8.4 System Actor

The `system` Actor (used by Kernel-internal Actions like the audit retry worker, the orphan reconciler, the Tenant catalog operations) has:

- `ActorRef('system', 'system', '__system__')`
- `roles() = []`
- `permissions() = []`
- `attribute()` raises `UnknownAttribute` for any key (no attributes)
- `roleHash() = the empty-input hash` (per §3.6 test vector 1)

The system Actor is constructed by the Kernel itself, not by the Authorization plugin. The plugin's `ActorResolver` MUST NOT return a `system` Actor under any normal request resolution; the system Actor is reserved for Kernel-initiated operations.

---

## 9. Impersonation

### 9.1 Out of V1

Impersonation (an admin acting "as" another user for support purposes) is NOT a first-class kernel feature in V1. The Actor returned by `ActorResolver::resolve()` is the effective Actor; if a deployment implements impersonation, the resolved Actor is the impersonated user, and the impersonator is not visible to the kernel.

### 9.2 Documented workaround

Deployments needing impersonation can implement it at the L4 / Authorization plugin level:

- The L4 layer accepts an `X-Impersonate-User: <user_id>` header from authenticated admins.
- The Authorization plugin's `ActorResolver` constructs an Actor for the impersonated user.
- The plugin records the impersonation event in its own audit trail (separate from RFC-007 audit).
- Standard RFC-007 AuditEntries reflect the impersonated user as the Actor; the impersonator is not in the audit.

This workaround means impersonated Actions are indistinguishable in the audit log from the user's own Actions. For deployments where this is unacceptable, V1 has no remedy.

### 9.3 Future RFC

A future RFC may add an `ImpersonatedActor` extension where `ActorRef` carries both the effective identity and the impersonator's identity, and the audit shape carries an `Impersonation` slot (analogous to RFC-003 §10.5's `Elevation` slot). Out of V1.

---

## 10. Invalidation

### 10.1 Per-request resolution makes invalidation implicit

Pull-based resolution (§5.1) means every Invoker call gets a fresh Actor. If roles change between calls, the next call sees the new roles, the new hash, and a fresh cache lookup.

There is no explicit "invalidate this Actor's cache" API at the kernel level. Cache entries naturally orphan and evict via LRU.

### 10.2 What if the plugin caches internally and serves stale roles?

The plugin's internal cache is the plugin's responsibility. If the plugin caches `(user_id, tenant_id) → roles` for performance, it MUST provide an invalidation path (e.g., on the admin-side role-update endpoint, the plugin clears its internal cache entry).

The kernel does not specify the plugin's internal invalidation mechanism. `ausus:doctor` MAY surface a warning if `Actor::roles()` returns different values for two consecutive calls in the same Invoker invocation (per §2.3 constraint 6 — that's a plugin bug).

### 10.3 Distributed-deployment considerations

If a deployment runs multiple PHP-FPM workers and the plugin caches in-process, role changes are visible to one worker immediately and to others after their cache TTL or explicit invalidation. The kernel does not provide cross-worker coordination.

For deployments needing strong cross-worker consistency, the plugin uses a shared cache (Redis) with TTL-based invalidation. The kernel is agnostic.

---

## 11. `ausus:doctor` checks

The Authorization plugin contributes the following checks beyond the standard kernel and stack checks:

| # | Check                                                                                          | Severity |
|---|------------------------------------------------------------------------------------------------|----------|
| 1 | Exactly one Authorization plugin is bound. (V1: single-plugin constraint.)                     | error    |
| 2 | The bound plugin implements `ActorResolver` correctly (interface check).                       | error    |
| 3 | `Actor` instances produced by the plugin implement all six methods of §2.1.                    | error    |
| 4 | `Actor::roleHash()` is called against the conformance test vectors of §3.6 at boot; mismatch fails. | error |
| 5 | `Actor::roles()` and `Actor::permissions()` return arrays of strings (sample at boot for the system Actor and one test Actor). | error |
| 6 | The plugin's `ActorResolver::resolve()` does not perform I/O during boot-time validation.       | warning  |
| 7 | Static analysis: `Actor` implementation does not import service-container facades.              | warning  |
| 8 | `Actor::attribute()` raises `UnknownAttribute` for keys not in `attributeKeys()` (sample test).| error    |
| 9 | Policies marked `cacheable: true` whose source references `Actor::attribute(...)`.              | warning  |
| 10 | The system Actor has the documented shape (§8.4).                                              | error    |

Items 4, 5, 8, 10 are runtime conformance checks executed during `ausus:doctor` runs. Item 6 is a static-analysis best-effort. Items 7, 9 require AST inspection; may be omitted on minimal doctor builds.

---

## 12. Conformance tests

### 12.1 Test vectors

The §3.6 test vector table defines the byte-identity proof obligations. Conformance tests:

1. Implementations MUST execute every vector and verify byte-identical hash output. Mismatch is a conformance failure.
2. Implementations MAY add additional internal vectors. The §3.6 set is the minimum.
3. The hex values for §3.6 vectors are computed once at acceptance time (§18 criterion 5) and locked. Once locked, they are part of the V1 surface; a vector hash change is a major kernel bump.

### 12.2 Property-based tests

Implementations SHOULD include property tests:

- **Order independence:** `roleHash([r1, r2]) == roleHash([r2, r1])`.
- **Idempotency under duplication:** `roleHash([r1, r1, r2]) == roleHash([r1, r2])`.
- **NFC stability:** `roleHash(['café'])` produces the same hash whether `café` is NFC or NFD pre-normalized.
- **Empty equivalence:** `roleHash([], [])` matches the §3.6 test vector 1.
- **UTF-8 sensitivity:** `roleHash(['Admin'])` ≠ `roleHash(['admin'])` (case-sensitive).

### 12.3 Conformance test suite distribution

The conformance test suite ships as a Composer package `ausus/auth-conformance-tests` (V1 deliverable; not yet built per RFC-000 V0 Real Pass). Plugin authors include it as a `require-dev` dependency and run `composer test` to verify conformance.

### 12.4 Self-test on boot

In addition to the conformance package, every plugin implementing the Authorization contract MUST execute at least the §3.6 vectors at boot. Boot failure on hash mismatch is the design intent: a non-conformant plugin must not silently serve traffic.

---

## 13. Error taxonomy (closed for V1)

```
AuthorizationError                                  (abstract base)
├── ActorRequired                                  (Invoker received null from ActorResolver)
├── ActorContractViolation(class, reason)          (implementation missing methods of §2.1)
├── ActorResolverNotBound                          (no Authorization plugin registered)
├── MultipleActorResolversBound(plugins)           (more than one bound; V1 single-plugin)
├── RoleHashInputInvalid(reason)                   (roles or permissions array contains invalid element)
├── RoleHashMismatch(expected, actual)             (conformance test vector failure)
├── UnknownAttribute(key)                          (Actor::attribute called with unknown key)
├── AttributeValueNotSerializable(key, type)       (attribute value cannot be JSON-encoded)
├── ActorMutationDetected(class, method)           (Actor mutation observed in-call)
├── AuthorizationForbiddenSideEffect(class, op)    (Actor implementation called app() or similar)
└── AuthorizationError.Internal(message)           (plugin invariant violation)
```

All errors extend `AuthorizationError`. Plugin-thrown exceptions from Actor methods are wrapped in `AuthorizationError.Internal(message, cause)`.

Conformance failures (`RoleHashMismatch`, `ActorContractViolation`) fail at boot; runtime calls never see them.

---

## 14. Rejected alternatives

Patterns explicitly rejected for V1. Each rejection is normative — plugin code attempting any is non-conformant.

### 14.1 Multiple Authorization plugins per deployment

**Rejected** for V1. Single-plugin constraint mirrors RFC-002 §14 (single PersistenceDriver) and RFC-010 §2.7 (single ReportingDriver). Multi-plugin would require kernel-level Actor merging logic; out of V1. Future RFC may relax.

### 14.2 RBAC baked into the kernel

**Rejected** by RFC-001 §9.11 and RFC-005 §14.1. Restated.

### 14.3 Permission inheritance / role hierarchies as kernel feature

**Rejected.** Roles/permissions are flat lists at the kernel boundary. Plugins MAY implement role hierarchies internally (a "manager" role implies "viewer"); the resolution happens in the plugin, and `Actor::roles()` returns the flattened set. The kernel sees only the result.

### 14.4 Non-cryptographic hash for `roleHash`

**Rejected.** Faster hashes (xxHash, MurmurHash) were considered but rejected because:

- `roleHash` becomes part of the cache key surface; collisions affect correctness, not just security.
- SHA-256 cost is microseconds per request; not a bottleneck.
- Universal availability in PHP (`hash('sha256')`) without third-party dependencies.

### 14.5 Including the Actor identity in the hash

**Rejected** (§3.2). Caching is at the authorization-profile level, not user level. Same roles → same hash → cache hit, regardless of user identity.

### 14.6 Server-Sent Events / push-based role change notification

**Rejected** for V1. Pull-based per-request resolution is correct and simple. Push-based would require kernel awareness of identity events; complexity without commensurate benefit.

### 14.7 Per-Action role grants ("user can perform Action X")

**Rejected** as a primitive. This is what Policies are for (RFC-005). An Authorization plugin that exposes per-Action grants is a Policy implementation, not an Authorization plugin extension.

### 14.8 Impersonation in V1

**Rejected** as a first-class feature (§9). Deferred to future RFC.

### 14.9 Hash truncation

**Rejected** (§3.7). Full 64-character hex required.

### 14.10 Locale-sensitive sorting

**Rejected** (§3.7). UTF-8 codepoint byte order is the only deterministic cross-implementation sort.

### 14.11 Including attributes in `roleHash`

**Rejected** (§3.2, §5.4). Attributes are out of the hash; attribute-dependent Policies are uncached.

### 14.12 Service-locator access from inside Actor methods

**Rejected.** Symmetric with RFC-005 §10 (Policy side-effect prohibition) and RFC-013 §3.7 (Effect side-effect prohibition). Actor methods MUST NOT call `app()`, `resolve()`, framework facades, or perform I/O. Detection via runtime spy.

### 14.13 Stateful Actor classes

**Rejected.** Actor instances are immutable per request. Mutation between method calls (e.g., a method that increments an internal counter) is detected via §11 doctor check 9 and raises `ActorMutationDetected`.

### 14.14 `actor.can($actionFqn)` method as kernel surface

**Rejected.** The `can` semantics ("can this Actor invoke this Action?") is a Policy evaluation, not an Actor query. Routing it through `Actor` would bypass the Policy Engine. Plugins may expose `can()` as a convenience method, but the kernel does not consume it; Policy Engine consultations are the source of truth.

---

## 15. Trade-offs

1. **Per-request hash computation.** O(n log n) for sorting + O(n) for hashing per request. Negligible for typical role counts (< 100); acceptable for high counts (< 10,000). Beyond that, the plugin should consider truncating granted permissions before exposing.
2. **Attributes out of hash means attribute-dependent Policies are uncached.** Plugin authors writing many such Policies pay a per-evaluation cost. Mitigated by RFC-005 §8.6's `cacheable: false` flag; documented.
3. **SHA-256 over xxHash.** ~2-3× slower for small inputs. Accepted for cross-platform deterministic-availability and zero-collision-risk.
4. **NFC normalization on every role string.** Small per-string cost; benefit is byte-identity across encodings. Accepted.
5. **No impersonation in V1.** Real operational need for support teams; deferred. Acknowledged.
6. **Single Authorization plugin per deployment.** Excludes hybrid deployments (e.g., human users via Spatie + service accounts via a different plugin). Plugin authors implementing both behaviors in one plugin is the V1 workaround.
7. **`Actor::attribute()` keys must be declared via `attributeKeys()`.** Adds a small ergonomic step for ABAC plugins. Benefit: Policy authors and `ausus:doctor` can introspect available attributes.
8. **Test vector hex values locked at acceptance.** Any change after V1 release is a major kernel bump. Acknowledged operational discipline.

---

## 16. Open questions

1. **Future RFC for impersonation.** First-class `ImpersonatedActor` with audit-shape extension. Out of V1.
2. **Future RFC for multi-plugin Authorization.** Hybrid deployments. Out of V1.
3. **Future RFC for `permissionHash` as a separate quantity.** Only if a use case demands. Currently no.
4. **Conformance test suite distribution.** Package `ausus/auth-conformance-tests` to be built; format TBD; CI integration story TBD.
5. **Plugin-author convenience extensions.** `Actor::can()`, `Actor::hasRole()`, `Actor::hasAnyRole()` are common patterns. The kernel doesn't consume them; should the Standard Stack provide them via a default `AbstractActor` base class? Out of this RFC.
6. **Cross-Tenant Actor identity reconciliation.** When the same `ActorRef.id` exists in two Tenants but with different roles, the audit log shows the same user in both Tenants. Cross-Tenant identity correlation is the audit consumer's concern; out of this RFC.
7. **Attribute schema evolution.** When a plugin adds a new attribute key, existing Policies break (UnknownAttribute). The plugin's `attributeKeys()` evolution policy is plugin-internal; documented operational concern.
8. **Locked test vector hash values.** Concrete hex strings to be computed and locked at §18 acceptance. Until then, the §3.6 table shows the canonical inputs without the locked outputs.

---

## 17. Challenger review — attack matrix

Each load-bearing section attacked against: **layer violations**, **Policy bypass**, **Tenant bypass**, **audit bypass**, **cache poisoning**, **hash collision exploit**, **service-locator escape**, **SemVer traps**.

### 17.1 Actor contract (§2)

| Attack | Defence |
|---|---|
| Layer violation: Actor exposes the request object. | The six methods of §2.1 are closed. No additional public surface. Conformance test sample-call verifies no other accessor. |
| Policy bypass: Actor::roles() returns extra roles to inflate access. | Roles are plugin-defined strings; the kernel cannot validate semantic correctness. Plugins returning fictional roles is a plugin-author bug; documented; doctor cannot detect. |
| Tenant bypass: Actor::roles() returns roles for a different Tenant. | §2.3 constraint 5 requires Tenant-scoped resolution. Conformance test difficult; documented as plugin-author responsibility. |
| Audit bypass: ActorRef.id rewritten to hide the actor's identity. | The `id` is what audit records; if the plugin lies about identity, audit lies. Documented as plugin-author trust. |
| Cache poisoning: roleHash crafted to collide with another actor's hash. | SHA-256 collision is computationally infeasible (>2^128 operations). Not a practical attack. |
| Hash collision exploit: same. | Same defence. |
| Service-locator escape: Actor methods call `app()`. | Detected by runtime spy (§11 check 7). Conformance test catches. Static analysis catches at boot. |
| SemVer trap: adding a seventh method to Actor in V1.x. | Adding methods is a minor (additive) bump. Existing Actor implementations remain conformant. Removing methods is major. |

### 17.2 roleHash algorithm (§3)

| Attack | Defence |
|---|---|
| Layer violation: hash depends on Tenant or Clock. | §3.2 explicitly excludes both. Inclusion is a conformance failure. |
| Policy bypass: implementation truncates SHA-256, increasing collision probability to enable poisoning. | §3.7 forbids truncation. Conformance test verifies full 64 hex characters. |
| Tenant bypass: hash includes Tenant, leaking Tenant identity into the cache. | Forbidden (§3.7). |
| Audit bypass: n/a. | — |
| Cache poisoning: hash collision exploited to read another user's cached decisions. | SHA-256 collision resistance is the protection. Not a practical exploit. |
| Hash collision exploit: implementation uses a weaker hash. | §3.7 mandates SHA-256. Conformance test verifies expected vector outputs. |
| Service-locator escape: hash implementation calls `app()`. | Detected per §17.1 service-locator entry. |
| SemVer trap: changing the canonical algorithm post-V1. | Major kernel bump. Conformance test vector hex values are locked at §18. |

### 17.3 Test vectors (§3.6, §12)

| Attack | Defence |
|---|---|
| Layer violation: test vectors exposed in public source code. | Test vectors are part of the V1 public surface; intentional. Disclosure is not a vulnerability. |
| Policy bypass: implementation passes vectors at boot but diverges at runtime. | Doctor's runtime conformance check (§11 #4) can re-execute vectors on demand. |
| Tenant bypass: implementation behaves differently per Tenant. | The vectors are Tenant-independent. Hash computation is per-(roles, permissions) only. |
| Audit bypass: n/a. | — |
| Cache poisoning: vectors crafted to enable collisions. | Vectors are inputs to SHA-256; collisions are infeasible. |
| Hash collision exploit: same. | Same defence. |
| Service-locator escape: n/a. | — |
| SemVer trap: adding new vectors without breaking existing. | Additive minor bumps acceptable. Removing or changing existing vector hex is major. |

### 17.4 Session lifecycle (§6)

| Attack | Defence |
|---|---|
| Layer violation: kernel stores session state. | The kernel does not. Plugin manages session. |
| Policy bypass: stale session continues to authorize after role revocation. | Per-request resolution (§6.1) makes role changes visible on next Invoker call. Cross-worker staleness depends on plugin's internal cache; plugin's responsibility. |
| Tenant bypass: session for Tenant A used to authorize in Tenant B. | Tenant Context is bound from the request (RFC-003 §11), not from the session. Session-Tenant binding is the plugin's responsibility. |
| Audit bypass: session-end events not audited. | Authentication events are out of audit scope (RFC-007). Plugin may audit separately. |
| Cache poisoning: session expires but cached decisions remain. | Cache LRU eviction handles cleanup; cache entries are independent of session lifetime. |
| Hash collision exploit: n/a. | — |
| Service-locator escape: ActorResolver::resolve() calls `app()` to grab session services. | The kernel's runtime spy targets `Actor` method calls. `ActorResolver::resolve()` is necessarily container-aware (it constructs Actors from session data); the spy permits this at the resolver level and forbids it at the Actor-method level. Documented distinction. |
| SemVer trap: changing per-request vs per-session resolution. | Major bump. V1 commits to per-request. |

### 17.5 Elevation integration (§7)

| Attack | Defence |
|---|---|
| Layer violation: Actor consults elevation state directly. | Actor doesn't expose elevation; the plugin's `roles()` resolution may consult the active Tenant Context (which is the target during elevation). Indirect, intentional. |
| Policy bypass: elevation grants implicit roles not visible in `roles()`. | Per §7.5, no automatic merging. Plugin grants cross-Tenant roles explicitly if desired. Visible in `roles()` like any other role. |
| Tenant bypass: elevation lets Actor access non-target Tenants. | Per RFC-003 §10, elevation is scoped to one target Tenant per call. Plugin returns target-Tenant roles only. |
| Audit bypass: elevated calls' Actor identity doesn't match origin. | Per RFC-003 §10.5 + RFC-007 §10, the audit's Elevation slot records the origin Tenant; the Actor's `homeTenant` is preserved (§7.1). Forensic correlation works. |
| Cache poisoning: elevated and non-elevated hash collisions. | Per §7.2, cache key includes both target Tenant and elevation origin (RFC-005 §8.7); cross-context collision impossible. |
| Hash collision exploit: n/a. | — |
| Service-locator escape: plugin's elevation handling uses container access. | Plugin-internal; permitted at the plugin's own boundary. |
| SemVer trap: changing elevation-Actor semantics. | Coupled to RFC-003 §10. Changes require coordinated RFC. |

### 17.6 Tenant scoping (§8)

| Attack | Defence |
|---|---|
| Layer violation: Actor exposes all Tenants the user belongs to. | Out of V1 contract. Plugin may add a method beyond §2.1; the kernel does not consume. |
| Policy bypass: plugin returns roles for wrong Tenant. | Per §2.3 constraint 5. Conformance test difficult to automate; documented as plugin-author responsibility. |
| Tenant bypass: same. | — |
| Audit bypass: AuditEntry.tenant doesn't match the Tenant the roles came from. | The Invoker sets AuditEntry.tenant from the active Tenant Context, not from the Actor. Disagreement caught by RFC-007 §3.3 tenant verification. |
| Cache poisoning: cross-Tenant cache leakage. | Cache key includes tenantId (RFC-005 §8.2); cross-Tenant collision impossible by construction. |
| Hash collision exploit: n/a. | — |
| Service-locator escape: per-Tenant role lookup uses container. | Plugin-internal; permitted at the resolution boundary. |
| SemVer trap: changing the per-Tenant resolution contract. | Major bump. |

### 17.7 Doctor checks (§11)

| Attack | Defence |
|---|---|
| Layer violation: doctor reaches into plugin internals. | Doctor calls public contract methods only. Plugin-internal state is not inspected. |
| Policy bypass: doctor's conformance pass succeeds, runtime diverges. | Doctor checks are sampled; not exhaustive. The runtime spy (§11.3) is the second line. |
| Tenant bypass: doctor runs against one Tenant only. | Doctor runs against the system Tenant typically; plugin-author responsibility for per-Tenant correctness. |
| Audit bypass: doctor failures not audited. | Doctor failures abort boot; audit not relevant. |
| Cache poisoning: n/a. | — |
| Hash collision exploit: doctor's vector check is sampled; collisions in non-sampled vectors. | The §3.6 vector set is mandatory; conformance MUST run all. Doctor surfaces failures. |
| Service-locator escape: doctor uses container to test. | Doctor itself runs in the kernel boot context; uses container as intended. The check itself targets Actor methods. |
| SemVer trap: adding doctor checks in V1.x. | Additive minor; previously-conformant plugins remain conformant unless the new check exposes existing non-conformance, in which case the new check is honest. |

---

## 18. Acceptance criteria

This RFC is accepted when:

1. The three role signatories (architect, kernel, challenger) sign off on §2 (Actor contract), §3 (roleHash algorithm), §5 (caching), §7 (elevation integration), §8 (Tenant scoping), §11 (doctor checks), §12 (conformance tests).
2. The §3.6 test vector table's "to be computed and locked at acceptance" entries are computed using a reference SHA-256 implementation, verified independently, and locked as V1 surface. Once locked, any change is a major kernel bump.
3. RFC-005 §1.3 is updated to reference this RFC as the source of truth for the full Actor contract; the "Authorization-plugin-defined" caveats are tightened to "per RFC-014 §2 contract."
4. RFC-012 §16.5 is updated to remove "Actor extensions beyond minimum (in `ausus/auth-bridge`)" from the provisional list, replacing it with "fixed by RFC-014."
5. The conformance test package `ausus/auth-conformance-tests` is scoped (not built) before V1: at minimum, one test per §3.6 vector plus the property tests of §12.2.
6. `ausus/auth-bridge` (RFC-012 §9) is updated to fully implement the §2 contract and to pass §3.6's conformance test vectors at boot.
7. Doctor checks of §11 are implemented in the `ausus/doctor-bundle` extension (RFC-012 §10).
8. The error taxonomy of §13 is added to the V1 surface alongside RFC-005's PolicyEngineError taxonomy.

Once accepted, this RFC is the source of truth for V1 Authorization plugin contracts.

---

## 19. Determination

**ACCEPT.**

Justification:

- **No new kernel primitives.** Authorization plugin is L7; Actor is consumed by L2 (Policy Engine, Invoker) and L0 (audit emission). Nothing in the kernel changes.
- **Hard rule satisfied.** §3 specifies a canonical algorithm with byte-identity proof obligations. §3.6 test vectors operationalize. §12 conformance tests enforce. §18 acceptance criterion 2 locks the hex outputs as V1 surface.
- **C-V0-02 resolved.** Two Authorization plugins implementing this contract correctly produce the same `roleHash` for the same logical inputs. Cache portability across plugin migrations works.
- **F-V0-04 unblocked.** The Actor contract is fully specified; RFC-005 §1.3's "documented as the expected V1 coupling" gap is closed.
- **Closed error taxonomy** (§13). 11 error types.
- **14 explicit rejections** (§14). Bound the scope tightly.

Conditional notes:

- Acceptance is **specification-level**. Runtime verification requires `ausus/auth-bridge` (RFC-012 §9) to be built and to pass conformance tests. RFC-000 V0 Real Pass demonstrated the package does not exist.
- This RFC unblocks **RFC-000 F-V0-04**. RFC-012 §16.5 should be updated to remove "Actor extensions" from the provisional list.
- **4 of 6 RFC-000 BLOCKERs now addressed at specification level:**
  - F-V0-01: RFC-011 DSL ✓
  - F-V0-02: RFC-013 Effect contract ✓
  - F-V0-03: RFC-006 Workflow runtime ✓
  - F-V0-04: RFC-014 Authorization contract ✓ (this RFC)
  - F-V0-05: 7 reference Composer packages built — still open
  - F-V0-14: `@ausus/renderer-react` npm package built — still open

The remaining blockers are implementation work. The V1 specification surface for the kernel + runtime + Authorization is now complete. The next blocker is engineering, not architecture.

---

## Appendix A — V1 public surface enumeration

```
Ausus\Kernel\Contracts\Authorization\
  Actor                              (interface; §2.1)
  ActorRef                           (final value object; §2.2)
  ActorResolver                      (interface; §2.7)

Ausus\Kernel\Contracts\Authorization\Errors\
  AuthorizationError                 (abstract base)
  ActorRequired,
  ActorContractViolation,
  ActorResolverNotBound,
  MultipleActorResolversBound,
  RoleHashInputInvalid,
  RoleHashMismatch,
  UnknownAttribute,
  AttributeValueNotSerializable,
  ActorMutationDetected,
  AuthorizationForbiddenSideEffect,
  AuthorizationError.Internal
```

11 error types. Closed for V1.

The reference algorithm of §3 is part of the V1 surface; deviations are non-conformant. The §3.6 test vector hex values are V1 surface; change is major.

---

## Appendix B — Worked example: `Acme\Billing\IssuePolicy` reading roles

```php
<?php

namespace Acme\Billing\Policies;

use Ausus\{Policy, Decision};

final class IssuePolicy implements Policy
{
    public function evaluate($actor, $action, $subject, $context): Decision
    {
        // §2.3: roles() returns string[] for the active Tenant
        if (!in_array('invoice.issuer', $actor->roles(), true)) {
            return Decision::Deny;
        }

        // §2.4: attribute() returns plugin-defined value
        // Reading attribute means Policy is cacheable: false (§5.4)
        $plan = $actor->attribute('tenant_plan');  // declared in attributeKeys()
        return $plan === 'active' ? Decision::Permit : Decision::Deny;
    }
}
```

The corresponding PolicyDescriptor declares `cacheable: false` per §5.4:

```php
Policy::make('billing.invoice.policy.issue')
    ->implementedBy(IssuePolicy::class)
    ->cacheable(false);   // attribute-dependent
```

Without `cacheable: false`, the Policy Engine would cache decisions keyed on `roleHash`, which does not include `tenant_plan`. A plan change for an existing Actor would produce stale decisions. The `cacheable: false` flag bypasses the cache.

---

## Appendix C — Proof of byte-identity (informational)

Given the canonical algorithm of §3, two implementations $I_1$ and $I_2$ produce identical `roleHash` outputs iff:

1. They produce identical input arrays after deduplication and sorting. Per §3.3 steps 1–4, the canonical form is deterministic from (roles, permissions); any conforming implementation produces the same canonical arrays.
2. They produce identical canonical JSON bytes. Per §3.3 step 5, the JSON format is fully specified down to escape characters and whitespace; conforming implementations produce byte-identical output.
3. They apply SHA-256 to the same bytes. SHA-256 is a deterministic function; identical inputs produce identical outputs.
4. They encode the SHA-256 output as lowercase hex of exactly 64 characters. Per §3.4, the encoding is specified; conforming implementations produce identical strings.

Therefore $I_1$ and $I_2$ produce byte-identical `roleHash` outputs. QED.

The proof assumes both implementations conform to §3 exactly. Non-conformance breaks the chain at one of steps 1–4; conformance tests of §12 catch every deviation.

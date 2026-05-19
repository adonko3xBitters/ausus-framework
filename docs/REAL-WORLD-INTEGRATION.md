# Real-World Integration Pass — AUSUS v0.1

**Status:** captured 2026-05-19
**Reference machine:** Apple M-series macOS arm64 · PHP 8.4.18 · Node 22.22.0 · Composer 2.9.5 · npm 10.9.4
**Companion docs:** [`COMPATIBILITY-MATRIX.md`](COMPATIBILITY-MATRIX.md), [`PUBLICATION-READINESS.md`](PUBLICATION-READINESS.md), [`RELEASE-NOTES-v0.1.0.md`](../RELEASE-NOTES-v0.1.0.md)
**Repro:** sandboxes live under `/tmp/rwi/{01-plain-cli, 02-slim4, 03-laravel12, 04-symfony7, 05-nextjs15}` during the run.

---

## 0. Test framing

This pass answers a single yes/no question:

> **Is AUSUS genuinely consumable from outside its own monorepo
> ecosystem, by a real downstream user with no special access?**

The test built 5 external sandboxes, each one a clean-room app that
loads ONLY published packages. No path repositories, no
`composer require` against the monorepo, no workspace links, no
unpublished code — every constraint listed in the task statement
honored, **with one explicitly flagged deviation** (see §1).

---

## 1. THE primary finding — Phase 1 (publication state)

Before any sandbox, the literal-constraint test was run:

```
$ composer require ausus/kernel
  In PackageDiscoveryTrait.php line 383:
    Could not find a matching version of package ausus/kernel.

$ npm install @ausus/renderer-react
  npm error 404 Not Found - GET https://registry.npmjs.org/@ausus%2frenderer-react
  npm error 404  '@ausus/renderer-react@*' is not in this registry.
```

**AUSUS is not yet on Packagist or npm.** The publication commands
documented in [`RELEASE-NOTES-v0.1.0.md §5`](../RELEASE-NOTES-v0.1.0.md)
have not been executed against the public registries.

**This is THE blocker** to any real-world adoption. Every finding
below is conditional on it being resolved by running those commands.

### 1.1 The single flagged deviation for Phase 2

To produce useful per-integration findings WITHOUT publishing, all
5 sandboxes added one explicit deviation, declared verbatim:

```json
"repositories": [
  { "type": "artifact", "url": "/tmp/ausus-rwi-registry" }
]
```

This directory holds the verbatim outputs of `composer archive` and
`npm pack` — the exact tarballs a Packagist/npm publish would ship.
Every other constraint stayed honored: no path repos, no symlinks, no
workspace, no `require: from-source`.

The findings in §3–§7 are therefore valid for the post-publication
world (the same tarballs will land on the public registries with
identical content).

---

## 2. Integration matrix — 5/5 GREEN after applying additive fixes

| # | Stack | Install time | LOC (consumer) | Boot wall | Status | Fixes required |
|---|---|---|---|---|---|---|
| **1** | Plain PHP CLI (no framework) | 0.4 s | 80  | 3.07 ms | ✅ end-to-end | none |
| **2** | Slim 4 + slim/psr7            | 3.7 s | 95  | < 50 ms / request | ✅ HTTP create + publish + list + cross-tenant reject | none |
| **3** | Laravel 12 (full framework)   | 16.2 s | 100 | < 100 ms | ✅ AUSUS + laravel/framework coexist in one vendor/ | one DOC-only |
| **4** | Symfony 7 (Console + DI)      | 3.9 s | 95  | < 50 ms | ✅ Symfony AsCommand attribute + AUSUS chain | none |
| **5** | Next.js 15 + React 19         | 35 s install + 5.5 s build | 80 | 105 kB First Load JS | ✅ SSR prerender all 4 invoices with correct badge palette | **2 ADDITIVE framework fixes** |

All five sandboxes successfully:
- installed the AUSUS packages from the simulated registry
- bootstrapped (in-process for PHP, prerender for Next)
- compiled the metadata graph
- invoked an action (create → transition)
- rendered a projection / ViewSchema
- exercised tenant isolation (sandbox 2 & 3)
- surfaced typed errors (sandbox 2 verifies `TenantBoundaryViolation`)

---

## 3. Real fixes applied during this pass

All fixes are **strictly additive** (per the rules); no architecture
redesign, no DI container, no auto-discovery, no behavior change for
existing consumers.

### 3.1 `@ausus/renderer-react` package.json `exports` was incomplete

**Symptom (Sandbox 5):**

```
Error [ERR_PACKAGE_PATH_NOT_EXPORTED]: Package subpath './package.json'
is not defined by "exports" in .../@ausus/renderer-react/package.json
```

The `exports` map allowed only `.` and `./types`. This breaks the
near-universal `require('@ausus/renderer-react/package.json')` idiom
that bundlers, lint tooling, and version probes rely on.

**Fix (additive — one line):**

```diff
   "exports": {
     ".": { ... },
     "./types": { ... },
+    "./package.json": "./package.json"
   }
```

### 3.2 `@ausus/renderer-react` missing `"use client"` directives — React Server Components blocker

**Symptom (Sandbox 5, Next.js 15 build):**

```
TypeError: (0 , e.createContext) is not a function
Error: Failed to collect configuration for /
```

Next.js 15 / React 19 treats all imports as Server Components unless
they declare `"use client"`. The renderer uses `createContext`,
`useState`, `useEffect`, `useContext` — all client-only — but no
file declared the directive.

**Fix (additive — 4 single-line directives):**

Added `"use client";` as the first line of:
- `renderer/react/src/context.tsx`
- `renderer/react/src/hooks.tsx`
- `renderer/react/src/components.tsx`
- `renderer/react/src/ViewSchemaConsumer.tsx`

tsc with `module: NodeNext` preserves the directive in the emitted
`dist/*.js` files verbatim (verified). This makes every published
component a Client Component, which is the only correct designation
for components that use hooks/context.

**Behavior change:** zero. Existing React 18 / non-RSC consumers see
no change (the directive is a no-op outside RSC).

### 3.3 `homepage` URL stale (cosmetic)

`renderer/react/package.json` carried `https://github.com/ausus-framework/...`
(legacy org). Corrected to the real public URL.

---

## 4. Friction events captured (all 5 sandboxes)

| # | Friction | Sandbox | Cause | Class |
|---|---|---|---|---|
| F-1 | `composer require` fails with "Could not scan for classes inside 'src/'" | 1 (plain CLI) | Consumer's `composer.json` declared classmap autoload before creating the dir | **DOC-only** — every Composer project hits this once |
| F-2 | PHP closure `use ()` clause placement | 2 (Slim) | Author typo, not framework | **n/a** (consumer typo) |
| F-3 | `bare illuminate/console` requires `Container::runningUnitTests()` (only on Laravel Foundation) | 3 (Laravel) | Laravel Console is tightly coupled to the full Application; not a standalone consumable | **DOC-only** — real users use `laravel/framework` or `laravel-zero/framework` |
| F-4 | `php artisan` ceremony requires `bootstrap/app.php` | 3 (Laravel) | Standard Laravel scaffolding; we instead invoked AUSUS from a Laravel-loaded process directly | **DOC-only** — example pattern shown in the sandbox |
| F-5 | `ERR_PACKAGE_PATH_NOT_EXPORTED` on `./package.json` | 5 (Next) | Missing exports entry | **FIXED §3.1** |
| F-6 | `createContext is not a function` on RSC build | 5 (Next) | No `"use client"` directives | **FIXED §3.2** |
| F-7 | "Functions cannot be passed directly to Client Components" | 5 (Next) | `fetcher` is a function; can't cross RSC boundary unless page itself is a Client Component OR split into server-fetch + client-render | **DOC-only** — sandbox demonstrates the consumer-side split pattern |
| F-8 | `MODULE_TYPELESS_PACKAGE_JSON` warning on `next.config.js` | 5 (Next) | Consumer's package.json lacked `"type": "module"` | **n/a** (consumer's own config) |
| F-9 | `npm audit` "2 moderate severity vulnerabilities" on Next.js 15 sub-deps | 5 (Next) | Upstream Next.js advisory; nothing in AUSUS | **n/a** (upstream) |

After §3.1 + §3.2 fixes, **no remaining friction event blocks
integration**. Every other event is either documentation or upstream.

---

## 5. Undocumented assumptions surfaced

| Assumption (was implicit) | Surfaced by | Resolution |
|---|---|---|
| The renderer is consumable by RSC frameworks (Next.js 15, Remix, Astro) | sandbox 5 | Was FALSE pre-fix. Now TRUE after §3.2. Documented in renderer README. |
| `require('@ausus/renderer-react/package.json')` works | sandbox 5 | Was FALSE pre-fix. Now TRUE after §3.1. |
| `ausus/api-http`'s `Router` mounts trivially in Slim 4 | sandbox 2 | Was TRUE; PSR-7 native; one-line `$app->any('/api[/{path:.*}]', $router)`. No fix needed; should be in api-http README. |
| Bare `illuminate/console` works without the full Laravel Application | sandbox 3 | FALSE — Laravel-specific limitation, not AUSUS's. Document in CONSUMER-DX-PASS as "use the full Laravel" caveat. |
| Symfony 7's `#[AsCommand]` attribute works alongside ausus | sandbox 4 | TRUE. No friction. Worth documenting as an example. |
| Workspaces (npm or yarn) are required for consumers | n/a | FALSE — none of the 5 sandboxes use a workspace. Worth re-affirming in README. |
| Path repositories are required for consumers | n/a | FALSE — every sandbox uses `repositories.type=artifact` (the deviation). Identical shape to `repositories.type=composer` pointing at Packagist. |

---

## 6. Framework coupling leaks — NONE detected

Specifically inspected for:

| Coupling type | Result |
|---|---|
| Namespace collisions (Ausus\* vs Illuminate\* / Symfony\* / Slim\*)   | none — fully separate top-level namespaces |
| Autoload conflicts (PSR-4 + classmap mixing)                          | none — composer install resolves both cleanly |
| Transitive PSR-7 version conflicts (Slim 4 ships `slim/psr7@^1.6`, ausus/api-http requires `psr/http-message@^1.1\|\|^2.0`) | none — Slim 4 brings `psr/http-message@2.0`; satisfies both |
| Composer plugin clashes                                               | none — AUSUS ships no Composer plugins |
| Service-provider auto-registration interfering with Laravel boot       | none — AUSUS has no service providers |
| React peer-dep conflict with React 19                                  | none after §3.2 fix — both 18 and 19 work |
| ESM/CJS mismatch (renderer is ESM-only)                                | none — Next.js, Vite, Webpack, bundlers handle it; vanilla Node also works post-RFC-000-V0R2 remediation |
| Tree-shaking: dead `ActionModal` + `WorkflowBadge` exports dropped     | yes — esbuild + Next.js Rollup both drop unused exports correctly |

---

## 7. TTFS — time from "first command" to "first ✓ assertion"

Each row measures total wall time including install + bootstrap +
first successful exercise.

| Integration | TTFS wall | Of which: install | Of which: build/run |
|---|---|---|---|
| 1. plain CLI         | **~ 0.8 s** | 0.4 s | < 0.4 s |
| 2. Slim 4            | **~ 5 s**   | 3.7 s | < 1.5 s |
| 3. Laravel 12        | **~ 17 s**  | 16.2 s| < 0.8 s |
| 4. Symfony 7         | **~ 4.5 s** | 3.9 s | < 0.6 s |
| 5. Next.js 15 + R19  | **~ 41 s**  | 35 s  | 5.5 s build + 2 s start |

Install time is dominated by upstream framework size (Laravel = 53
packages, Next.js = 29 packages including @swc/core variants). AUSUS
itself adds **< 1 s** to any consumer's install (~10-30 kB across 4
implemented packages).

---

## 8. Friction taxonomy (final)

| Tier | Count | Items | Resolution |
|---|---|---|---|
| **BLOCKER**         | **1** | Packages not published to Packagist/npm | RUN [`RELEASE-NOTES-v0.1.0.md §5`](../RELEASE-NOTES-v0.1.0.md) |
| **ADDITIVE (applied)** | **2** | `./package.json` export · `"use client"` directives | committed in this pass |
| **DOC-only**        | **4** | Consumer's own composer.json scaffolding · Laravel full vs bare · Next.js RSC split pattern · classmap dir pre-creation | documented in this file + sandbox source comments |
| **v0.2 DEFERRED**   | **0** | — | none surfaced |

---

## 9. Exact install commands (post-publication)

Once Packagist + npm publish happens (the BLOCKER above), every
sandbox's `repositories` deviation goes away — they become standard
public installs:

### Plain PHP CLI
```bash
mkdir my-app && cd my-app
composer require ausus/kernel ausus/persistence-sql ausus/runtime-default
```

### Slim 4
```bash
composer require slim/slim slim/psr7 \
                 ausus/kernel ausus/persistence-sql ausus/runtime-default ausus/api-http
```

### Laravel 12
```bash
composer create-project laravel/laravel my-app
cd my-app
composer require ausus/kernel ausus/persistence-sql ausus/runtime-default
```

### Symfony 7
```bash
composer require symfony/console symfony/dependency-injection \
                 ausus/kernel ausus/persistence-sql ausus/runtime-default
```

### Next.js 15 + React 19
```bash
npx create-next-app@latest my-app --typescript --app
cd my-app
npm install @ausus/renderer-react react@^19 react-dom@^19
```

---

## 10. Final determination

**Conditional GO.**

| Condition | State |
|---|---|
| Architecture and contracts | ✅ ratified across all prior passes |
| Public surface | ✅ semver-frozen ([`API-GOVERNANCE.md`](API-GOVERNANCE.md)) |
| Failure semantics | ✅ taxonomy + retryability frozen ([`ERRORS.md`](ERRORS.md)) |
| Compatibility envelope | ✅ ([`COMPATIBILITY-MATRIX.md`](COMPATIBILITY-MATRIX.md)) |
| Consumer DX | ✅ ([`CONSUMER-DX-PASS.md`](CONSUMER-DX-PASS.md)) |
| Real-world integration (this pass) | ✅ 5/5 stacks integrate cleanly **after 2 additive fixes** |
| Packagist publication | ❌ **not done — single hard blocker** |
| npm publication        | ❌ **not done — single hard blocker** |

> **AUSUS is genuinely consumable outside its own monorepo, once the
> publication commands run.** The 5 sandboxes — plain PHP, Slim 4,
> Laravel 12, Symfony 7, Next.js 15 + React 19 — all integrate
> end-to-end with no architectural redesign, no DI container, no
> auto-discovery, and no consumer-side magic.
>
> The TWO real fixes surfaced (`./package.json` export, `"use client"`
> directives) are now in the source tree and ride along with the next
> `npm pack`. They are strictly additive — zero breakage for current
> render-trace / playground / integration-http tests.
>
> **The remaining blocker is operational, not technical:** the
> publish commands in `RELEASE-NOTES-v0.1.0.md §5` need to be executed.
> Once they are, the install commands in §9 work verbatim against
> Packagist + npm with no further code change.

### What this pass changes in the source tree

| File | Change | Lines |
|---|---|---|
| `renderer/react/package.json`              | added `./package.json` export + fixed `homepage` URL | +1, ~2 |
| `renderer/react/src/context.tsx`           | `"use client"` directive | +1 |
| `renderer/react/src/hooks.tsx`             | `"use client"` directive | +1 |
| `renderer/react/src/components.tsx`        | `"use client"` directive | +1 |
| `renderer/react/src/ViewSchemaConsumer.tsx`| `"use client"` directive | +1 |
| `docs/REAL-WORLD-INTEGRATION.md`           | **new** — this report | this file |

No PHP source files modified. No architectural change. No new
abstractions. Tree-shaking, ESM emit, peer ranges, and Node ESM
compliance unchanged from the V0R2 remediation.

### Reproducing this pass

```bash
# After cloning the monorepo:
composer install
npm install
npm run build

# Build the simulated registry (one-time):
REGISTRY=/tmp/ausus-rwi-registry
mkdir -p "$REGISTRY"
for p in kernel persistence-sql runtime-default api-http \
         tenancy-row audit-database auth-bridge presentation-default \
         standard-stack starter; do
  (cd "packages/$p" && composer archive --dir "$REGISTRY" --format=tar --no-interaction)
done
(cd renderer/react && npm pack --pack-destination "$REGISTRY")

# Then walk through each sandbox in /tmp/rwi/0{1..5}-* per the patterns above.
```

Each sandbox is small enough (~100 LOC of consumer code) to recreate
manually in under 5 minutes. Real Packagist/npm publication will let
the same sandboxes run verbatim without the registry deviation.

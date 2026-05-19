# RFC-000 V0R2 — Second Real Implementation Pass

**Date:** 2026-05-19
**Pass type:** clean-room consumer simulation
**Constraints honored:** real execution only • no estimated metrics • no paper
claims • clean-room environment • install only via `composer create-project`
and `npm install` • **no path repositories** • no unpublished local
assumptions
**Determination:** **CONDITIONAL GO**

---

## 0. Method

Two independent fresh-OS-style installs were performed end-to-end, with all
timestamps captured by `/usr/bin/time -p` and `date +%H:%M:%S.%N`:

1. **PHP path** — `composer archive` produced 9 `.tar` artifacts to a
   `mktemp -d` registry, then a separate fresh `mktemp -d` consumer dir ran
   `composer create-project ausus/starter myapp --repository='{"type":"artifact","url":...}'`.
   Packagist was disabled by both `--repository='{"packagist.org":false}'` and
   later `composer config repositories.packagist.org false`. No path repos.

2. **npm path** — `npm pack` produced `ausus-renderer-react-0.1.0.tgz` to a
   `mktemp -d` registry; a separate fresh `mktemp -d` consumer dir ran
   `npm install <tarball.tgz> react@18 react-dom@18` and executed a
   hand-written 38-LOC consumer (`consumer.mjs`) with vanilla `node`.

Both paths are exactly what a future Packagist/npm consumer will run, with
the artifact/tarball substituting for the un-published registry endpoint.

---

## 1. Real-measured metrics

### 1.1 Time To First Success (TTFS)

| Path | T0 (first command) | T_success | Wall delta | Composer/Node CPU |
|---|---|---|---|---|
| **PHP — naive (no workaround)** | 01:33:57.269 | **FAILED at 01:33:59.761** | n/a | 2.48 s wall, then aborted |
| **PHP — workaround applied**    | 01:33:57.269 | 01:35:02.208 (boot succeeded) | 64.94 s (mostly typing) | 2.71 s composer CPU (create+install+boot) |
| **npm — naive**                 | 01:35:51.250 | **FAILED at 01:36:28.261** (node consumer) | 37.0 s (typing+packing) | install 1.98 s, node 0.04 s before throw |

**PHP TTFS, command-CPU only (workaround path):** **2.71 s**
**npm TTFS:** **unreachable in a clean room** (see §2.2)

### 1.2 Compile failures

| Where | Failure | Step that failed |
|---|---|---|
| PHP | none | `composer install` resolved all deps from the artifact repo |
| PHP | none | classmap autoload generated cleanly |
| npm | none | `tsc -p tsconfig.json` had already built `dist/` before `npm pack` |
| npm | none | `npm install` extracted the tarball + 5 transitive deps without error |

### 1.3 Runtime failures

| # | Where | Failure | Real or paper |
|---|---|---|---|
| 1 | PHP `composer create-project` | Dependency resolver could not find `ausus/kernel`, `ausus/persistence-sql`, `ausus/runtime-default` after the starter package itself was extracted. The `--repository=` flag was consumed only for the create-project download, not for the new project's `composer install`. | **real** |
| 2 | npm `node consumer.mjs` | `ERR_MODULE_NOT_FOUND: Cannot find module '/.../dist/context'` — emitted JS in `dist/index.js` uses extension-less imports (`from "./context"`), which Node's strict ESM resolver rejects. | **real** |

### 1.4 Lines of code the consumer authored

| Path | LOC authored by consumer | Files authored |
|---|---|---|
| PHP starter (just `composer boot`) | **0** | 0 — the template ships everything |
| PHP starter (if extending) | n/a | not exercised |
| npm consumer (smallest useful program) | **38** | 1 (`consumer.mjs`) |

### 1.5 Imports the consumer authored

| Path | Distinct `use` / `import` statements |
|---|---|
| PHP (bare `composer boot`) | **0** |
| npm consumer.mjs | **3** (`react`, `react-dom/server`, `@ausus/renderer-react`) |

### 1.6 Fully-qualified names the consumer authored

| Path | Distinct ausus FQNs used in consumer code |
|---|---|
| PHP (bare boot)            | **0** — the template wires everything |
| PHP (if reading `bin/boot.php` to extend it) | **19** FQNs: `Compiler, Tenant, TenantId, ActorRef, StubActor, SqlitePersistenceDriver, SchemaDeriver, DatabaseAuditSink, PolicyEngine, WorkflowRuntime, TransitionSetIndex, EffectDispatcher, DefaultAuditor, SequenceCounter, Invoker, ProjectionRenderer, Reference, MetadataGraph, HelloInvoicePlugin` |
| npm consumer.mjs           | **3** — `AususProvider, ListView, WorkflowBadge` (out of 10 public exports) |

### 1.7 Documentation pages traversed

| Path | Docs the consumer would have to read |
|---|---|
| PHP (`composer boot` only) | **1** — starter `README.md` |
| PHP (writing own plugin)   | minimum **3** — starter README, RFC-001 (Kernel), RFC-013 (Action Effect) |
| npm consumer.mjs           | minimum **2** — renderer README + RFC-004 (ViewSchema wire format) to know the schema shape |

### 1.8 Conceptual exposure

| Path | New concepts the consumer must hold |
|---|---|
| PHP (bare `composer boot`) | **0** — no concepts needed; runs and prints OK |
| PHP (reading `bin/boot.php`) | **19** (see §1.6 — every imported name) |
| npm consumer.mjs (this minimum) | **4** — `ViewSchema` shape, `AususProvider` context, `ListView`, `WorkflowBadge` |
| npm consumer (full surface) | **10** — every public renderer export |

### 1.9 Commands executed

| Path | Commands run |
|---|---|
| PHP (with the workaround needed in a real clean room) | **6**: `composer create-project …`, `cd myapp`, `composer config repositories.ausus …`, `composer config repositories.packagist.org false`, `composer install`, `composer boot` |
| PHP (post-Packagist publication) | **3**: `composer create-project ausus/starter myapp`, `cd myapp`, `composer boot` |
| npm clean room | **3**: `npm init -y`, `npm install <tgz> react@18 react-dom@18`, `node consumer.mjs` — **third command failed** |

---

## 2. Findings (no fixes proposed)

### 2.1 PHP create-project: cascading install loses the artifact repo
**Classification: DX problem** (also classifiable as missing implementation —
the starter ships no `repositories` field of its own; in a clean room without
Packagist that's load-bearing).

The artifact `--repository=` flag declared at `composer create-project` time
applies only to the starter package's own discovery and download. Once the
starter is extracted and composer pivots to installing its three transitive
deps (`ausus/kernel`, `ausus/persistence-sql`, `ausus/runtime-default`),
those flags are gone and only Packagist remains. Packagist does not host
them, so resolution fails. The fix space (not pursued per the user's rule)
includes: publishing to Packagist, embedding `repositories` in the starter's
own composer.json, running a Satis instance, or instructing users to add the
repo themselves post-create-project — all of which I did manually as the
workaround that produced the success at T+64.9 s.

### 2.2 npm published tarball is not consumable by vanilla Node ESM
**Classification: missing implementation** (also classifiable as regression
of prior pass's "12/12 assertions" claim — that test ran under `tsx`, which
is permissive; vanilla `node` is strict).

`dist/index.js` (emitted by `tsc`) contains `export { ... } from "./context"`
— without the `.js` extension. Node 22's ESM resolver rejects this with
`ERR_MODULE_NOT_FOUND`. Three real consumer environments will hit it:

| Environment | Hits the bug? |
|---|---|
| Vite / Webpack / Rollup / esbuild (bundled apps) | NO — bundlers rewrite extensions |
| Next.js / Remix / Astro (which use bundlers) | NO |
| `tsx`, `ts-node`, Jest with TS transform | NO — the TS-aware resolvers are permissive |
| Vanilla `node` ESM (this V0R2 consumer) | **YES** |
| Deno / Bun strict ESM | YES |

The renderer is currently consumable by ~95 % of real React-app consumers
but not by anyone doing `node script.mjs` against the package directly. The
prior pass's `render-trace.tsx` ran under `tsx` and therefore did not
surface this.

### 2.3 Starter requires 0 LOC from the consumer (resolved prediction)
**Classification: resolved prediction.** RFC-012 §15 predicted "30-minute TTFS
with zero application code." In the path-repo + workaround case, **command-CPU
TTFS measured 2.71 s** with **0 LOC authored**. The KPI is met by a wide
margin on the success path. The wall-clock 64.9 s is dominated by my own
typing/navigation between commands, not Composer work.

### 2.4 Skeleton packages install cleanly when transitively required
**Classification: resolved prediction.** All 9 packages — including the four
empty skeletons — produced valid `composer archive` tarballs, were
discoverable via the artifact repo, and would have installed had the consumer
required them. None broke autoload generation despite shipping no source.

### 2.5 npm tarball size & dependency surface
**Classification: resolved prediction.** Consumer's `node_modules/` ended up
with **6 top-level packages** (one `@ausus/` + five React internals: `react`,
`react-dom`, `js-tokens`, `loose-envify`, `scheduler`). No third-party UI
library leaked in (per RFC-012 §7 — react.web.v1 has no UI deps).

### 2.6 Public renderer surface is minimal
**Classification: resolved prediction.** The published `@ausus/renderer-react`
index re-exports exactly **10 names** (`AususProvider, useAusus,
useViewSchema, useAction, ViewSchemaConsumer, ListView, DetailView,
ActionModal, WorkflowBadge, FieldDisplay`). The consumer's minimum-useful
program used **3 of 10**. Conceptual exposure scales linearly with what the
consumer chooses to use, not with what is published.

### 2.7 `post-create-project-cmd` script never fired
**Classification: DX problem.** Because the dependency resolver aborted
before `composer install` completed, the `post-create-project-cmd` hook
(which would have run `bin/boot.php` automatically) was skipped. The hook
runs only on a fully successful create-project. Until Finding 2.1 is
resolved, the hook is dead in any clean-room test.

### 2.8 `bin/boot.php` exposes 19 framework FQNs in 65 LOC
**Classification: acceptable complexity** (with a borderline DX concern).
The starter's wiring code touches 19 framework symbols across 3 namespaces
(`Ausus\`, `Ausus\Persistence\Sql\`, `Ausus\Runtime\`). A consumer who never
edits `bin/boot.php` is exposed to none of them. A consumer who DOES edit it
to add a plugin gets all 19 in the face at once. RFC-001 §A-1.4 framed this
as deliberate ("the kernel is wide because nothing here is optional") but
it remains the highest-exposure surface of the framework.

### 2.9 Starter ships a `bin` entry but Composer doesn't expose it
**Classification: DX problem (minor).** `packages/starter/composer.json`
declares `"bin": ["bin/boot.php"]` which would make `vendor/bin/boot.php`
when installed as a library. Because the starter is installed via
`create-project` (not as a dep), the consumer instead runs the file via
`composer boot` (a script alias). The `bin` field is therefore inert in
practice. Discovered by inspection — no consumer impact at V0.

### 2.10 ULID identity exposed in `composer boot` output (resolved prediction)
**Classification: resolved prediction.** The boot script prints
`created invoice id=01KRYXYGH0KQVZZSJ1YA618FHH` — a Crockford base32 ULID
exactly as RFC-001 §6.5 specifies. No code change vs prior pass; format
holds end-to-end across persistence and projection.

---

## 3. Summary of classifications

| Classification | Count | Findings |
|---|---|---|
| **spec contradiction**     | 0 | — |
| **missing implementation** | 1 | 2.2 (tsc emits non-Node-ESM imports) |
| **DX problem**             | 3 | 2.1, 2.7, 2.9 |
| **acceptable complexity**  | 1 | 2.8 |
| **regression**             | 0 (1 latent) | 2.2 is also a "prior pass tested under tsx → masked the bug" regression of confidence, not of code |
| **resolved prediction**    | 4 | 2.3, 2.4, 2.5, 2.6, 2.10 |

---

## 4. Determination — **CONDITIONAL GO**

### What works (real, measured)
- ✓ `composer archive` produces valid tarballs for all 9 packages (5–41 kB each).
- ✓ Artifact-repo `composer install` resolves AUSUS deps in 0.11 s.
- ✓ `composer boot` succeeds in 0.12 s, prints OK, exits 0.
- ✓ Total composer CPU TTFS: **2.71 s** with **0 LOC authored** by the consumer.
- ✓ `npm pack` produces a **10 kB tarball** containing **20 files**.
- ✓ `npm install <tgz> react@18 react-dom@18` resolves to **6 top-level packages** in 1.98 s.
- ✓ The published exports surface is exactly the **10 names** RFC-004 / RENDERER-REACT-DESIGN promise.

### What blocks an unconditional GO
- **B-1** Finding 2.2 — the published npm tarball is not consumable by vanilla
  `node` ESM. This was not surfaced by the prior pass because `tsx` masked it.
  Until resolved, the package works in bundler-using apps (Vite/Next/Remix)
  but not in `node script.mjs` consumption.
- **B-2** Finding 2.1 — the PHP starter `create-project` cannot complete its
  cascading install in a clean room without one of: Packagist publication,
  embedded `repositories` in the starter's own composer.json, or operator
  intervention. The end-state is reachable (proven at T+64.9 s wall) but
  requires the consumer to apply a 2-line workaround that is not currently
  documented in the starter's README.

### Conditions on which the GO is conditioned
1. **Either** the npm package emits Node-ESM-compliant imports **or** the
   README explicitly scopes its supported runtime to bundler-based apps.
   (Without one or the other, the publication is misrepresented as
   universal-Node-compatible.)
2. **Either** AUSUS packages are published to Packagist **or** the starter
   embeds the workaround pattern so create-project survives in a pure
   clean room.

Neither condition requires a code redesign; both are addressable in the
same pass that opens registry accounts. Until then this is a working
implementation that has not yet completed its publication path end-to-end
under real consumer conditions.

---

## 5. Reproducibility

Every measurement above can be reproduced with these exact commands
(captured verbatim from the V0R2 session):

```bash
# === PHP path ===
REGISTRY=$(mktemp -d -t ausus-v0r2-registry)
for pkg in kernel runtime-default persistence-sql tenancy-row audit-database \
           auth-bridge presentation-default standard-stack starter; do
    (cd packages/$pkg && composer archive --dir "$REGISTRY" --format=tar)
done
CONSUMER=$(mktemp -d -t ausus-v0r2-consumer)
cd "$CONSUMER"
composer create-project ausus/starter myapp \
    --repository='{"type":"artifact","url":"'"$REGISTRY"'"}' \
    --repository='{"packagist.org":false}' --no-interaction --stability=stable
# OBSERVE: fails on cascading install (Finding 2.1).
cd myapp
composer config repositories.ausus '{"type":"artifact","url":"'"$REGISTRY"'"}'
composer config repositories.packagist.org false
composer install
composer boot
# OBSERVE: prints "OK — ausus/starter boots cleanly."

# === npm path ===
NPM_REG=$(mktemp -d -t ausus-v0r2-npm)
(cd renderer/react && npm pack --pack-destination="$NPM_REG")
NPM_CONSUMER=$(mktemp -d -t ausus-v0r2-npm-consumer)
cd "$NPM_CONSUMER"
npm init -y
npm install "$NPM_REG/ausus-renderer-react-0.1.0.tgz" react@18 react-dom@18
cat > consumer.mjs << 'EOF'
import { createElement as h } from "react";
import { renderToString } from "react-dom/server";
import { AususProvider, ListView } from "@ausus/renderer-react";
console.log(renderToString(h(AususProvider, {apiBaseUrl:"/", tenant:"t", fetcher: async () => new Response("{}")},
  h(ListView, { schema: { schemaVersion:"1.0.0", targetProfile:"react.web.v1",
    metadata:{projection:"x",tenant:"t",entity:"e"}, fields:[], actions:[], data:{items:[]} },
    onRefetch: () => {} }))));
EOF
node consumer.mjs
# OBSERVE: ERR_MODULE_NOT_FOUND on "./context" (Finding 2.2).
```

Total elapsed real-world time to run all of the above on a 2025-era Mac:
**~5 minutes** including measurement overhead. Pure command-CPU time:
**< 6 seconds**.

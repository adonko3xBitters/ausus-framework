# Appendix UX-2 — Standard Stack Audit vs UX-1

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Audit report                                           |
| Authors       | architect, challenger                                  |
| Date          | 2026-05-18                                             |
| Target        | RFC-012 Standard Stack                                 |
| Baseline      | Appendix UX-1 (DX scan against RFC-000 V0)             |
| Scope         | Per-metric delta only. No fixes. No recommendations.   |

This appendix measures the seven brief-mandated DX deltas between UX-1 and the state RFC-012 specifies. Each metric is classified into one of four bands: **Resolved** (metric fully addressed, lands in Excellent or Acceptable), **Improved** (metric better but still problematic), **Unchanged** (no measurable shift), **Regressed** (worse than UX-1).

---

## 0. Method and caveat

UX-1 measured the V0 vertical slice as specified by RFC-000. This appendix measures the same slice as specified by RFC-012's Standard Stack. Both are paper specifications: RFC-000 V0 Real Pass (the prior real-execution attempt) confirmed that the RFC-012 packages return 404 from packagist and npm and that no Standard Stack code exists.

**Every UX-2 number below is the value RFC-012's specification commits to**, not a value observed from running code. The comparison answers: "If RFC-012 were built exactly to spec, what DX deltas would result?" It does not answer "what is the current DX." The current DX is unmeasurable — there is nothing to run.

This framing matters because the deltas reported here are upper-bound improvements. Implementation friction will erode them.

---

## 1. TTFS (time-to-first-screen)

| Source                                                                | Value         |
|-----------------------------------------------------------------------|---------------|
| UX-1 (RFC-000 V0)                                                     | 480 min (~8h) |
| RFC-012 §15.1 target                                                  | ≤ 30 min      |
| RFC-012 §15.3 budgeted breakdown                                      | ~30 min       |

**Delta**: −450 minutes (−94%).

RFC-012 mechanisms producing the delta:
- `composer create-project ausus/starter myapp` ships pre-wired Laravel + Standard Stack + working demo plugin (§12).
- `php artisan ausus:up` (§14) collapses RFC-000 §7 setup steps 4–11 into one invocation: migrate, bootstrap `system` Tenant, bootstrap demo Tenant, bootstrap stub user, compile, doctor.
- 38 defaults (§11.2) make `config/ausus.php` optional in fresh installs; zero config to write before first run.
- Schema derivation from Metadata Graph (§3.4) eliminates per-Entity migration authoring.
- `ausus/plugin-template` scaffolds a working skeleton; author edits inside TODO markers.

**Band**: **Resolved.** The metric moves from Dangerous (480 min, 16× Filament) to Acceptable (30 min, parity with Filament). It does not enter Excellent — Retool's 10-minute baseline is not matched and not targeted.

Caveat: RFC-012 §15.5 makes acceptance conditional on a measured TTFS run; the measurement requires the packages to exist. The current real-world TTFS remains unreachable per RFC-000 V0 Real Pass.

---

## 2. Imports (manually written `use` statements)

| Source                                                                | Value                                           |
|-----------------------------------------------------------------------|-------------------------------------------------|
| UX-1 (RFC-000 V0)                                                     | 38 mandatory imports per first plugin           |
| RFC-012 (plugin template scaffold pre-fills imports)                  | ~5–10 manually written; ~30+ present in template files |

**Delta**: from-scratch unchanged (38); manual-write count down ~75% (estimated 5–10).

RFC-012 mechanisms producing the delta:
- `ausus/plugin-template` (§13) ships file skeletons with imports pre-filled per RFC-012 §13.2's layout.
- Plugin author edits inside class bodies; the imports at the top of each file are pre-existing.
- New imports are written only when the author adds dependencies the template did not anticipate (e.g., custom Field Types, additional kernel contracts).

**Band**: **Improved.** The total import count visible in the plugin's source files is structurally unchanged (the kernel still requires the same interfaces); only the typing burden shifts from "author writes" to "author inherits." The Dangerous-band physical count from UX-1 remains physically present. An author who deletes the template's stub Policies and Effects to start clean must re-type the imports.

This is **not Resolved** because the structural import surface is a kernel property, not a packaging property. RFC-012 reduces the activation cost; it does not reduce the contract count.

---

## 3. FQNs written manually

| Source                                                                | Value                                       |
|-----------------------------------------------------------------------|---------------------------------------------|
| UX-1 (RFC-000 V0)                                                     | 19 unique / ~35 textual                     |
| RFC-012 (template find-replace pattern)                               | 19 unique / ~35 textual (same; values change) |

**Delta**: 0% structural; rename burden shifts from "author invents 19 FQNs" to "author renames 19 FQNs."

RFC-012 mechanisms:
- `ausus/plugin-template` ships TODO-marked FQNs (e.g., `mynamespace.myentity.policy.read`). Author find-replaces `mynamespace` and `myentity` with their domain names.
- Cross-references (Action FQN in DSL + in Workflow `via` + in Projection `actions` list) remain manual: every Action FQN is still written in 3–4 places per RFC-000 §10 friction 10.06.
- PHP class FQNs (Effect classes, Policy classes) remain manually authored in `implementedBy(...)` calls.

**Band**: **Unchanged.** Every Action still needs a Policy FQN, an Effect class FQN, and Workflow + Projection cross-references. The Standard Stack does not change the surface; it provides a starting template. Typo opportunities per Compiler diagnostic (RFC-001 §4.2.3) are unchanged. The Dangerous-band classification persists.

---

## 4. Compile failures before happy path

| Source                                                                | Value                                       |
|-----------------------------------------------------------------------|---------------------------------------------|
| UX-1 (RFC-000 V0) — novice                                            | 5–12 first-trial failures                   |
| UX-1 (RFC-000 V0) — guided                                            | 0–3 first-trial failures                    |
| RFC-012 — novice (with defaults + template + doctor)                  | ~2–5 first-trial failures                   |
| RFC-012 — guided (HelloInvoice demo follower)                         | ~0–1 first-trial failures                   |

**Delta**: novice −50–60%; guided −67–100%.

RFC-012 mechanisms producing the delta:
- 38 defaults (§11.2) eliminate the most common novice error class: missing or malformed `config/ausus.php` keys (~5 distinct failure modes per RFC-005 §13, RFC-007 §13, RFC-010 §11.1).
- Auto-binding via service providers eliminates "missing PersistenceDriver / ReportingDriver / primary sink binding" failures.
- `ausus:doctor` from `ausus/doctor-bundle` (§10) runs ~30 stack-specific health checks and surfaces errors before the developer hits them at runtime.
- `ausus/plugin-template`'s pre-filled skeletons produce compilable code from line 1; common authoring mistakes (wrong Policy interface signature, missing `implementedBy`) are scaffolded correctly.
- Schema derivation eliminates migration-order failures (no separate migration file to be out of sync with the Entity descriptor).

Residual failure modes (the 2–5 a novice still hits):
- Typos in author-authored FQNs (Compiler dangling-reference rejections per RFC-001 §4.2.3).
- TODO markers left unfilled (Compiler validation failures).
- Misnamed Effect class FQN (Policy resolution failures via §6.2 de facto contract).
- Auth bridge misconfiguration in mode transitions (dev stub → prod laravel).

**Band**: **Improved.** Novice failures drop into the same range as guided execution. Not Resolved — author-typo failures are not eliminated by packaging.

---

## 5. Concepts exposed before first render

| Source                                                                | Value      |
|-----------------------------------------------------------------------|------------|
| UX-1 (RFC-000 V0)                                                     | ~100       |
| RFC-012 (defaults hide, template scaffolds, demo demonstrates)        | ~25        |

**Delta**: −75 concepts (−75%).

RFC-012 mechanisms producing the delta:
- **Defaults eliminate must-learn config concepts.** The 38 defaulted config keys (§11.2) do not need to be understood to start; they need to be understood only to override. Removes ~25 concepts from the first-render path (audit sink lifecycle, retry queue, reconciliation window, reporting timeouts, policy cache sizes, etc.).
- **`ausus:up` hides bootstrap concepts.** Tenant catalog, `system` Tenant, demo Tenant, stub user creation, compilation, doctor — all hidden behind one command. Removes ~15 concepts from the first-render path (Tenant lifecycle states, catalog operations, override version mechanics, Tenant resolver registration per context).
- **`HelloInvoice` demo demonstrates rather than documents.** A working example replaces conceptual reading for first-render. Removes ~15 concepts (audit primary sink, secondary sinks, retry queue mechanics, External vs Transactional sinks, reconciliation, all kernel sentinels in the `kernel.*` namespace, ViewSchema profile negotiation internals).
- **Plugin template's TODO markers guide the smallest viable concept set.** What remains: ~25 concepts the author must understand to author **their own** first plugin (Plugin lifecycle, DSL chain, ~6 Field types, ActionEffect interface, Policy + Subject + Decision + Context + Actor, identity handle, `_version`, Workflow declaration, Projection declaration, FQN convention).

**Residual ~25 concepts**: Plugin/ServiceProvider/PluginLifecycle triple-interface; DSL chain (Entity, Field, Action, Policy, Workflow, Projection); Field types in active use (~6); ActionEffect interface (provisional per §6.2); Policy interface and its four parameters; canonical reference tuple; reserved `_version`; Workflow states/transitions; Projection field/action/filter/policy slots; FQN dot-notation; reserved namespaces (`kernel.*`, `__ausus_*`, `_<name>`).

**Band**: **Improved.** Moves from Adoption-blocker (~100) to Dangerous (~25, still ~2× peer norm of ~15). The kernel's conceptual surface cannot be shrunk by packaging; the Standard Stack hides what it can but cannot eliminate the residue an author must internalize.

Not Resolved. Author still faces a larger concept set than Filament or Nova on day one.

---

## 6. Docs traversed outside the slice itself

| Source                                                                | Value                                  |
|-----------------------------------------------------------------------|----------------------------------------|
| UX-1 (RFC-000 V0)                                                     | 7 RFCs / ~70,000 words                 |
| RFC-012 (first-screen path)                                           | 2 READMEs / ~1,000–2,000 words         |
| RFC-012 (first own plugin path)                                       | 2 READMEs + 1 RFC consultation likely  |

**Delta**: −98% for first-screen-of-demo; −95% for first-own-plugin.

RFC-012 mechanisms producing the delta:
- `ausus/starter` ships a README with a 5-minute orientation (§12.1, §15.3 step 4 budget).
- `ausus/plugin-template` ships a README explaining TODOs and the local-path-install workflow (§13.4).
- The HelloInvoice demo is self-documenting code: an author copies and modifies.
- The two READMEs together cover the happy path; RFC consultation becomes needed only for: (a) deviating from defaults, (b) writing non-trivial Policies that depend on `Actor` extensions, (c) debugging unfamiliar `ausus:doctor` warnings.

Residual RFC consultation likely:
- For first own Policy: probably consult RFC-005 §2 (signature) — but the template's sample Policy may suffice.
- For Field type choice: probably consult Standard Stack §7 table — but Field type list is enumerable.

**Band**: **Resolved** for first-screen path. **Improved** for first-own-plugin path (one likely RFC consultation, not seven).

The 7-RFC traversal of UX-1 was the single most operationally severe burden; RFC-012 neutralizes it for the happy path. RFC consultation becomes the **exception**, not the default. Authors deviating from defaults or doing non-trivial work still face the full 70k-word surface, but that is the exception class.

---

## 7. Boilerplate LOC

| Source                                                                | Value                                      |
|-----------------------------------------------------------------------|--------------------------------------------|
| UX-1 (RFC-000 V0) — infrastructure (excl. DSL + tests + frontend)     | 330 lines                                  |
| RFC-012 — infrastructure (with template + schema derivation)          | ~230 lines                                 |

**Delta**: −100 lines (−30%).

RFC-012 mechanisms producing the delta:

| Component                                | UX-1 | RFC-012 | Mechanism                                                      |
|------------------------------------------|------|---------|----------------------------------------------------------------|
| `composer.json` (Composer + AUSUS extra) | 30   | ~25     | Template ships pre-filled                                       |
| Plugin/ServiceProvider class             | 60   | ~50     | Template ships skeleton; author fills DSL chain                 |
| Policy implementations (4)               | 90   | ~85     | Template ships one sample Policy; author duplicates and modifies |
| Action effect implementations (3)        | 110  | ~70     | Template + simple Workflow runtime (§6.3) removes per-effect state-mutation code |
| Migration                                | 40   | **0**   | Schema derivation from Metadata Graph (§3.4) eliminates the file |
| **Subtotal**                             | 330  | ~230    |                                                                |

Largest single reduction: **migration file elimination** (40 LOC) via schema derivation.

Second-largest: **Effect simplification** (~40 LOC) because the simple Workflow runtime (§6.3) handles state transitions; effects no longer mutate state columns directly.

Smaller reductions from template scaffolding for composer.json, ServiceProvider, and Policy classes.

**Band**: **Improved.** The metric drops from 330 → ~230 LOC (still ~1.5× peer norm of ~150 LOC per RFC-000 §8). UX-1's Dangerous classification stands, but the gap narrows.

Per-Entity boilerplate beyond the first (RFC-000 §8's "amortization" claim) is not separately measured here. The improvement reported is for the first Entity only.

Not Resolved. The Standard Stack reduces; it does not eliminate. Per-Action Effect class scaffolds and per-Projection mandatory Policies are kernel-level requirements that no packaging can dissolve.

---

## 8. Aggregate scorecard

| # | Metric                    | UX-1 (Dangerous classification)        | UX-2 (specified)        | Delta             | Band         |
|---|---------------------------|----------------------------------------|-------------------------|--------------------|---------------|
| 1 | TTFS                      | 480 min                                | ≤30 min                 | −94%              | **Resolved**  |
| 2 | Imports (manual)          | 38                                     | ~5–10                   | ~−75%             | **Improved**  |
| 3 | FQNs (unique)             | 19                                     | 19                      | 0%                | **Unchanged** |
| 4 | Compile failures (novice) | 5–12                                   | 2–5                     | ~−55%             | **Improved**  |
| 5 | Concepts exposed          | ~100                                   | ~25                     | ~−75%             | **Improved**  |
| 6 | Docs traversed            | 7 RFCs / ~70k words                    | 2 READMEs / ~1–2k words | ~−98% (happy path)| **Resolved**  |
| 7 | Boilerplate LOC           | 330                                    | ~230                    | ~−30%             | **Improved**  |

### Band distribution

| Band       | Count | Metrics                                                    |
|------------|-------|------------------------------------------------------------|
| Resolved   | 2     | TTFS (#1), Docs traversed (#6, happy path only)            |
| Improved   | 4     | Imports (#2), Compile failures (#4), Concepts (#5), LOC (#7) |
| Unchanged  | 1     | FQNs (#3)                                                  |
| Regressed  | 0     | —                                                          |

---

## 9. Findings

1. **RFC-012 neutralizes the two most operationally severe UX-1 findings.** TTFS (8h → 30min) and documentation traversal (7 RFCs → 2 READMEs) move from Dangerous-band into Acceptable. The two metrics that determined first-impression DX failure are addressed.

2. **RFC-012 improves four of the seven metrics into "still Dangerous, but less so" territory.** Imports, compile failures, concept exposure, and boilerplate LOC all drop by 30–75%. None enters Acceptable band; all remain above peer-platform norms. The amortization-after-first-Entity argument from RFC-000 §8 is not measured here.

3. **FQN count is unchanged.** Every Action still requires a Policy FQN, an Effect FQN, and Workflow/Projection cross-references. The Standard Stack provides a starting template but does not reduce the structural FQN surface. This is the only kernel-property metric in the seven; the others are packaging-properties or activation-cost properties.

4. **The strongest UX-1 finding (concept count, the only Adoption-blocker band) is reduced but not resolved.** ~25 concepts to author a first plugin is still ~2× peer norm. The kernel's conceptual surface is not compressible by packaging; the Standard Stack hides defaulted config and scaffolds boilerplate, but the irreducible domain-modelling vocabulary (Plugin, Entity, Field, Action, Policy, Workflow, Projection, Subject, Decision, Context, Actor, identity handle, `_version`, FQN conventions, reserved namespaces) must still be internalized.

5. **No metric regressed.** The Standard Stack does not worsen any of the seven measured dimensions. New surface introduced by the Standard Stack itself (auth.mode configuration, ausus:up command, HelloInvoice demo as concept to delete-or-copy) does not appear in any of the seven measurements as a regression. It does add to the residual ~25 concepts of metric #5 but the net is a reduction.

6. **The deltas are entirely conditional on the Standard Stack being built.** Every UX-2 value above is specified, not observed. RFC-000 V0 Real Pass demonstrated that the prerequisite packages (`ausus/kernel`, `ausus/standard-stack`, `ausus/starter`, `@ausus/renderer-react`, transitively the seven other Composer packages) return 404 from packagist and npm. Until those packages are published as installable, stable versions, the actual delta against UX-1 is **zero on all seven metrics**.

7. **Four provisional contracts (RFC-012 §16.5) are not reflected in any of the seven metric deltas.** When RFC-006 (Workflow execution), RFC-011 (DSL surface), the ActionEffect formal RFC, and the Authorization plugin RFC land, the affected `ausus/runtime-default` and `ausus/auth-bridge` packages will release major bumps. Plugin authors who built against the Standard Stack between V1 release and those RFC landings will face refactor cost not measured here. This is a debt accrued by the metrics' improvement; it is not visible in the seven measurements.

End of audit.

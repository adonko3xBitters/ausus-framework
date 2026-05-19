# RFC-000 — V0 Real Implementation Pass

| Field         | Value                                                  |
|---------------|--------------------------------------------------------|
| Status        | Real implementation report                              |
| Authors       | architect, challenger                                  |
| Date          | 2026-05-18                                             |
| Attempted     | RFC-000 V0 vertical slice against RFC-012 Standard Stack |
| Mission       | Execute the slice end-to-end with real commands, real packages, real timings, no simulation. |

---

## 0. Method

Per the brief: no simulations, no estimated timings, no hypothetical code. Every measurement below is either an observed execution result or `0` / `N/A` because execution did not reach that step. Where execution failed, the failure was captured verbatim. No values are inferred or extrapolated.

The environment used:

```
Host:       Darwin 25.3.0
PHP:        8.4.18
Composer:   2.9.5
Node:       (available via ServBay)
npm:        (available via ServBay)
psql:       (available via ServBay)
Working dir: /Users/adonko3xbitters/Desktop/SIDE PROJECTS/Framework AUSUS/
```

The toolchain is available and verified. The slice is RFC-000 §1 against the Standard Stack of RFC-012.

---

## 1. Attempted execution

### Step 1 — `composer create-project ausus/starter` (RFC-012 §15.3 item 1)

```
$ cd /tmp && composer create-project ausus/starter ausus-real-pass-1779126338
Creating a "ausus/starter" project at "./ausus-real-pass-1779126338"

In CreateProjectCommand.php line 429:

  Could not find package ausus/starter with stability stable.
```

**Step 1 failed.** Composer cannot locate `ausus/starter` on packagist. No further RFC-012 steps are reachable.

### Auxiliary verification — packagist and npm presence

| Package                          | Endpoint                                                       | HTTP status |
|----------------------------------|----------------------------------------------------------------|-------------|
| `ausus/kernel`                   | https://repo.packagist.org/p2/ausus/kernel.json                | **404**     |
| `ausus/standard-stack`           | https://repo.packagist.org/p2/ausus/standard-stack.json        | **404**     |
| `ausus/starter`                  | https://repo.packagist.org/p2/ausus/starter.json               | **404**     |
| `@ausus/renderer-react`          | https://registry.npmjs.org/@ausus/renderer-react               | **404**     |

All four prerequisite packages from RFC-012 §2 return 404. Spot checks of the remaining seven Composer packages (`ausus/persistence-sql`, `ausus/tenancy-row`, `ausus/audit-database`, `ausus/reporting-sql`, `ausus/runtime-default`, `ausus/field-types-standard`, `ausus/auth-bridge`, `ausus/doctor-bundle`, `ausus/plugin-template`) and the `ausus/renderer-react` Composer half were not necessary: if `ausus/standard-stack` does not exist, transitive requirements cannot exist either.

### Auxiliary verification — local source

```
$ ls "/Users/adonko3xbitters/Desktop/SIDE PROJECTS/Framework AUSUS/"
AGENTS.md  CLAUDE.md  conversation_filament_framework.pdf  rfcs/
```

The working directory contains **only**: two configuration files, the source PDF, and the `rfcs/` directory. There is no `src/`, no `composer.json`, no `package.json`, no kernel source code, no Standard Stack source code, no starter template source, no plugin template source. The repository is RFC-only.

```
$ ls rfcs/
RFC-000-v0-vertical-slice.md     RFC-001-amendment-01.md
RFC-001-amendment-02.md           RFC-001-kernel.md
RFC-001-review-01.md              RFC-002-persistence-driver.md
RFC-003-tenancy.md                RFC-004-viewschema.md
RFC-005-policy-engine.md          RFC-007-amendment-01.md
RFC-007-audit.md                  RFC-010-reporting-maintenance.md
RFC-012-standard-stack.md         appendices/
```

13 RFC documents + 1 appendix. Zero lines of implementation code.

---

## 2. Measurements

Each of the ten brief-mandated measurements, observed:

| # | Measurement                                                              | Observed value                              |
|---|--------------------------------------------------------------------------|----------------------------------------------|
| 1 | Wall-clock time from `composer create-project` to first browser render   | **N/A** — step 1 failed at second 0 of TTFS |
| 2 | Number of commands executed                                              | **1** (`composer create-project ausus/starter ...`) |
| 3 | Number of files edited manually                                          | **0**                                        |
| 4 | Number of compile failures                                               | **0** (compile never reached)                |
| 5 | Number of runtime failures                                               | **0** (runtime never reached)                |
| 6 | Number of docs consulted outside starter README                          | **N/A** — starter README does not exist     |
| 7 | Number of lines written by developer                                     | **0**                                        |
| 8 | Number of FQNs written manually                                          | **0**                                        |
| 9 | Number of imports written manually                                       | **0**                                        |
| 10 | Number of moments where kernel internals had to be understood            | **0** (none reachable; would have been many) |

No measurement was estimated. Where the step that would produce the measurement was not reached, the value is `N/A` rather than a guess.

---

## 3. Findings

### F-RP-01 — `ausus/starter` is not published

**Observed**: HTTP 404 from `https://repo.packagist.org/p2/ausus/starter.json` and from `composer create-project ausus/starter`.

**Consequence**: RFC-012 §15.3 item 1 cannot execute. The entire TTFS budget begins after this step; it never starts.

### F-RP-02 — `ausus/standard-stack` is not published

**Observed**: HTTP 404 from `https://repo.packagist.org/p2/ausus/standard-stack.json`.

**Consequence**: Even if `ausus/starter` existed, its transitive dependency on the meta-package would fail. The Standard Stack is a specification, not a release.

### F-RP-03 — `ausus/kernel` is not published

**Observed**: HTTP 404 from `https://repo.packagist.org/p2/ausus/kernel.json`.

**Consequence**: Even bypassing the starter and the meta-package, a hand-authored plugin cannot `composer require ausus/kernel` because the kernel package itself does not exist.

### F-RP-04 — `@ausus/renderer-react` is not published on npm

**Observed**: HTTP 404 from `https://registry.npmjs.org/@ausus/renderer-react`.

**Consequence**: Even with a hypothetical backend, the React renderer cannot be installed in any `frontend/` directory.

### F-RP-05 — The working repository contains zero implementation code

**Observed**: The repository at `/Users/adonko3xbitters/Desktop/SIDE PROJECTS/Framework AUSUS/` contains only RFC documents (13 specs + 1 appendix), one mission PDF, and two configuration files. No `src/`, no `composer.json`, no PHP source, no JavaScript source, no migrations, no tests, no published package skeleton.

**Consequence**: Even installing locally via `composer config repositories.local path ./packages` would fail because there are no local packages to point at. Nothing exists to vendor.

### F-RP-06 — RFC-012 was accepted as a packaging specification, not an implementation release

**Observed by document inspection**: RFC-012 §19 acceptance criteria #3 requires "All ten Composer packages and one npm package are built and published." This gate has not been met. RFC-012 acceptance #2 requires a measured TTFS run within ≤ 35 minutes; that measurement requires the packages of #3 to exist first.

**Consequence**: RFC-012 itself is in a pre-acceptance state with respect to its own §19. The Standard Stack is specified and the package map is defined, but no implementation engineering has begun.

### F-RP-07 — The seven RFC-000 BLOCKER findings remain unaddressed at the implementation level

RFC-000 (paper pass) listed six blockers (F-V0-01 through F-V0-05 plus F-V0-14). Five of them (F-V0-02 through F-V0-05, F-V0-14) are blockers that RFC-012 commits to resolve at the implementation layer. RFC-012 §6 (runtime-default), §3 (persistence-sql), §5 (audit-database), §8 (renderer-react), and §9 (auth-bridge) describe the resolutions; none are built. The sixth (F-V0-01, RFC-011 DSL surface) is acknowledged in RFC-012 §16.5 as provisional and remains unresolved.

**Consequence**: The blockers RFC-000 identified persist. RFC-012 documents the resolution path but does not walk it. This real-pass attempt confirms zero progress between RFC-000's paper determination and now.

---

## 4. Determination

**BLOCKED.**

The implementation pass terminated at second 0 of the TTFS budget. The single failed `composer create-project` invocation is sufficient proof: the prerequisite packages required by RFC-012 do not exist as published artifacts. They are specified in RFC-012, but specification is not publication.

No alternative path was attempted:

- Hand-authoring a plugin against `ausus/kernel`: blocked by F-RP-03.
- Hand-authoring the entire kernel: outside the scope of "execute the slice end-to-end" and would itself be a multi-month engineering effort, not a real-pass attempt.
- Local-path-repository installation: blocked by F-RP-05 (no local packages exist).
- Building a stub kernel just to render one screen: would constitute simulation, explicitly forbidden by the brief.

Per the brief's hard rule ("If BLOCKED: findings only. No fixes."), no remediation is proposed in this RFC.

The findings (§3) report the present state. The unblocking path is documented in RFC-012 §19 and is implementation work, not specification work. Until RFC-012's acceptance criteria #3 is met (the packages are built and published), this RFC's determination remains **BLOCKED**.

A subsequent real-pass attempt should be scheduled only after the eleven packages of RFC-012 §2 + Appendix A are published with installable, stable versions and after `composer create-project ausus/starter` returns a working project tree.

#!/usr/bin/env bash
#
# AUSUS — release line manifest (single source of truth)
# ------------------------------------------------------
# Sourced by scripts/release-publish.sh, scripts/release-gate.sh, and the
# packagist-validation / npm-publish workflows. It defines, per release LINE,
# the Composer packages (with their topological split levels), the required
# release branch, and the npm renderer package — so every part of the pipeline
# agrees on what "the release" contains.
#
# Two independent lines coexist in this monorepo:
#
#   legacy — the standard-stack line (ausus/standard-stack + 1.x packages,
#            @ausus/renderer-react). Unchanged historical behaviour.
#   gen2   — the Entity Engine line (AUSUS 2.0: ausus/kernel Definition/Contracts/
#            Compiled, entity-engine, authoring, persistence-memory, api-runtime,
#            view-system, cli, @ausus/react-renderer).
#
# Selection: RELEASE_LINE=legacy|gen2  (default: legacy, to preserve the prior
# default for any caller that does not set it). The line can also be derived
# from a version's major component via `ausus_line_for_version vX.Y.Z`
# (major >= 2 → gen2, else legacy).
#
# After `ausus_release_line <line>` the following are exported / set:
#   RELEASE_LINE_NAME        — resolved line name
#   RELEASE_REQUIRED_BRANCH  — branch the publish script must run from
#   RELEASE_LEVELS[]         — "level label:pkg pkg pkg" (leaves first)
#   RELEASE_ALL[]            — flat package list (leaves first)
#   RELEASE_NPM_DIR          — npm renderer working directory
#   RELEASE_NPM_PKG          — npm renderer package name

ausus_release_line() {
    local line="${1:-legacy}"
    case "$line" in
        legacy)
            RELEASE_LINE_NAME=legacy
            RELEASE_REQUIRED_BRANCH="${RELEASE_REQUIRED_BRANCH:-main}"
            RELEASE_LEVELS=(
                "L1 leaves (no ausus deps)|audit-database auth-bridge kernel presentation-default tenancy-row"
                "L2 (deps: kernel)|persistence-postgres persistence-sql runtime-default"
                "L3 (deps: kernel+runtime)|api-http"
                "L4 (bundle)|standard-stack"
                "L5 (template)|starter"
            )
            RELEASE_NPM_DIR="renderer/react"
            RELEASE_NPM_PKG="@ausus/renderer-react"
            ;;
        gen2)
            RELEASE_LINE_NAME=gen2
            RELEASE_REQUIRED_BRANCH="${RELEASE_REQUIRED_BRANCH:-main}"
            RELEASE_LEVELS=(
                "L1 leaves (no ausus deps)|kernel view-system"
                "L2 (deps: kernel)|authoring entity-engine persistence-memory"
                "L3 (deps: kernel+entity-engine)|api-runtime"
                "L4 (deps: kernel+authoring+entity-engine)|cli"
            )
            RELEASE_NPM_DIR="packages/react-renderer"
            RELEASE_NPM_PKG="@ausus/react-renderer"
            ;;
        *)
            echo "::error::unknown RELEASE_LINE: '$line' (expected legacy|gen2)" >&2
            return 1
            ;;
    esac

    RELEASE_ALL=()
    local entry pkgs
    for entry in "${RELEASE_LEVELS[@]}"; do
        pkgs="${entry#*|}"
        # shellcheck disable=SC2206
        RELEASE_ALL+=($pkgs)
    done
    export RELEASE_LINE_NAME RELEASE_REQUIRED_BRANCH RELEASE_NPM_DIR RELEASE_NPM_PKG
}

# Derive the line from a version string: major >= 2 → gen2, else legacy.
ausus_line_for_version() {
    local v="${1#v}"
    local major="${v%%.*}"
    case "$major" in
        ''|*[!0-9]*) echo legacy ;;   # non-numeric → legacy default
        *) [ "$major" -ge 2 ] && echo gen2 || echo legacy ;;
    esac
}

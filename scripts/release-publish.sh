#!/usr/bin/env bash
#
# AUSUS — Coordinated subtree-split publish to rel-* repos
# --------------------------------------------------------
# Line-aware: publishes EITHER the legacy standard-stack line OR the Gen2
# Entity Engine line (AUSUS 2.0), selected by RELEASE_LINE (default: legacy).
# The package set + topological levels + required branch come from the shared
# manifest scripts/release-packages.sh, so both lines publish independently.
#
# Pre-flight enforces HEAD=<required branch>, clean tree, synced with origin.
# Phase 0 validates the line's rel-* remotes are reachable.
# Phase A regenerates all subtree splits FROM the required branch (deterministic).
# Phase B detects tag collisions on remotes (idempotent re-run safe).
# Phase C tags + pushes per topological level.
# Phase D cleanup local split branches (via trap EXIT).
#
# Tags are IMMUTABLE on remotes. This script never force-pushes tags.
#
# Usage:   scripts/release-publish.sh v2.0.0
# Env:     RELEASE_LINE=legacy|gen2   select the line (default: legacy)
#          DRY_RUN=1                  skip pushes/network gates, print intent
#          RELEASE_REQUIRED_BRANCH=…  override the required branch (default main)

set -euo pipefail

VERSION="${1:?usage: release-publish.sh v<X.Y.Z>}"
DRY_RUN="${DRY_RUN:-0}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

if ! [[ "$VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[a-z0-9.]+)?$ ]]; then
    echo "::error::invalid version: $VERSION (expected v<X.Y.Z>[-pre.N])"
    exit 1
fi

cd "$ROOT"

# ─── Line manifest: package set + topological levels + required branch ───────
# shellcheck source=scripts/release-packages.sh
. "$ROOT/scripts/release-packages.sh"
ausus_release_line "${RELEASE_LINE:-legacy}"
ALL=("${RELEASE_ALL[@]}")
echo "[publish] line=$RELEASE_LINE_NAME  packages=${#ALL[@]} (${ALL[*]})  required-branch=$RELEASE_REQUIRED_BRANCH  dry-run=$DRY_RUN"

warn() { echo "  ! $*"; }

# ─── Pre-flight: HEAD=<branch>, clean tree, synced with origin ───────────────
ORIGINAL_BRANCH=$(git symbolic-ref --short HEAD 2>/dev/null || git rev-parse HEAD)
preflight_fail() {
    if [ "$DRY_RUN" = "1" ]; then warn "DRY-RUN: $*"; else echo "::error::$*"; exit 1; fi
}

[ "$ORIGINAL_BRANCH" = "$RELEASE_REQUIRED_BRANCH" ] || \
    preflight_fail "release-publish.sh requires HEAD=$RELEASE_REQUIRED_BRANCH (currently: $ORIGINAL_BRANCH)"

if ! git diff --quiet || ! git diff --cached --quiet; then
    preflight_fail "working tree dirty (uncommitted changes)"
fi
if [ -n "$(git ls-files --others --exclude-standard)" ]; then
    preflight_fail "working tree has untracked files"
fi

if [ "$DRY_RUN" != "1" ]; then
    git fetch origin "$RELEASE_REQUIRED_BRANCH" --quiet
    LOCAL_SHA=$(git rev-parse HEAD)
    REMOTE_SHA=$(git rev-parse "origin/$RELEASE_REQUIRED_BRANCH")
    if [ "$LOCAL_SHA" != "$REMOTE_SHA" ]; then
        echo "::error::HEAD ($LOCAL_SHA) diverges from origin/$RELEASE_REQUIRED_BRANCH ($REMOTE_SHA)"
        exit 1
    fi
else
    warn "DRY-RUN: skipping origin/$RELEASE_REQUIRED_BRANCH sync check"
fi

echo "[publish] pre-flight done (line=$RELEASE_LINE_NAME)"

# ─── Cleanup trap: restore branch, drop local split branches + local tag ─────
cleanup() {
    rc=$?
    git checkout "$ORIGINAL_BRANCH" 2>/dev/null || true
    for pkg in "${ALL[@]:-}"; do
        git branch -D "split/$pkg" 2>/dev/null || true
    done
    git tag -d "$VERSION" 2>/dev/null || true
    return "$rc"
}
trap cleanup EXIT

# ─── Phase 0: verify the line's rel-* remotes are configured + reachable ─────
echo "[publish] Phase 0: verify rel-* remotes (${#ALL[@]})"
for pkg in "${ALL[@]}"; do
    if ! git remote get-url "rel-$pkg" > /dev/null 2>&1; then
        msg="remote rel-$pkg not configured (run: git remote add rel-$pkg https://github.com/adonko3xBitters/$pkg.git)"
        if [ "$DRY_RUN" = "1" ]; then warn "DRY-RUN: $msg"; continue; fi
        echo "::error::$msg"; exit 1
    fi
    if ! git ls-remote --exit-code "rel-$pkg" HEAD > /dev/null 2>&1; then
        if [ "$DRY_RUN" = "1" ]; then warn "DRY-RUN: rel-$pkg unreachable (mirror repo not created yet)"; continue; fi
        echo "::error::remote rel-$pkg unreachable"; exit 1
    fi
    echo "  ✓ rel-$pkg reachable"
done

# ─── Phase A: regenerate splits from the current tree (no checkout between) ──
echo "[publish] Phase A: regenerate ${#ALL[@]} subtree splits"
for pkg in "${ALL[@]}"; do
    git branch -D "split/$pkg" 2>/dev/null || true
done
for pkg in "${ALL[@]}"; do
    if ! git subtree split --prefix="packages/$pkg" -b "split/$pkg" > /dev/null 2>&1; then
        echo "::error::subtree split failed for packages/$pkg (does the path exist on $ORIGINAL_BRANCH?)"
        exit 1
    fi
    SHA=$(git rev-parse "split/$pkg")
    echo "  ✓ split/$pkg @ ${SHA:0:12}"
done

# ─── Phase B: detect tag collision (idempotent re-run safe) ──────────────────
echo "[publish] Phase B: tag collision check"
if [ "$DRY_RUN" = "1" ]; then
    warn "DRY-RUN: skipping remote tag-collision probe"
else
    COLLISION_COUNT=0
    for pkg in "${ALL[@]}"; do
        if git ls-remote --exit-code --tags "rel-$pkg" "refs/tags/$VERSION" > /dev/null 2>&1; then
            REMOTE_TAG_SHA=$(git ls-remote --tags "rel-$pkg" "refs/tags/$VERSION^{}" | awk '{print $1}')
            LOCAL_SPLIT_SHA=$(git rev-parse "split/$pkg")
            if [ "$REMOTE_TAG_SHA" = "$LOCAL_SPLIT_SHA" ]; then
                echo "  (skip-prep: rel-$pkg already has $VERSION at expected SHA)"
            else
                echo "::error::rel-$pkg has $VERSION at WRONG SHA (immutable tag) — manual intervention required"
                COLLISION_COUNT=$((COLLISION_COUNT + 1))
            fi
        fi
    done
    [ "$COLLISION_COUNT" -eq 0 ] || exit 1
    echo "  ✓ no SHA-mismatch collisions"
fi

# ─── Phase C: tag + push per topological level ───────────────────────────────
push_level() {
    local level_name="$1"; shift
    local packages=("$@")
    echo "[publish] Phase C — $level_name (${#packages[@]} packages)"

    for pkg in "${packages[@]}"; do
        if [ "$DRY_RUN" = "1" ]; then
            echo "  [DRY-RUN] would tag+push rel-$pkg $VERSION ($(git rev-parse "split/$pkg" | head -c 12))"
            continue
        fi
        if git ls-remote --exit-code --tags "rel-$pkg" "refs/tags/$VERSION" > /dev/null 2>&1; then
            REMOTE_TAG_SHA=$(git ls-remote --tags "rel-$pkg" "refs/tags/$VERSION^{}" | awk '{print $1}')
            LOCAL_SPLIT_SHA=$(git rev-parse "split/$pkg")
            if [ "$REMOTE_TAG_SHA" = "$LOCAL_SPLIT_SHA" ]; then
                echo "  (skip: rel-$pkg already at $VERSION)"; continue
            fi
            echo "::error::rel-$pkg WRONG SHA at $VERSION (Phase B should have caught)"; exit 1
        fi
        git checkout "split/$pkg" > /dev/null 2>&1
        git tag -d "$VERSION" 2>/dev/null || true
        git tag -a "$VERSION" -m "Release $VERSION"
        git push "rel-$pkg" "$VERSION"
        REMOTE_TAG_SHA=$(git ls-remote --tags "rel-$pkg" "refs/tags/$VERSION^{}" | awk '{print $1}')
        LOCAL_SPLIT_SHA=$(git rev-parse "split/$pkg")
        if [ "$REMOTE_TAG_SHA" != "$LOCAL_SPLIT_SHA" ]; then
            echo "::error::push to rel-$pkg landed at $REMOTE_TAG_SHA (expected $LOCAL_SPLIT_SHA)"; exit 1
        fi
        echo "  ✓ pushed rel-$pkg $VERSION ($LOCAL_SPLIT_SHA)"
        git tag -d "$VERSION" 2>/dev/null || true
    done
    git checkout "$ORIGINAL_BRANCH" > /dev/null 2>&1
}

for entry in "${RELEASE_LEVELS[@]}"; do
    level_label="${entry%%|*}"
    level_pkgs="${entry#*|}"
    # shellcheck disable=SC2086
    push_level "$level_label" $level_pkgs
done

# ─── Phase D: cleanup via trap EXIT ──────────────────────────────────────────
echo "[publish] OK ($RELEASE_LINE_NAME) — $VERSION processed for ${#ALL[@]} rel-* repos"
echo ""
echo "Next:"
echo "  1. Wait ~90s for Packagist webhook propagation."
echo "  2. RELEASE_LINE=$RELEASE_LINE_NAME RELEASE_GATE_LIVE=1 RELEASE_GATE_VERSION=$VERSION bash scripts/release-gate.sh"
echo "  3. npm: publish $RELEASE_NPM_PKG from $RELEASE_NPM_DIR (npm-publish workflow on tag $VERSION)."

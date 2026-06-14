#!/usr/bin/env bash
#
# AUSUS — Coordinated subtree-split publish to rel-* repos
# --------------------------------------------------------
# Pre-flight enforces HEAD=main, clean tree, synced with origin/main.
# Phase 0 validates the 10 rel-* remotes are reachable.
# Phase A regenerates all 10 subtree splits FROM MAIN (deterministic).
# Phase B detects tag collisions on remotes (idempotent re-run safe).
# Phase C tags + pushes per topological level (5 levels).
# Phase D cleanup local split branches (via trap EXIT).
#
# Failure modes covered:
#   - Mid-loop subtree-split poisoning bug (Phase A done before Phase C).
#   - Tag collision (skip with verify-SHA; fail-loud on SHA mismatch).
#   - Wrong branch / dirty tree / unsynced origin (pre-flight blocks).
#   - Partial failure mid-Phase-C (rerun is idempotent).
#
# Tags are IMMUTABLE on remotes. This script never force-pushes tags.
# Recovery from a tag-at-wrong-SHA situation requires manual intervention.
#
# Usage:   scripts/release-publish.sh v0.2.0-alpha.4
# Env:     DRY_RUN=1   skip the actual push, just print intent

set -euo pipefail

VERSION="${1:?usage: release-publish.sh v<X.Y.Z>}"
DRY_RUN="${DRY_RUN:-0}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

if ! [[ "$VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[a-z0-9.]+)?$ ]]; then
    echo "::error::invalid version: $VERSION (expected v<X.Y.Z>[-pre.N])"
    exit 1
fi

cd "$ROOT"

# Topological order: leaves first, dependents later.
# Within each level, alphabetical for determinism.
LEVEL_1=(audit-database auth-bridge kernel presentation-default tenancy-row)  # no ausus/* deps
LEVEL_2=(persistence-sql runtime-default)                                      # deps: kernel
LEVEL_3=(api-http)                                                              # deps: kernel + runtime-default
LEVEL_4=(standard-stack)                                                        # deps: 4 above
LEVEL_5=(starter)                                                               # deps: 4 above

ALL=("${LEVEL_1[@]}" "${LEVEL_2[@]}" "${LEVEL_3[@]}" "${LEVEL_4[@]}" "${LEVEL_5[@]}")

# ─── Pre-flight: HEAD=main, clean tree, synced with origin ───────────────────
ORIGINAL_BRANCH=$(git symbolic-ref --short HEAD 2>/dev/null || git rev-parse HEAD)
if [ "$ORIGINAL_BRANCH" != "main" ]; then
    echo "::error::release-publish.sh requires HEAD=main (currently: $ORIGINAL_BRANCH)"
    exit 1
fi

if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "::error::working tree dirty (uncommitted changes)"
    git status --short
    exit 1
fi

if [ -n "$(git ls-files --others --exclude-standard)" ]; then
    echo "::error::working tree has untracked files"
    git ls-files --others --exclude-standard
    exit 1
fi

git fetch origin main --quiet
LOCAL_SHA=$(git rev-parse HEAD)
REMOTE_SHA=$(git rev-parse origin/main)
if [ "$LOCAL_SHA" != "$REMOTE_SHA" ]; then
    echo "::error::HEAD ($LOCAL_SHA) diverges from origin/main ($REMOTE_SHA)"
    echo "  Run: git pull --ff-only origin main"
    exit 1
fi

echo "[publish] pre-flight: HEAD=main, clean tree, synced with origin/main"

# ─── Cleanup trap: restore branch, drop local split branches + local tag ─────
cleanup() {
    rc=$?
    git checkout "$ORIGINAL_BRANCH" 2>/dev/null || true
    for pkg in "${ALL[@]:-}"; do
        git branch -D "split/$pkg" 2>/dev/null || true
    done
    # Defensive backstop — the clone must NEVER retain a local $VERSION tag
    # (that name is reserved for the monorepo release tag). Tags are pushed to
    # each rel-* repo then deleted in-loop; this unconditional cleanup covers
    # DRY_RUN and every failure path.
    git tag -d "$VERSION" 2>/dev/null || true
    return "$rc"
}
trap cleanup EXIT

# ─── Phase 0: verify all 10 rel-* remotes are configured + reachable ─────────
echo "[publish] Phase 0: verify rel-* remotes"
for pkg in "${ALL[@]}"; do
    if ! git remote get-url "rel-$pkg" > /dev/null 2>&1; then
        echo "::error::remote rel-$pkg not configured"
        echo "  run: git remote add rel-$pkg https://github.com/adonko3xBitters/$pkg.git"
        exit 1
    fi
    if ! git ls-remote --exit-code "rel-$pkg" HEAD > /dev/null 2>&1; then
        echo "::error::remote rel-$pkg unreachable"
        exit 1
    fi
done
echo "  ✓ all 10 rel-* remotes reachable"

# ─── Phase A: regenerate all 10 splits from main (no checkout between) ───────
echo "[publish] Phase A: regenerate 10 subtree splits from main"
for pkg in "${ALL[@]}"; do
    git branch -D "split/$pkg" 2>/dev/null || true
done

for pkg in "${ALL[@]}"; do
    git subtree split --prefix="packages/$pkg" -b "split/$pkg" > /dev/null 2>&1
    SHA=$(git rev-parse "split/$pkg")
    echo "  ✓ split/$pkg @ ${SHA:0:12}"
done

# ─── Phase B: detect tag collision (idempotent re-run safe) ──────────────────
echo "[publish] Phase B: tag collision check"
COLLISION_COUNT=0
for pkg in "${ALL[@]}"; do
    if git ls-remote --exit-code --tags "rel-$pkg" "refs/tags/$VERSION" > /dev/null 2>&1; then
        REMOTE_TAG_SHA=$(git ls-remote --tags "rel-$pkg" "refs/tags/$VERSION^{}" | awk '{print $1}')
        LOCAL_SPLIT_SHA=$(git rev-parse "split/$pkg")
        if [ "$REMOTE_TAG_SHA" = "$LOCAL_SPLIT_SHA" ]; then
            echo "  (skip-prep: rel-$pkg already has $VERSION at expected SHA)"
        else
            echo "::error::rel-$pkg has $VERSION at WRONG SHA"
            echo "  remote: $REMOTE_TAG_SHA"
            echo "  expected: $LOCAL_SPLIT_SHA"
            echo "  Release tags are immutable — manual intervention required."
            COLLISION_COUNT=$((COLLISION_COUNT + 1))
        fi
    fi
done
if [ "$COLLISION_COUNT" -ne 0 ]; then
    exit 1
fi
echo "  ✓ no SHA-mismatch collisions"

# ─── Phase C: tag + push per topological level ───────────────────────────────
push_level() {
    local level_name="$1"; shift
    local packages=("$@")
    echo "[publish] Phase C — $level_name (${#packages[@]} packages)"

    for pkg in "${packages[@]}"; do
        # Skip if remote tag already exists at the right SHA (idempotent rerun)
        if git ls-remote --exit-code --tags "rel-$pkg" "refs/tags/$VERSION" > /dev/null 2>&1; then
            REMOTE_TAG_SHA=$(git ls-remote --tags "rel-$pkg" "refs/tags/$VERSION^{}" | awk '{print $1}')
            LOCAL_SPLIT_SHA=$(git rev-parse "split/$pkg")
            if [ "$REMOTE_TAG_SHA" = "$LOCAL_SPLIT_SHA" ]; then
                echo "  (skip: rel-$pkg already at $VERSION)"
                continue
            fi
            # Phase B caught mismatches already; defensive guard
            echo "::error::rel-$pkg WRONG SHA at $VERSION (Phase B should have caught)"
            exit 1
        fi

        git checkout "split/$pkg" > /dev/null 2>&1
        # Drop stale local tag from prior partial run (idempotent)
        git tag -d "$VERSION" 2>/dev/null || true
        git tag -a "$VERSION" -m "Release $VERSION"

        if [ "$DRY_RUN" = "1" ]; then
            echo "  [DRY-RUN] would push rel-$pkg $VERSION ($(git rev-parse HEAD | head -c 12))"
        else
            git push "rel-$pkg" "$VERSION"
            # Verify remote tag landed at expected SHA
            REMOTE_TAG_SHA=$(git ls-remote --tags "rel-$pkg" "refs/tags/$VERSION^{}" | awk '{print $1}')
            LOCAL_SPLIT_SHA=$(git rev-parse "split/$pkg")
            if [ "$REMOTE_TAG_SHA" != "$LOCAL_SPLIT_SHA" ]; then
                echo "::error::push to rel-$pkg succeeded but tag points to $REMOTE_TAG_SHA (expected $LOCAL_SPLIT_SHA)"
                exit 1
            fi
            echo "  ✓ pushed rel-$pkg $VERSION ($LOCAL_SPLIT_SHA)"
            # Remove the local tag immediately after a verified push: the clone
            # must not retain a local $VERSION tag (reserved for the monorepo tag).
            git tag -d "$VERSION" 2>/dev/null || true
        fi
    done
    git checkout "$ORIGINAL_BRANCH" > /dev/null 2>&1
}

push_level "LEVEL 1 (leaves, no ausus deps)"  "${LEVEL_1[@]}"
push_level "LEVEL 2 (deps: kernel)"           "${LEVEL_2[@]}"
push_level "LEVEL 3 (deps: kernel+runtime)"   "${LEVEL_3[@]}"
push_level "LEVEL 4 (bundle)"                 "${LEVEL_4[@]}"
push_level "LEVEL 5 (template)"               "${LEVEL_5[@]}"

# ─── Phase D: cleanup via trap EXIT (no explicit action here) ────────────────
echo "[publish] OK — $VERSION pushed to all 10 rel-* repos"
echo ""
echo "Next:"
echo "  1. Wait 90s for Packagist webhook propagation."
echo "  2. RELEASE_GATE_LIVE=1 RELEASE_GATE_VERSION=$VERSION bash scripts/release-gate.sh"

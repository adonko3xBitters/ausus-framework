#!/usr/bin/env bash
#
# AUSUS — Release gate
# --------------------
# Single binary gate that any tag must pass. Two modes:
#
#   default (CI on PR/main):
#     - structural checks on the working tree
#     - composer.json shape validation (--strict)
#     - local path-repo install + tests (scripts/ci.sh)
#     - doc/version coherence
#     - renderer / backend ViewSchema alignment
#
#   live mode (post-tag CI):
#     RELEASE_GATE_LIVE=1
#     RELEASE_GATE_VERSION=v0.2.0-alpha.4
#     - all of the above PLUS:
#     - homepage URLs HTTP 200 against GitHub
#     - composer create-project against Packagist live
#     - end-to-end smoke (composer boot non-interactive)
#     - npm registry has the matching renderer tag
#
# Exit codes:
#   0 = all green
#   1 = structural validation failure
#   2 = local install / scripts/ci.sh failure
#   3 = create-project / smoke failure (live mode)
#   4 = homepage URL failure (live mode)
#   5 = renderer alignment failure
#   6 = doc-version coherence failure
#   7 = npm registry / Packagist indexing failure (live mode)
#   8 = clean-room starter install failure (live mode)
#   9 = renderer npm provenance metadata failure

set -euo pipefail

LIVE="${RELEASE_GATE_LIVE:-0}"
VERSION="${RELEASE_GATE_VERSION:-}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP_ROOT="$(mktemp -d -t ausus-release-gate-XXXXXX)"

if [ -t 1 ]; then
    BOLD=$'\033[1m'; RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RESET=$'\033[0m'
else
    BOLD=''; RED=''; GREEN=''; YELLOW=''; RESET=''
fi
log()  { printf "%s[release-gate]%s %s\n" "$BOLD" "$RESET" "$*"; }
ok()   { printf "  %s✓%s %s\n" "$GREEN" "$RESET" "$*"; }
fail() { printf "  %s✗%s %s\n" "$RED" "$RESET" "$*" >&2; }
warn() { printf "  %s!%s %s\n" "$YELLOW" "$RESET" "$*"; }

cleanup() {
    rc=$?
    rm -rf "$TMP_ROOT" 2>/dev/null || true
    if [ "$rc" -ne 0 ]; then
        log "${RED}FAILED${RESET} with exit $rc"
    fi
    return "$rc"
}
trap cleanup EXIT

cd "$ROOT"

# ─── Release line resolution (legacy | gen2) ─────────────────────────────────
# Explicit RELEASE_LINE wins; otherwise derive from the version's major
# (>=2 → gen2). The line selects which build, renderer, and live checks run.
# shellcheck source=scripts/release-packages.sh
. "$ROOT/scripts/release-packages.sh"
RELEASE_LINE="${RELEASE_LINE:-$(ausus_line_for_version "${VERSION:-v1.0.0}")}"
ausus_release_line "$RELEASE_LINE"

log "mode=$([ "$LIVE" = "1" ] && echo LIVE || echo LOCAL)  line=$RELEASE_LINE_NAME  version=${VERSION:-<n/a>}  tmp=$TMP_ROOT"

# ─── Step 1: composer.json structural validation ─────────────────────────────
log "step 1 — composer.json structural validation"
fail_count=0

for f in composer.json packages/*/composer.json; do
    if ! composer validate --no-check-publish --no-check-lock --strict "$f" >/dev/null 2>&1; then
        fail "$f failed composer validate --strict"
        fail_count=$((fail_count + 1))
    fi
done

# starter's minimum-stability must be one of the documented values. During
# the v0.2 pre-release cycle it was pinned to 'alpha' so the scaffolded
# project inherited the pre-release resolution chain. At v1.0 stable it
# drops to 'stable' (the Composer default). Accepting either keeps the
# gate green across both worlds; anything else (e.g. an accidental
# 'minimum-stability: dev') still fails loud.
STARTER_STAB=$(jq -r '."minimum-stability" // "stable"' packages/starter/composer.json)
case "$STARTER_STAB" in
    alpha|beta|rc|stable) ;;
    *)
        fail "packages/starter/composer.json minimum-stability='$STARTER_STAB' (expected alpha|beta|rc|stable)"
        fail_count=$((fail_count + 1))
        ;;
esac

# all packages MUST have a homepage field
for f in packages/*/composer.json; do
    if [ "$(jq -r '.homepage // ""' "$f")" = "" ]; then
        fail "$f missing homepage field"
        fail_count=$((fail_count + 1))
    fi
done

if [ "$fail_count" -ne 0 ]; then
    fail "structural validation: $fail_count error(s)"
    exit 1
fi
ok "all composer.json structurally valid"

# ─── Step 2: build (line-aware) ──────────────────────────────────────────────
if [ "$RELEASE_LINE_NAME" = "gen2" ]; then
    log "step 2 — scripts/ci-gen2.sh (Entity Engine suites)"
    BUILD_SCRIPT="scripts/ci-gen2.sh"
else
    log "step 2 — scripts/ci.sh (standard-stack build)"
    BUILD_SCRIPT="scripts/ci.sh"
fi
if bash "$BUILD_SCRIPT" > "$TMP_ROOT/ci.log" 2>&1; then
    ok "$BUILD_SCRIPT green"
else
    fail "$BUILD_SCRIPT failed (last 30 lines):"
    tail -30 "$TMP_ROOT/ci.log" >&2
    exit 2
fi

# ─── Step 3: doc-version coherence (legacy line only) ────────────────────────
if [ "$RELEASE_LINE_NAME" = "gen2" ]; then
    log "step 3 — doc-version coherence skipped (tied to ausus/standard-stack; gen2 docs are docs/v2)"
else
    log "step 3 — doc-version coherence"
    if bash scripts/check-doc-version.sh > "$TMP_ROOT/doc-version.log" 2>&1; then
        ok "doc-version aligned"
    else
        fail "doc-version mismatch:"
        cat "$TMP_ROOT/doc-version.log" >&2
        exit 6
    fi
fi

# ─── Step 4: renderer / backend ViewSchema alignment (legacy line only) ──────
if [ "$RELEASE_LINE_NAME" = "gen2" ]; then
    log "step 4 — ViewSchema alignment skipped (gen2 renderer consumes the api-runtime HTTP contract, no ViewSchema peer)"
else
    log "step 4 — renderer / backend ViewSchema alignment"
    if bash scripts/check-renderer-alignment.sh > "$TMP_ROOT/renderer.log" 2>&1; then
        ok "renderer alignment OK"
    else
        fail "renderer alignment failed:"
        cat "$TMP_ROOT/renderer.log" >&2
        exit 5
    fi
fi

# ─── Step 4b: renderer npm provenance metadata pre-check (line-aware dir) ────
log "step 4b — renderer npm provenance metadata ($RELEASE_NPM_DIR)"
if RENDERER_PKG_DIR="$RELEASE_NPM_DIR" bash scripts/check-renderer-provenance.sh > "$TMP_ROOT/provenance.log" 2>&1; then
    ok "renderer provenance metadata OK"
else
    fail "renderer provenance metadata failed:"
    cat "$TMP_ROOT/provenance.log" >&2
    exit 9
fi

# ─── live mode only ──────────────────────────────────────────────────────────
if [ "$LIVE" != "1" ]; then
    log "clean-room starter install skipped (RELEASE_GATE_LIVE != 1)"
    log "${GREEN}OK${RESET} (local mode)"
    exit 0
fi

if [ -z "$VERSION" ]; then
    fail "RELEASE_GATE_LIVE=1 requires RELEASE_GATE_VERSION (e.g. v0.2.0-alpha.4)"
    exit 1
fi

# ─── Step 5: homepage URLs HTTP 200 ──────────────────────────────────────────
# Resolves the 10 declared homepage URLs against GitHub. Two implementation
# notes pin the contract against the previous transient-failure pattern:
#
#   - Status capture is decoupled from curl's exit code. The earlier form
#     `STATUS=$(curl ... || echo "000")` concatenated curl's own emitted
#     `%{http_code}` with the fallback string when curl exited non-zero
#     after partially writing — producing impossible status values like
#     `200000` or `000000`. Capturing the rc separately keeps STATUS as a
#     single, well-formed HTTP code (or `000` on hard failure).
#
#   - A 0.3 s pause + a single `--retry 1 --retry-delay 1` budget per URL
#     defuses GitHub's HEAD-burst rate limiter for the 10 sibling repos
#     without ever crossing into infinite-retry territory. An explicit
#     User-Agent string surfaces this gate clearly in GitHub's logs and
#     avoids the heuristic throttle that some no-UA pings hit.
log "step 5 — homepage URLs reachable ($RELEASE_LINE_NAME line: ${RELEASE_ALL[*]})"
fail_count=0
# Temporarily relax errexit: curl-substitution failures inside the loop must
# be caught by the rc check that follows, not by `set -e` abandoning the
# script. The loop's own per-URL failure tracking + the post-loop fail_count
# gate provides the actual abort semantics.
set +e
for pkg in "${RELEASE_ALL[@]}"; do
    f="packages/$pkg/composer.json"
    URL=$(jq -r '.homepage' "$f")
    STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
        --connect-timeout 5 -m 15 \
        --retry 2 --retry-delay 2 --retry-all-errors \
        -A "ausus-release-gate/1.0 (+homepage-check)" \
        "$URL")
    rc=$?
    if [ "$rc" -ne 0 ]; then
        STATUS="000"
    fi
    if [ "$STATUS" = "200" ] || [ "$STATUS" = "301" ] || [ "$STATUS" = "302" ]; then
        ok "$URL → $STATUS"
    else
        fail "$f homepage $URL → $STATUS"
        fail_count=$((fail_count + 1))
    fi
    sleep 1.2
done
set -e
if [ "$fail_count" -ne 0 ]; then
    exit 4
fi

# ─── Steps 6–9: external install + npm (line-aware) ──────────────────────────
NPM_VERSION="${VERSION#v}"
if [ "$RELEASE_LINE_NAME" = "gen2" ]; then
    # Gen2 is a library line (no starter scaffold / composer boot). The external
    # contract is: `composer require` the Gen2 packages from Packagist + a
    # compile/runtime smoke, and `npm install @ausus/react-renderer`.
    log "step 6–7 — clean-room Gen2 library install (Packagist live)"
    if EXPECTED_VERSION="$VERSION" bash scripts/clean-room-gen2.sh > "$TMP_ROOT/clean-room.log" 2>&1; then
        ok "clean-room Gen2 install green"
    else
        fail "clean-room Gen2 install failed:"
        tail -40 "$TMP_ROOT/clean-room.log" >&2
        exit 8
    fi

    log "step 8 — npm registry has $RELEASE_NPM_PKG@$NPM_VERSION"
    NPM_SEEN=$(curl -s -m 10 "https://registry.npmjs.org/$RELEASE_NPM_PKG/$NPM_VERSION" | jq -r '.version // empty')
    if [ "$NPM_SEEN" = "$NPM_VERSION" ]; then
        ok "$RELEASE_NPM_PKG@$NPM_VERSION published"
    else
        fail "$RELEASE_NPM_PKG@$NPM_VERSION NOT on npm (saw: '$NPM_SEEN')"
        exit 7
    fi
else
    # ─── Legacy: composer create-project against Packagist live ──────────────
    log "step 6 — composer create-project (Packagist live)"
    PROJECT_DIR="$TMP_ROOT/create-project-test"
    if composer create-project "ausus/starter:$VERSION" "$PROJECT_DIR" \
            --stability=alpha --no-interaction --no-cache > "$TMP_ROOT/create-project.log" 2>&1; then
        ok "create-project succeeded"
        [ -f "$PROJECT_DIR/composer.json" ] || { fail "scaffolded project missing composer.json"; exit 3; }
        ok "scaffolded project structure valid"
    else
        fail "create-project failed:"
        tail -40 "$TMP_ROOT/create-project.log" >&2
        exit 3
    fi

    log "step 7 — smoke test installed scaffold"
    cd "$PROJECT_DIR"
    if COMPOSER_NO_INTERACTION=1 composer boot --no-interaction > "$TMP_ROOT/boot.log" 2>&1; then
        ok "composer boot succeeded"
    else
        fail "composer boot failed:"
        tail -20 "$TMP_ROOT/boot.log" >&2
        exit 3
    fi
    cd "$ROOT"

    log "step 8 — npm registry has @ausus/renderer-react@$NPM_VERSION"
    NPM_LATEST=$(curl -s -m 10 "https://registry.npmjs.org/@ausus/renderer-react/$NPM_VERSION" | jq -r '.version // empty')
    if [ "$NPM_LATEST" = "$NPM_VERSION" ]; then
        ok "@ausus/renderer-react@$NPM_VERSION published"
    else
        fail "@ausus/renderer-react@$NPM_VERSION NOT on npm (saw: '$NPM_LATEST')"
        exit 7
    fi

    log "step 9 — clean-room starter install"
    if EXPECTED_VERSION="${RELEASE_GATE_VERSION:-}" \
            bash scripts/clean-room-install-test.sh > "$TMP_ROOT/clean-room.log" 2>&1; then
        ok "clean-room starter install green"
    else
        fail "clean-room starter install failed:"
        tail -40 "$TMP_ROOT/clean-room.log" >&2
        exit 8
    fi
fi

log "${GREEN}OK${RESET} (live mode) — $VERSION ready for announce"

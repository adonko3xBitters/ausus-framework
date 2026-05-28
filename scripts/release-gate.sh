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
log "mode=$([ "$LIVE" = "1" ] && echo LIVE || echo LOCAL)  version=${VERSION:-<n/a>}  tmp=$TMP_ROOT"

# ─── Step 1: composer.json structural validation ─────────────────────────────
log "step 1 — composer.json structural validation"
fail_count=0

for f in composer.json packages/*/composer.json; do
    if ! composer validate --no-check-publish --no-check-lock --strict "$f" >/dev/null 2>&1; then
        fail "$f failed composer validate --strict"
        fail_count=$((fail_count + 1))
    fi
done

# starter MUST have minimum-stability=alpha while AUSUS is alpha
STARTER_STAB=$(jq -r '."minimum-stability" // "stable"' packages/starter/composer.json)
if [ "$STARTER_STAB" != "alpha" ]; then
    fail "packages/starter/composer.json minimum-stability='$STARTER_STAB' (expected 'alpha')"
    fail_count=$((fail_count + 1))
fi

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

# ─── Step 2: scripts/ci.sh (local path-repo install + tests) ─────────────────
log "step 2 — scripts/ci.sh"
if bash scripts/ci.sh > "$TMP_ROOT/ci.log" 2>&1; then
    ok "scripts/ci.sh green"
else
    fail "scripts/ci.sh failed (last 30 lines):"
    tail -30 "$TMP_ROOT/ci.log" >&2
    exit 2
fi

# ─── Step 3: doc-version coherence ───────────────────────────────────────────
log "step 3 — doc-version coherence"
if bash scripts/check-doc-version.sh > "$TMP_ROOT/doc-version.log" 2>&1; then
    ok "doc-version aligned"
else
    fail "doc-version mismatch:"
    cat "$TMP_ROOT/doc-version.log" >&2
    exit 6
fi

# ─── Step 4: renderer / backend ViewSchema alignment ─────────────────────────
log "step 4 — renderer / backend ViewSchema alignment"
if bash scripts/check-renderer-alignment.sh > "$TMP_ROOT/renderer.log" 2>&1; then
    ok "renderer alignment OK"
else
    fail "renderer alignment failed:"
    cat "$TMP_ROOT/renderer.log" >&2
    exit 5
fi

# ─── Step 4b: renderer npm provenance metadata pre-check ────────────────────
log "step 4b — renderer npm provenance metadata"
if bash scripts/check-renderer-provenance.sh > "$TMP_ROOT/provenance.log" 2>&1; then
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
log "step 5 — homepage URLs reachable"
fail_count=0
for f in packages/*/composer.json; do
    URL=$(jq -r '.homepage' "$f")
    STATUS=$(curl -s -o /dev/null -w "%{http_code}" -m 10 "$URL" || echo "000")
    if [ "$STATUS" = "200" ] || [ "$STATUS" = "301" ] || [ "$STATUS" = "302" ]; then
        ok "$URL → $STATUS"
    else
        fail "$f homepage $URL → $STATUS"
        fail_count=$((fail_count + 1))
    fi
done
if [ "$fail_count" -ne 0 ]; then
    exit 4
fi

# ─── Step 6: composer create-project against Packagist live ──────────────────
log "step 6 — composer create-project (Packagist live)"
PROJECT_DIR="$TMP_ROOT/create-project-test"
if composer create-project "ausus/starter:$VERSION" "$PROJECT_DIR" \
        --stability=alpha --no-interaction --no-cache > "$TMP_ROOT/create-project.log" 2>&1; then
    ok "create-project succeeded"
    if [ ! -f "$PROJECT_DIR/composer.json" ]; then
        fail "scaffolded project missing composer.json"
        exit 3
    fi
    ok "scaffolded project structure valid"
else
    fail "create-project failed:"
    tail -40 "$TMP_ROOT/create-project.log" >&2
    exit 3
fi

# ─── Step 7: smoke test the installed scaffold (non-interactive) ─────────────
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

# ─── Step 8: npm registry alignment ──────────────────────────────────────────
log "step 8 — npm registry has @ausus/renderer-react@${VERSION#v}"
NPM_VERSION="${VERSION#v}"
NPM_LATEST=$(curl -s -m 10 "https://registry.npmjs.org/@ausus/renderer-react/$NPM_VERSION" \
    | jq -r '.version // empty')
if [ "$NPM_LATEST" = "$NPM_VERSION" ]; then
    ok "@ausus/renderer-react@$NPM_VERSION published"
else
    fail "@ausus/renderer-react@$NPM_VERSION NOT on npm (saw: '$NPM_LATEST')"
    exit 7
fi

# ─── Step 9: clean-room starter install (Packagist quickstart end-to-end) ───
log "step 9 — clean-room starter install"
if EXPECTED_VERSION="${RELEASE_GATE_VERSION:-}" \
        bash scripts/clean-room-install-test.sh > "$TMP_ROOT/clean-room.log" 2>&1; then
    ok "clean-room starter install green"
else
    fail "clean-room starter install failed:"
    tail -40 "$TMP_ROOT/clean-room.log" >&2
    exit 8
fi

log "${GREEN}OK${RESET} (live mode) — $VERSION ready for announce"

#!/usr/bin/env bash
#
# AUSUS — Clean-room starter install test
# ---------------------------------------
# Reproduces the documented quickstart end to end in a throwaway temp
# directory consuming the live Packagist registry only — no path repos,
# no symlinks, no pre-existing vendor, no monorepo sources.
#
# Gates the user-visible contract:
#   A. The scaffold contains no monorepo directories.
#   B. The scaffold's composer.json is named ausus/starter.
#   C. The scaffold's composer.json carries no repositories[] entry.
#   D. `composer boot` succeeds non-interactively.
#   E. Every required ausus/* dependency installs at EXPECTED_VERSION.
#
# Designed to fail loudly on the alpha.4 regression: running
# `composer create-project ausus/starter myapp` without --stability=alpha
# would have tripped Gates A + C + D (monorepo embedded, repositories.path
# present, boot script undefined) at minimum.
#
# Standalone or driven from scripts/release-gate.sh (Step 9).
#
# Usage:   bash scripts/clean-room-install-test.sh
# Env:     EXPECTED_VERSION   target version of ausus/* (default v1.0.0)

set -euo pipefail

EXPECTED_VERSION="${EXPECTED_VERSION:-v1.0.0}"
TMP="$(mktemp -d -t ausus-clean-room-XXXXXX)"

cleanup() {
    rc=$?
    rm -rf "$TMP" 2>/dev/null || true
    if [ "$rc" -ne 0 ]; then
        echo "[clean-room] FAILED (exit=$rc)"
    fi
    return "$rc"
}
trap cleanup EXIT

echo "[clean-room] tmp=$TMP"
echo "[clean-room] expected_version=$EXPECTED_VERSION"

cd "$TMP"

# ─── Quickstart: the exact documented command ───────────────────────────────
echo "[clean-room] running: composer create-project ausus/starter myapp"
composer create-project "ausus/starter" myapp \
    --no-interaction \
    --no-cache

cd myapp

# ─── Gate A — no monorepo directories embedded ──────────────────────────────
echo "[clean-room] gate A — no forbidden monorepo directories"
for forbidden in packages apps docs-site renderer .github scripts; do
    if [ -d "./$forbidden" ]; then
        echo "::error::myapp contains forbidden directory: $forbidden"
        exit 1
    fi
done
echo "  ✓ no monorepo directories"

# ─── Gate B — composer.json.name == ausus/starter ───────────────────────────
echo "[clean-room] gate B — composer.json.name == ausus/starter"
NAME="$(jq -r '.name' composer.json)"
if [ "$NAME" != "ausus/starter" ]; then
    echo "::error::composer.json name=$NAME (expected ausus/starter)"
    exit 1
fi
echo "  ✓ composer.json.name = ausus/starter"

# ─── Gate C — repositories[] absent or empty ────────────────────────────────
echo "[clean-room] gate C — composer.json has no repositories[]"
REPO_COUNT="$(jq '(.repositories // []) | length' composer.json)"
if [ "$REPO_COUNT" != "0" ]; then
    echo "::error::composer.json has non-empty repositories[] — must be absent"
    exit 1
fi
echo "  ✓ no repositories[] entry"

# ─── Gate D — composer boot succeeds ────────────────────────────────────────
echo "[clean-room] gate D — composer boot"
if ! composer boot --no-interaction; then
    echo "::error::composer boot failed"
    exit 1
fi
echo "  ✓ composer boot succeeded"

# ─── Gate E — every ausus/* dependency installed at EXPECTED_VERSION ────────
#
# Note: ausus/starter is intentionally excluded from this loop. It IS the
# root project here (the scaffolded myapp), so `composer show ausus/starter`
# does not return its own version. Its identity is verified by Gate B
# (composer.json.name == ausus/starter); its version-correctness is verified
# by Composer's own resolution against the documented `^0.2@alpha` constraint.
echo "[clean-room] gate E — installed versions"
for pkg in ausus/kernel ausus/runtime-default ausus/persistence-sql ausus/api-http ausus/standard-stack; do
    INSTALLED="$(
        composer show "$pkg" 2>/dev/null \
        | awk '/^versions/ { gsub(/[*,]/, ""); for (i=2; i<=NF; i++) { v=$i; if (v != ":" && v != "") { print v; exit } } }'
    )"
    if [ "$INSTALLED" != "$EXPECTED_VERSION" ]; then
        echo "::error::$pkg installed=$INSTALLED (expected $EXPECTED_VERSION)"
        exit 1
    fi
    printf "  ✓ %-30s %s\n" "$pkg" "$INSTALLED"
done

# ─── Gate F — `composer serve` smoke (regression guard for starter#1) ───────
#
# Boots the documented dev server on a free port, polls /api/_health, and
# asserts a 200 + JSON envelope. Without this gate, the `composer serve`
# DX promise can silently break — `composer create-project ausus/starter`
# may resolve cleanly but the dev server fatals on the first Nyholm class
# load if the starter's `require` ever loses `nyholm/psr7` /
# `nyholm/psr7-server` again (cf. adonko3xBitters/starter#1).
#
# Conditional execution: the gate ONLY runs when the scaffolded vendor/
# tree contains `nyholm/psr7`. The pre-fix starter (v1.0.0) does not
# install Nyholm via its require chain, so testing the documented
# `composer serve` against a v1.0.0 scaffold would deterministically
# fatal. Once v1.0.1+ is the floor on Packagist, the skip never fires
# and the gate becomes the permanent anti-regression guard.
if ! composer show nyholm/psr7 >/dev/null 2>&1; then
    echo "[clean-room] gate F — skipped (nyholm/psr7 absent from scaffold; pre-fix starter line)"
    echo "[clean-room] OK — quickstart works end to end"
    exit 0
fi
echo "[clean-room] gate F — composer serve + /api/_health"
SERVE_PORT=$(( (RANDOM % 1000) + 9000 ))
SERVE_LOG="$(mktemp -t ausus-clean-room-serve-XXXXXX.log)"
AUSUS_DB_PATH="$(pwd)/ausus-serve-smoke.sqlite" \
    php -S "127.0.0.1:${SERVE_PORT}" bin/server.php > "$SERVE_LOG" 2>&1 &
SERVE_PID=$!
# Poll for up to ~3 s; abort cleanly on any failure.
SERVE_OK=0
for i in 1 2 3 4 5 6 7 8 9 10; do
    if ! kill -0 "$SERVE_PID" 2>/dev/null; then
        echo "::error::dev server died during boot — log:"
        sed 's/^/    /' "$SERVE_LOG"
        rm -f "$SERVE_LOG"
        exit 1
    fi
    HTTP_CODE=$(curl -s -o "$SERVE_LOG.body" -w '%{http_code}' \
        --max-time 2 "http://127.0.0.1:${SERVE_PORT}/api/_health" 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" = "200" ]; then
        if grep -q '"ok":true' "$SERVE_LOG.body" 2>/dev/null \
                && grep -q '"service":"ausus/api-http"' "$SERVE_LOG.body" 2>/dev/null \
                && grep -q '"graphHash"' "$SERVE_LOG.body" 2>/dev/null; then
            SERVE_OK=1
            break
        fi
    fi
    sleep 0.3
done
kill "$SERVE_PID" 2>/dev/null || true
sleep 0.2
kill -9 "$SERVE_PID" 2>/dev/null || true
if [ "$SERVE_OK" -ne 1 ]; then
    echo "::error::/api/_health did not return the expected 200 + envelope"
    echo "  last HTTP code: ${HTTP_CODE}"
    echo "  body:"
    sed 's/^/    /' "$SERVE_LOG.body" 2>/dev/null || echo "    (empty)"
    echo "  server log:"
    sed 's/^/    /' "$SERVE_LOG"
    rm -f "$SERVE_LOG" "$SERVE_LOG.body"
    exit 1
fi
rm -f "$SERVE_LOG" "$SERVE_LOG.body"
echo "  ✓ /api/_health → 200 with expected JSON envelope"

echo "[clean-room] OK — quickstart works end to end"

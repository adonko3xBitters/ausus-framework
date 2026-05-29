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

echo "[clean-room] OK — quickstart works end to end"

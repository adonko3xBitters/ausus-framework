#!/usr/bin/env bash
#
# AUSUS — Renderer / backend ViewSchema alignment check
# -----------------------------------------------------
# Reads:
#   renderer/react/package.json → peerSchemaVersion (semver range, REQUIRED)
#   packages/runtime-default/src/runtime.php → schemaVersion (literal)
# Verifies backend schemaVersion satisfies renderer peerSchemaVersion.
#
# Portable: uses `npx --yes semver@7`, no global dependency required.
#
# Exit codes:
#   0 = aligned
#   1 = missing field / extraction failure / npx unavailable
#   2 = mismatch (renderer cannot consume current backend wire)

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# ─── 1. peerSchemaVersion (renderer side) ────────────────────────────────────
PEER=$(jq -r '.peerSchemaVersion // empty' renderer/react/package.json)
if [ -z "$PEER" ]; then
    echo "::error::renderer/react/package.json missing 'peerSchemaVersion'"
    echo "Expected example: \"peerSchemaVersion\": \"^1.0.0\""
    exit 1
fi

# ─── 2. schemaVersion (backend side, robust grep) ────────────────────────────
# Anchored on the exact ProjectionRenderer pattern, excludes comments,
# requires correct indent + single-quote style + trailing comma.
SCHEMA=$(grep -E "^[[:space:]]+'schemaVersion'[[:space:]]+=>[[:space:]]+'[0-9]+\.[0-9]+\.[0-9]+'," \
    packages/runtime-default/src/runtime.php 2>/dev/null \
    | grep -v -E "^[[:space:]]*(//|\*|#)" \
    | head -1 \
    | sed -E "s/.*'schemaVersion'[[:space:]]+=>[[:space:]]+'([^']+)'.*/\1/")

if [ -z "$SCHEMA" ]; then
    echo "::error::could not extract schemaVersion from packages/runtime-default/src/runtime.php"
    exit 1
fi

# ─── 3. portable semver check via npx ────────────────────────────────────────
if ! command -v npx >/dev/null 2>&1; then
    echo "::error::npx required (install Node.js 18+)"
    exit 1
fi

if npx --yes semver@7 --range "$PEER" "$SCHEMA" >/dev/null 2>&1; then
    echo "OK: schemaVersion=$SCHEMA satisfies peerSchemaVersion=$PEER"
    exit 0
fi

echo "::error::MISMATCH: backend schemaVersion=$SCHEMA does not satisfy renderer peerSchemaVersion=$PEER"
echo "  Either bump renderer's peerSchemaVersion to include $SCHEMA,"
echo "  or roll back backend schemaVersion (requires coordinated renderer release)."
exit 2

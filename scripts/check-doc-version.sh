#!/usr/bin/env bash
#
# AUSUS — Doc/Packagist version coherence
# ---------------------------------------
# Single source of truth: docs-site/CURRENT_VERSION (one-line file).
#
# Verifies the version in docs-site/CURRENT_VERSION matches the latest
# tag on Packagist for ausus/standard-stack. Supports intentional doc
# lag via the suffix " (intentional-lag)" during release prep windows.
#
# Source file format:
#   docs-site/CURRENT_VERSION
#     contains exactly:    v0.2.0-alpha.4
#   or, for intentional lag:
#     contains:            v0.2.0-alpha.3 (intentional-lag)
#
# Portable: uses `npx --yes semver@7` for version sorting (no global dep).
#
# Exit codes:
#   0 = aligned (or intentional lag)
#   1 = source file missing / malformed / Packagist hard fail on PR
#   2 = version drift detected

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SOURCE_FILE="$ROOT/docs-site/CURRENT_VERSION"
TIMEOUT="${PACKAGIST_TIMEOUT_SECONDS:-15}"

if [ ! -f "$SOURCE_FILE" ]; then
    echo "::error::$SOURCE_FILE missing"
    exit 1
fi

CONTENT=$(head -1 "$SOURCE_FILE" | tr -d '\n')
DOC_VERSION=$(echo "$CONTENT" | awk '{print $1}')
LAG_MARKER=$(echo "$CONTENT" | grep -oF "(intentional-lag)" || true)

# Validate format
if ! [[ "$DOC_VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[a-z0-9.]+)?$ ]]; then
    echo "::error::$SOURCE_FILE contains invalid version: '$DOC_VERSION'"
    exit 1
fi

# Query Packagist for the latest version of ausus/standard-stack
PACKAGIST_URL='https://repo.packagist.org/p2/ausus/standard-stack.json'
RAW=$(curl -s -m "$TIMEOUT" "$PACKAGIST_URL" || true)

if [ -z "$RAW" ] || ! echo "$RAW" | jq -e '.packages."ausus/standard-stack"' > /dev/null 2>&1; then
    echo "::warning::Packagist API unreachable or malformed response"
    # On PR / push: fail to force investigation. On schedule/manual: warn only.
    EVENT_NAME="${GITHUB_EVENT_NAME:-manual}"
    if [ "$EVENT_NAME" = "pull_request" ] || [ "$EVENT_NAME" = "push" ]; then
        echo "::error::cannot validate doc-version coherence on $EVENT_NAME (Packagist required)"
        exit 1
    fi
    echo "  skipping (event=$EVENT_NAME)"
    exit 0
fi

ALL_VERSIONS=$(echo "$RAW" | jq -r '.packages."ausus/standard-stack"[].version')
if [ -z "$ALL_VERSIONS" ]; then
    echo "::error::Packagist returned no versions for ausus/standard-stack"
    exit 1
fi

if ! command -v npx >/dev/null 2>&1; then
    echo "::error::npx required (install Node.js 18+) for semver-aware sort"
    exit 1
fi

# Pick the highest version via semver-aware sort (handles pre-release ordering).
# `semver` CLI sorts ascending; `--pre` opts pre-releases into the result.
# Note: `semver` strips leading `v` from input on output. Restore it below
# to match the `v<X.Y.Z>` convention used in CURRENT_VERSION.
# shellcheck disable=SC2086
PACKAGIST_LATEST=$(echo "$ALL_VERSIONS" \
    | xargs -n 1 echo \
    | xargs npx --yes semver@7 --pre 2>/dev/null \
    | tail -1)

if [ -z "$PACKAGIST_LATEST" ]; then
    echo "::error::semver sort produced empty result"
    exit 1
fi

# Restore the `v` prefix that `semver` stripped — DOC_VERSION carries it.
case "$PACKAGIST_LATEST" in
    v*) ;;
    *) PACKAGIST_LATEST="v${PACKAGIST_LATEST}" ;;
esac

# Compare
if [ "$DOC_VERSION" = "$PACKAGIST_LATEST" ]; then
    echo "✓ doc version ($DOC_VERSION) matches Packagist latest ($PACKAGIST_LATEST)"
    exit 0
fi

if [ -n "$LAG_MARKER" ]; then
    echo "⚠ intentional-lag declared: doc=$DOC_VERSION, Packagist=$PACKAGIST_LATEST"
    exit 0
fi

echo "::error::DRIFT DETECTED"
echo "    doc says:        $DOC_VERSION"
echo "    Packagist says:  $PACKAGIST_LATEST"
echo ""
echo "Either:"
echo "  1. Update docs-site/CURRENT_VERSION to '$PACKAGIST_LATEST' and the docs."
echo "  2. Or mark the lag explicitly: '$DOC_VERSION (intentional-lag)'"
echo "     during a release prep window."
exit 2

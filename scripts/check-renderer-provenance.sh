#!/usr/bin/env bash
#
# AUSUS — Renderer npm provenance pre-publish gate
# -------------------------------------------------
# Catches the BUG-A4-2 class of regressions BEFORE a tag is pushed: the
# npm-publish workflow uses `npm publish --provenance` (sigstore attestation),
# which validates that renderer/react/package.json `repository.url` matches the
# GitHub Actions repository running the workflow. If they don't, the publish
# rejects with E422 and the renderer never reaches npm.
#
# Verifies, against renderer/react/package.json:
#   1. `repository.url`  == https://github.com/adonko3xBitters/ausus-framework.git
#                           (or git+https:// equivalent)
#   2. `homepage`        is under https://github.com/adonko3xBitters/
#   3. `version`         matches the renderer CHANGELOG topmost section
#   4. `npm pack --dry-run` succeeds with a clean publishable shape
#
# Designed to be invoked from scripts/ci.sh and scripts/release-gate.sh as a
# fast gate. No network calls except the offline `npm pack --dry-run`.
#
# Exit codes:
#   0 = all green
#   1 = missing field / extraction failure
#   2 = repository.url mismatch (would trigger E422 sigstore)
#   3 = homepage outside expected org
#   4 = version drift vs CHANGELOG
#   5 = npm pack --dry-run failure

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PKG=renderer/react/package.json
EXPECTED_REPO_URL_HTTPS="https://github.com/adonko3xBitters/ausus-framework.git"
EXPECTED_REPO_URL_GIT="git+${EXPECTED_REPO_URL_HTTPS}"
EXPECTED_HOMEPAGE_PREFIX="https://github.com/adonko3xBitters/"

if [ ! -f "$PKG" ]; then
    echo "::error::$PKG not found"
    exit 1
fi

# ─── 1. repository.url match ────────────────────────────────────────────────
REPO_URL="$(jq -r '.repository.url // empty' "$PKG")"
if [ -z "$REPO_URL" ]; then
    echo "::error::$PKG missing repository.url"
    exit 1
fi

if [ "$REPO_URL" != "$EXPECTED_REPO_URL_HTTPS" ] && [ "$REPO_URL" != "$EXPECTED_REPO_URL_GIT" ]; then
    echo "::error::$PKG repository.url='$REPO_URL'"
    echo "  expected one of:"
    echo "    $EXPECTED_REPO_URL_HTTPS"
    echo "    $EXPECTED_REPO_URL_GIT"
    echo "  sigstore provenance attestation will reject the publish (E422)."
    exit 2
fi
echo "  ✓ repository.url matches publishing repo ($REPO_URL)"

# ─── 2. homepage prefix ─────────────────────────────────────────────────────
HOMEPAGE="$(jq -r '.homepage // empty' "$PKG")"
if [ -z "$HOMEPAGE" ]; then
    echo "::error::$PKG missing homepage"
    exit 1
fi

case "$HOMEPAGE" in
    "$EXPECTED_HOMEPAGE_PREFIX"*) ;;
    *)
        echo "::error::$PKG homepage='$HOMEPAGE'"
        echo "  expected to start with: $EXPECTED_HOMEPAGE_PREFIX"
        exit 3
        ;;
esac
echo "  ✓ homepage under expected org ($HOMEPAGE)"

# ─── 3. version sync with CHANGELOG topmost section ─────────────────────────
PKG_VERSION="$(jq -r '.version // empty' "$PKG")"
if [ -z "$PKG_VERSION" ]; then
    echo "::error::$PKG missing version"
    exit 1
fi

CHANGELOG=renderer/react/CHANGELOG.md
if [ ! -f "$CHANGELOG" ]; then
    echo "::error::$CHANGELOG not found"
    exit 1
fi

CHANGELOG_VERSION="$(grep -m1 -E "^## \[[0-9]" "$CHANGELOG" | sed -E 's/^## \[([^]]+)\].*/\1/')"
if [ -z "$CHANGELOG_VERSION" ]; then
    echo "::error::could not extract topmost version from $CHANGELOG"
    exit 1
fi

if [ "$PKG_VERSION" != "$CHANGELOG_VERSION" ]; then
    echo "::error::version drift"
    echo "  $PKG version       = $PKG_VERSION"
    echo "  $CHANGELOG topmost = $CHANGELOG_VERSION"
    echo "  Bump one to match the other before tagging."
    exit 4
fi
echo "  ✓ version $PKG_VERSION matches CHANGELOG topmost section"

# ─── 4. npm pack --dry-run (publishable shape) ──────────────────────────────
if ! (cd renderer/react && npm pack --dry-run > /tmp/ausus-renderer-pack.log 2>&1); then
    echo "::error::npm pack --dry-run failed:"
    tail -20 /tmp/ausus-renderer-pack.log
    exit 5
fi
PACK_VERSION="$(grep -E "^npm notice version:" /tmp/ausus-renderer-pack.log | awk '{print $NF}')"
PACK_FILES="$(grep -E "^npm notice total files:" /tmp/ausus-renderer-pack.log | awk '{print $NF}')"
echo "  ✓ npm pack --dry-run green (version=$PACK_VERSION, files=$PACK_FILES)"

echo "[renderer-provenance] OK"

#!/usr/bin/env bash
#
# AUSUS — Release manifest generator
# -----------------------------------
# Emits a small immutable JSON manifest for a released tag. The manifest is
# the single artifact that ties together every published `ausus/*` Composer
# package, the `@ausus/renderer-react` npm package, their resolved versions,
# the source commit SHA, and the publication timestamp.
#
# Output: artifacts/releases/<version>.json
#
# Use case:
#   - audit a release after the fact without trusting Packagist's mutable
#     listing (Packagist can re-index, npm can deprecate; this manifest is
#     committed to main alongside the release commit);
#   - drive scripts/release-replay.sh / .github/workflows/release-replay.yml
#     with a deterministic source of truth for what to validate.
#
# Usage:   scripts/generate-release-manifest.sh [version]
# Env:     OUTPUT_DIR=artifacts/releases   override output dir
#
# When called without arguments, reads the version from
# docs-site/CURRENT_VERSION (stripping any '(intentional-lag)' marker).
#
# Exit codes:
#   0 = manifest written
#   1 = could not resolve version / missing inputs

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

OUTPUT_DIR="${OUTPUT_DIR:-artifacts/releases}"
VERSION="${1:-}"

if [ -z "$VERSION" ]; then
    if [ ! -f docs-site/CURRENT_VERSION ]; then
        echo "::error::no version arg and docs-site/CURRENT_VERSION not found"
        exit 1
    fi
    VERSION="$(head -1 docs-site/CURRENT_VERSION | sed -E 's/[[:space:]]*\(intentional-lag\)[[:space:]]*$//')"
fi

if [ -z "$VERSION" ]; then
    echo "::error::could not resolve version"
    exit 1
fi

# Strip 'v' prefix for npm/Composer-friendly comparison; keep both forms.
VERSION_BARE="${VERSION#v}"

mkdir -p "$OUTPUT_DIR"
OUTPUT_FILE="$OUTPUT_DIR/${VERSION}.json"

# Resolve the source commit. Prefer the remote-authoritative tag (defeats
# the stale-local-tag pitfall left by release-publish.sh, which reuses the
# version tag name across each subtree push). Fall back to local tag, then
# HEAD (for pre-push manifest generation).
COMMIT_SHA=""
if REMOTE_LINE=$(git ls-remote --tags origin "refs/tags/${VERSION}^{}" 2>/dev/null) && [ -n "$REMOTE_LINE" ]; then
    COMMIT_SHA="$(echo "$REMOTE_LINE" | awk '{print $1}')"
elif git rev-parse --verify "${VERSION}^{}" >/dev/null 2>&1; then
    COMMIT_SHA="$(git rev-parse "${VERSION}^{}")"
elif git rev-parse --verify "${VERSION}" >/dev/null 2>&1; then
    COMMIT_SHA="$(git rev-parse "${VERSION}")"
else
    COMMIT_SHA="$(git rev-parse HEAD)"
fi

PUBLISHED_AT="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"

# All 10 public Composer packages, alphabetical.
PHP_PACKAGES=(api-http audit-database auth-bridge kernel persistence-sql presentation-default runtime-default standard-stack starter tenancy-row)

# Build the packages object via jq for safe JSON quoting.
PACKAGES_JSON='{}'
for pkg in "${PHP_PACKAGES[@]}"; do
    PACKAGES_JSON="$(echo "$PACKAGES_JSON" \
        | jq --arg name "ausus/$pkg" --arg ver "$VERSION" \
            '. + {($name): $ver}')"
done

# Renderer uses bare semver (no 'v' prefix) per npm convention.
PACKAGES_JSON="$(echo "$PACKAGES_JSON" \
    | jq --arg name "@ausus/renderer-react" --arg ver "$VERSION_BARE" \
        '. + {($name): $ver}')"

# Assemble.
jq -n \
    --arg version "$VERSION" \
    --arg tag "$VERSION" \
    --arg commit "$COMMIT_SHA" \
    --arg published_at "$PUBLISHED_AT" \
    --argjson packages "$PACKAGES_JSON" \
    '{version: $version, tag: $tag, commit: $commit, packages: $packages, published_at: $published_at}' \
    > "$OUTPUT_FILE"

echo "[release-manifest] wrote $OUTPUT_FILE"
jq -r '"  version    = " + .version,
       "  tag        = " + .tag,
       "  commit     = " + .commit,
       "  published  = " + .published_at,
       "  packages   = " + (.packages | length | tostring) + " entries"' "$OUTPUT_FILE"

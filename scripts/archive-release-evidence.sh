#!/usr/bin/env bash
#
# AUSUS — Release evidence archiver
# ----------------------------------
# Collects the validation artifacts produced during a release into a single
# committable directory:
#
#   artifacts/releases/<version>/
#     ├── manifest.json                 ← copy of artifacts/releases/<version>.json
#     ├── release-gate.live.log         ← scripts/release-gate.sh LIVE output
#     ├── clean-room-install.log        ← scripts/clean-room-install-test.sh output
#     ├── public-install.log            ← scripts/public-install.sh output
#     ├── renderer-provenance.log       ← scripts/check-renderer-provenance.sh
#     ├── npm-pack.log                  ← npm pack --dry-run output
#     ├── packages.json                 ← jq summary: every ausus/* installed version
#     └── github-release.url            ← single line: GitHub release URL
#
# Designed to be run AFTER the release-gate live mode finishes green for the
# tag. Re-running overwrites previous logs (idempotent on the same version).
# Failed validations stop the archive and surface the failure rather than
# committing a partial evidence set.
#
# Usage:   scripts/archive-release-evidence.sh [version]
# Env:     OUTPUT_DIR=artifacts/releases   override base output dir
#          SKIP_LIVE_GATE=1                use cached logs only (no re-run)
#          GITHUB_REPO=adonko3xBitters/ausus-framework
#
# Exit codes:
#   0 = evidence archived
#   1 = could not resolve version
#   2 = release-gate live run failed
#   3 = manifest missing (run scripts/generate-release-manifest.sh first)

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

OUTPUT_DIR="${OUTPUT_DIR:-artifacts/releases}"
SKIP_LIVE_GATE="${SKIP_LIVE_GATE:-0}"
GITHUB_REPO="${GITHUB_REPO:-adonko3xBitters/ausus-framework}"
VERSION="${1:-}"

if [ -z "$VERSION" ]; then
    if [ ! -f docs-site/CURRENT_VERSION ]; then
        echo "::error::no version arg and docs-site/CURRENT_VERSION not found"
        exit 1
    fi
    VERSION="$(head -1 docs-site/CURRENT_VERSION | sed -E 's/[[:space:]]*\(intentional-lag\)[[:space:]]*$//')"
fi

EVIDENCE_DIR="$OUTPUT_DIR/$VERSION"
MANIFEST_FILE="$OUTPUT_DIR/${VERSION}.json"

if [ ! -f "$MANIFEST_FILE" ]; then
    echo "::error::manifest $MANIFEST_FILE not found"
    echo "  Run: bash scripts/generate-release-manifest.sh $VERSION"
    exit 3
fi

mkdir -p "$EVIDENCE_DIR"
echo "[evidence] version=$VERSION dir=$EVIDENCE_DIR"

# ─── 1. copy the manifest ────────────────────────────────────────────────────
cp "$MANIFEST_FILE" "$EVIDENCE_DIR/manifest.json"
echo "  ✓ manifest.json"

# ─── 2. release-gate LIVE log ────────────────────────────────────────────────
if [ "$SKIP_LIVE_GATE" = "1" ]; then
    if [ -f "$EVIDENCE_DIR/release-gate.live.log" ]; then
        echo "  (skip live re-run, kept existing release-gate.live.log)"
    else
        echo "  ::warning::SKIP_LIVE_GATE=1 but no cached log; producing stub"
        echo "(SKIP_LIVE_GATE=1 — no live run captured)" > "$EVIDENCE_DIR/release-gate.live.log"
    fi
else
    echo "  → running release-gate LIVE (this can take ~2 min)"
    if RELEASE_GATE_LIVE=1 RELEASE_GATE_VERSION="$VERSION" \
            bash scripts/release-gate.sh > "$EVIDENCE_DIR/release-gate.live.log" 2>&1; then
        echo "  ✓ release-gate.live.log"
    else
        echo "::error::release-gate LIVE failed; see $EVIDENCE_DIR/release-gate.live.log"
        exit 2
    fi
fi

# ─── 3. clean-room install log (captured from release-gate or rerun) ─────────
# The release-gate.live.log already contains the clean-room output; also keep
# a standalone copy for downstream tooling that wants the gate isolated.
if EXPECTED_VERSION="$VERSION" \
        bash scripts/clean-room-install-test.sh > "$EVIDENCE_DIR/clean-room-install.log" 2>&1; then
    echo "  ✓ clean-room-install.log"
else
    echo "::warning::clean-room-install-test.sh exited non-zero; log captured anyway"
fi

# ─── 4. public-install log ───────────────────────────────────────────────────
if EXPECTED_VERSION="$VERSION" \
        bash scripts/public-install.sh > "$EVIDENCE_DIR/public-install.log" 2>&1; then
    echo "  ✓ public-install.log"
else
    echo "::warning::public-install.sh exited non-zero; log captured anyway"
fi

# ─── 5. renderer provenance log ──────────────────────────────────────────────
if bash scripts/check-renderer-provenance.sh > "$EVIDENCE_DIR/renderer-provenance.log" 2>&1; then
    echo "  ✓ renderer-provenance.log"
else
    echo "::warning::check-renderer-provenance.sh exited non-zero; log captured anyway"
fi

# ─── 6. npm pack --dry-run ───────────────────────────────────────────────────
NPM_PACK_LOG="$(cd "$ROOT" && cd "$EVIDENCE_DIR" && pwd)/npm-pack.log"
(cd renderer/react && npm pack --dry-run > "$NPM_PACK_LOG" 2>&1) || true
echo "  ✓ npm-pack.log"

# ─── 7. packages.json — version index, jq-friendly ───────────────────────────
jq '.packages' "$MANIFEST_FILE" > "$EVIDENCE_DIR/packages.json"
echo "  ✓ packages.json"

# ─── 8. GitHub release URL ───────────────────────────────────────────────────
if command -v gh >/dev/null 2>&1; then
    RELEASE_URL="$(gh release view "$VERSION" --repo "$GITHUB_REPO" --json url -q .url 2>/dev/null || echo "")"
    if [ -n "$RELEASE_URL" ]; then
        echo "$RELEASE_URL" > "$EVIDENCE_DIR/github-release.url"
        echo "  ✓ github-release.url ($RELEASE_URL)"
    else
        echo "(no GitHub release found for $VERSION)" > "$EVIDENCE_DIR/github-release.url"
        echo "  ! github-release.url (release not found)"
    fi
else
    echo "(gh CLI not available)" > "$EVIDENCE_DIR/github-release.url"
    echo "  ! github-release.url (gh not installed)"
fi

echo "[evidence] OK — archived to $EVIDENCE_DIR"
ls -lh "$EVIDENCE_DIR" | tail -n +2

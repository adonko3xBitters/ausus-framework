#!/usr/bin/env bash
#
# AUSUS clean-room installation test
# ----------------------------------
# Copies the monorepo to a fresh tmp directory (no existing vendor/, node_modules/,
# composer.lock, or package-lock.json) and runs the full publication-ready
# pipeline end-to-end:
#
#   1.  composer validate    (every manifest)
#   2.  composer install     (from path repos)
#   3.  php playground       (36 assertions)
#   4.  composer boot        (starter standalone)
#   5.  npm install          (workspace hoist)
#   6.  npm run build        (renderer dist/)
#   7.  npm run trace        (render assertions; gate is failed=0)
#   8.  npm pack --dry-run   (publishable tarball)
#
# Exits non-zero on any step failure. Streams transcript to stdout.
#
# Usage:   scripts/clean-room.sh
# Env:     KEEP=1   keep the tmp dir for inspection
#

set -euo pipefail

SOURCE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TMP_ROOT="$(mktemp -d -t ausus-cleanroom-XXXXXX)"
WORK_DIR="${TMP_ROOT}/ausus"
LOG_PREFIX="\033[1;36m[clean-room]\033[0m"

trap 'echo "[clean-room] exit code=$?  tmp dir=${TMP_ROOT}"' EXIT
if [[ "${KEEP:-0}" != "1" ]]; then
    trap 'rm -rf "${TMP_ROOT}"; echo "[clean-room] removed ${TMP_ROOT}"' EXIT
fi

log() { printf "${LOG_PREFIX} %s\n" "$*"; }

# ─── 0. Stage source (excluding ephemeral state) ──────────────────────────────
log "stage source → ${WORK_DIR}"
mkdir -p "${WORK_DIR}"
rsync -a \
    --exclude='vendor' \
    --exclude='node_modules' \
    --exclude='composer.lock' \
    --exclude='package-lock.json' \
    --exclude='renderer/react/dist' \
    --exclude='apps/playground/web/dist' \
    --exclude='apps/playground/*.sqlite' \
    "${SOURCE_DIR}/" "${WORK_DIR}/"

cd "${WORK_DIR}"
log "PWD=$(pwd)"

# ─── 1. composer validate every manifest ──────────────────────────────────────
log "step 1 — composer validate (9 manifests)"
fail=0
for f in composer.json packages/*/composer.json; do
    if ! composer validate --no-check-publish --no-check-lock --no-check-version "$f" >/dev/null 2>&1; then
        echo "  ✗ $f"
        composer validate --no-check-publish --no-check-lock --no-check-version "$f" || true
        fail=$((fail+1))
    else
        echo "  ✓ $f"
    fi
done
[[ $fail -gt 0 ]] && { echo "VALIDATE FAILED ($fail manifest(s))"; exit 2; }

# ─── 2. composer install (path repos resolve) ─────────────────────────────────
log "step 2 — composer install"
composer install --no-interaction --prefer-dist 2>&1 | tail -8

# ─── 3. PHP playground smoke (36 assertions) ──────────────────────────────────
log "step 3 — php apps/playground/run.php"
php apps/playground/run.php > /tmp/ausus-cleanroom-php.log 2>&1
tail -3 /tmp/ausus-cleanroom-php.log
grep -q "RESULT: passed=36 failed=0" /tmp/ausus-cleanroom-php.log \
    || { echo "PHP SMOKE FAILED"; cat /tmp/ausus-cleanroom-php.log; exit 3; }

# ─── 4. composer boot (starter standalone) ────────────────────────────────────
log "step 4 — composer --working-dir=packages/starter boot"
composer --working-dir=packages/starter boot 2>&1 | tail -8
# Re-run to capture exit code cleanly
composer --working-dir=packages/starter boot >/dev/null 2>&1 \
    || { echo "STARTER BOOT FAILED"; exit 4; }

# ─── 5. npm install (workspace hoist) ─────────────────────────────────────────
log "step 5 — npm install (workspace)"
npm install --no-audit --no-fund 2>&1 | tail -5

# ─── 6. npm build renderer ────────────────────────────────────────────────────
log "step 6 — npm run build"
npm run build 2>&1 | tail -5
[[ -f renderer/react/dist/index.js ]] || { echo "BUILD MISSING dist/index.js"; exit 5; }

# ─── 7. npm trace (render assertions; gate is failed=0) ──────────────────────
log "step 7 — npm run trace"
npm run trace > /tmp/ausus-cleanroom-trace.log 2>&1
tail -3 /tmp/ausus-cleanroom-trace.log
# The assertion count grows as the trace adds coverage; only the
# `failed=0` part is the gate. Mirrors scripts/ci.sh:147.
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-cleanroom-trace.log \
    || { echo "TRACE FAILED"; tail -40 /tmp/ausus-cleanroom-trace.log; exit 6; }

# ─── 8. npm pack --dry-run (publishable tarball) ──────────────────────────────
log "step 8 — npm pack --dry-run"
(cd renderer/react && npm pack --dry-run 2>&1 | grep -E "(name|version|filename|package size|total files):") | sed 's/^/  /'

# ─── Done ─────────────────────────────────────────────────────────────────────
echo ""
log "ALL STEPS PASSED"
log "monorepo at ${WORK_DIR}"
[[ "${KEEP:-0}" != "1" ]] && log "(set KEEP=1 to retain)"

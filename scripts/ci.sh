#!/usr/bin/env bash
#
# AUSUS CI command set
# --------------------
# Single-pass build that any CI system (GitHub Actions, GitLab, Circle) can
# invoke. Runs in-place against the current checkout (does NOT copy to /tmp).
# For an isolated clean-room rebuild, use scripts/clean-room.sh.
#
# Steps:
#   1. composer validate     all 9 manifests
#   2. composer install      from path repos
#   3. phpunit               (if any test class exists; skipped otherwise)
#   4. php playground smoke  36 assertions
#   5. composer boot         starter standalone
#   6. npm ci                workspace lockfile-strict install (falls back to install)
#   7. npm run build         renderer/react/dist
#   8. npm run trace         12 render assertions
#   9. npm pack --dry-run    publishable tarball gate
#
# Usage:   scripts/ci.sh
#

set -euo pipefail

cd "$(dirname "$0")/.."
echo "[ci] root=$(pwd)"
echo "[ci] php=$(php --version | head -1)"
echo "[ci] composer=$(composer --version)"
echo "[ci] node=$(node --version)  npm=$(npm --version)"

# 1
echo "[ci] step 1 — composer validate"
fail=0
for f in composer.json packages/*/composer.json; do
    composer validate --no-check-publish --no-check-lock --no-check-version "$f" >/dev/null 2>&1 \
        && echo "  ✓ $f" \
        || { echo "  ✗ $f"; composer validate --no-check-publish --no-check-lock --no-check-version "$f" || true; fail=$((fail+1)); }
done
[[ $fail -gt 0 ]] && { echo "validate failed ($fail)"; exit 2; }

# 2
echo "[ci] step 2 — composer install"
composer install --no-interaction --prefer-dist 2>&1 | tail -6

# 3
echo "[ci] step 3 — phpunit"
if find packages -name '*Test.php' -type f 2>/dev/null | grep -q .; then
    vendor/bin/phpunit --colors=never 2>&1 | tail -20 || { echo "phpunit failed"; exit 3; }
else
    echo "  (no *Test.php files yet — skipping phpunit)"
fi

# 4
echo "[ci] step 4 — php apps/playground/run.php"
php apps/playground/run.php > /tmp/ausus-ci-php.log 2>&1
grep -q "RESULT: passed=36 failed=0" /tmp/ausus-ci-php.log \
    && echo "  ✓ playground 36/36" \
    || { echo "playground failed"; tail -50 /tmp/ausus-ci-php.log; exit 4; }

# 5
echo "[ci] step 5 — composer boot (starter)"
composer --working-dir=packages/starter boot >/dev/null 2>&1 \
    && echo "  ✓ starter boots cleanly" \
    || { echo "starter boot failed"; composer --working-dir=packages/starter boot; exit 5; }

# 6
echo "[ci] step 6 — npm ci (or install fallback)"
if [[ -f package-lock.json ]]; then
    npm ci --no-audit --no-fund 2>&1 | tail -3
else
    npm install --no-audit --no-fund 2>&1 | tail -3
fi

# 7
echo "[ci] step 7 — npm run build"
npm run build 2>&1 | tail -3
[[ -f renderer/react/dist/index.js ]] || { echo "build missing dist/index.js"; exit 7; }
echo "  ✓ renderer/react/dist built"

# 8
echo "[ci] step 8 — npm run trace"
npm run trace > /tmp/ausus-ci-trace.log 2>&1
grep -q "RESULT: passed=12 failed=0" /tmp/ausus-ci-trace.log \
    && echo "  ✓ trace 12/12" \
    || { echo "trace failed"; tail -30 /tmp/ausus-ci-trace.log; exit 8; }

# 9
echo "[ci] step 9 — npm pack --dry-run"
(cd renderer/react && npm pack --dry-run 2>&1 | grep -E "(version|package size|total files):" | sed 's/^/  /')

# 10
echo "[ci] step 10 — L4 HTTP integration (live php -S + renderer-react)"
bash scripts/integration-http.sh > /tmp/ausus-ci-integration.log 2>&1
grep -q "RESULT: passed=12 failed=0" /tmp/ausus-ci-integration.log \
    && echo "  ✓ integration-http 12/12" \
    || { echo "integration-http failed"; tail -50 /tmp/ausus-ci-integration.log; exit 10; }

# 11 — consumer DX pass (3 external apps, real end-to-end)
echo "[ci] step 11 — consumer DX (3 external apps)"
php apps/consumer-minimal-crud/run.php > /tmp/ausus-ci-c1.log 2>&1 \
    && grep -q "consumer-minimal-crud — OK" /tmp/ausus-ci-c1.log \
    && echo "  ✓ consumer-minimal-crud" \
    || { echo "consumer-minimal-crud failed"; tail -20 /tmp/ausus-ci-c1.log; exit 11; }
php apps/consumer-multi-tenant/run.php > /tmp/ausus-ci-c2.log 2>&1 \
    && grep -q "consumer-multi-tenant — OK" /tmp/ausus-ci-c2.log \
    && echo "  ✓ consumer-multi-tenant" \
    || { echo "consumer-multi-tenant failed"; tail -20 /tmp/ausus-ci-c2.log; exit 11; }
npx tsx apps/consumer-custom-renderer/run.tsx > /tmp/ausus-ci-c3.log 2>&1 \
    && grep -q "RESULT: passed=9 failed=0" /tmp/ausus-ci-c3.log \
    && echo "  ✓ consumer-custom-renderer (9/9)" \
    || { echo "consumer-custom-renderer failed"; tail -20 /tmp/ausus-ci-c3.log; exit 11; }

echo "[ci] DONE — all 11 steps passed"

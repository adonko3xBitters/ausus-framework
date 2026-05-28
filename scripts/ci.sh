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
#   4b. application smoke    Ausus\Application bootstrap assertions
#   4c. workflow smoke       explicit Workflow declaration assertions
#   4d. api-consistency      v0.1.x public API consistency assertions
#   4e. config-builder       ApplicationConfig fluent builder assertions
#   4f. application-http     Application::http() PSR-7 entry-point assertions
#   4g. issue-tracker smoke  end-to-end sample-app assertions
#   4h. null-roundtrip       nullable field SQL ↔ PHP ↔ JSON regression
#   4i. update-action        ADR-0002 Action::update(...) + UpdateEffect tests
#   4j. error-taxonomy       Phase C marker-first ErrorMapper dispatch tests
#   5. composer boot         starter standalone
#   6. npm ci                workspace lockfile-strict install (falls back to install)
#   7. npm run build         renderer/react/dist
#   8. npm run trace         12 render assertions
#   9. npm pack --dry-run    publishable tarball gate
#  10. L4 HTTP integration   live php -S + renderer-react
#  11. public-install        Packagist clean-room install validation
#                            (consumes the public registry, not path repos —
#                            guards against any regression of the v0.1.x
#                            packaging defect)
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
    composer validate --no-check-publish --no-check-lock --strict "$f" >/dev/null 2>&1 \
        && echo "  ✓ $f" \
        || { echo "  ✗ $f"; composer validate --no-check-publish --no-check-lock --strict "$f" || true; fail=$((fail+1)); }
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

# 4b
echo "[ci] step 4b — php apps/playground/application-smoke.php"
php apps/playground/application-smoke.php > /tmp/ausus-ci-app.log 2>&1
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-app.log \
    && echo "  ✓ application-smoke $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-app.log | head -1)" \
    || { echo "application-smoke failed"; tail -50 /tmp/ausus-ci-app.log; exit 4; }

# 4c
echo "[ci] step 4c — php apps/playground/workflow-test.php"
php apps/playground/workflow-test.php > /tmp/ausus-ci-wf.log 2>&1
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-wf.log \
    && echo "  ✓ workflow-test $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-wf.log | head -1)" \
    || { echo "workflow-test failed"; tail -50 /tmp/ausus-ci-wf.log; exit 4; }

# 4d
echo "[ci] step 4d — php apps/playground/api-consistency-test.php"
php apps/playground/api-consistency-test.php > /tmp/ausus-ci-api.log 2>&1
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-api.log \
    && echo "  ✓ api-consistency $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-api.log | head -1)" \
    || { echo "api-consistency failed"; tail -50 /tmp/ausus-ci-api.log; exit 4; }

# 4e
echo "[ci] step 4e — php apps/playground/config-builder-test.php"
php apps/playground/config-builder-test.php > /tmp/ausus-ci-cfg.log 2>&1
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-cfg.log \
    && echo "  ✓ config-builder $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-cfg.log | head -1)" \
    || { echo "config-builder failed"; tail -50 /tmp/ausus-ci-cfg.log; exit 4; }

# 4f
echo "[ci] step 4f — php apps/playground/application-http-test.php"
php apps/playground/application-http-test.php > /tmp/ausus-ci-http.log 2>&1
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-http.log \
    && echo "  ✓ application-http $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-http.log | head -1)" \
    || { echo "application-http failed"; tail -50 /tmp/ausus-ci-http.log; exit 4; }

# 4g
echo "[ci] step 4g — php apps/issue-tracker/tests/smoke.php"
php apps/issue-tracker/tests/smoke.php > /tmp/ausus-ci-tracker.log 2>&1
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-tracker.log \
    && echo "  ✓ issue-tracker $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-tracker.log | head -1)" \
    || { echo "issue-tracker failed"; tail -50 /tmp/ausus-ci-tracker.log; exit 4; }

# 4h
echo "[ci] step 4h — php apps/playground/null-roundtrip-test.php"
php apps/playground/null-roundtrip-test.php > /tmp/ausus-ci-null.log 2>&1
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-null.log \
    && echo "  ✓ null-roundtrip $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-null.log | head -1)" \
    || { echo "null-roundtrip failed"; tail -50 /tmp/ausus-ci-null.log; exit 4; }

# 4i
echo "[ci] step 4i — php apps/playground/update-action-test.php"
php apps/playground/update-action-test.php > /tmp/ausus-ci-update.log 2>&1
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-update.log \
    && echo "  ✓ update-action $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-update.log | head -1)" \
    || { echo "update-action failed"; tail -50 /tmp/ausus-ci-update.log; exit 4; }

# 4j
echo "[ci] step 4j — php apps/playground/error-taxonomy-test.php"
php apps/playground/error-taxonomy-test.php > /tmp/ausus-ci-errors.log 2>&1
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-errors.log \
    && echo "  ✓ error-taxonomy $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-errors.log | head -1)" \
    || { echo "error-taxonomy failed"; tail -50 /tmp/ausus-ci-errors.log; exit 4; }

# 4k
echo "[ci] step 4k — php apps/playground/pagination-test.php"
php apps/playground/pagination-test.php > /tmp/ausus-ci-pagination.log 2>&1
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-pagination.log \
    && echo "  ✓ pagination $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-pagination.log | head -1)" \
    || { echo "pagination failed"; tail -50 /tmp/ausus-ci-pagination.log; exit 4; }

# 4l
echo "[ci] step 4l — php apps/playground/filtering-test.php"
php apps/playground/filtering-test.php > /tmp/ausus-ci-filtering.log 2>&1
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-filtering.log \
    && echo "  ✓ filtering $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-filtering.log | head -1)" \
    || { echo "filtering failed"; tail -50 /tmp/ausus-ci-filtering.log; exit 4; }

# 4m
echo "[ci] step 4m — php apps/playground/filtering-http-test.php"
php apps/playground/filtering-http-test.php > /tmp/ausus-ci-filteringhttp.log 2>&1
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-filteringhttp.log \
    && echo "  ✓ filtering-http $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-filteringhttp.log | head -1)" \
    || { echo "filtering-http failed"; tail -50 /tmp/ausus-ci-filteringhttp.log; exit 4; }

# 4n
echo "[ci] step 4n — php apps/playground/sorting-test.php"
php apps/playground/sorting-test.php > /tmp/ausus-ci-sorting.log 2>&1
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-sorting.log \
    && echo "  ✓ sorting $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-sorting.log | head -1)" \
    || { echo "sorting failed"; tail -50 /tmp/ausus-ci-sorting.log; exit 4; }

# 4o
echo "[ci] step 4o — php apps/playground/sorting-sql-test.php"
php apps/playground/sorting-sql-test.php > /tmp/ausus-ci-sortingsql.log 2>&1
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-sortingsql.log \
    && echo "  ✓ sorting-sql $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-sortingsql.log | head -1)" \
    || { echo "sorting-sql failed"; tail -50 /tmp/ausus-ci-sortingsql.log; exit 4; }

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
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-trace.log \
    && echo "  ✓ trace $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-trace.log | head -1)" \
    || { echo "trace failed"; tail -30 /tmp/ausus-ci-trace.log; exit 8; }

# 9
echo "[ci] step 9 — npm pack --dry-run"
(cd renderer/react && npm pack --dry-run 2>&1 | grep -E "(version|package size|total files):" | sed 's/^/  /')

# 10
echo "[ci] step 10 — L4 HTTP integration (live php -S + renderer-react)"
bash scripts/integration-http.sh > /tmp/ausus-ci-integration.log 2>&1
grep -qE "RESULT: passed=[0-9]+ failed=0" /tmp/ausus-ci-integration.log \
    && echo "  ✓ integration-http $(grep -oE 'passed=[0-9]+' /tmp/ausus-ci-integration.log | head -1)" \
    || { echo "integration-http failed"; tail -50 /tmp/ausus-ci-integration.log; exit 10; }

# 11
echo "[ci] step 11 — public-install validation (Packagist clean-room)"
bash scripts/public-install.sh > /tmp/ausus-ci-publicinstall.log 2>&1
grep -q "^\[public-install\] OK$" /tmp/ausus-ci-publicinstall.log \
    && echo "  ✓ public-install reached the OK gate" \
    || { echo "public-install failed"; tail -50 /tmp/ausus-ci-publicinstall.log; exit 11; }

echo "[ci] DONE — all 11 steps passed"

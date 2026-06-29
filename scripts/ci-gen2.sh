#!/usr/bin/env bash
#
# AUSUS — Gen2 (Entity Engine) CI orchestrator
# --------------------------------------------
# The Gen2 counterpart of scripts/ci.sh. It ONLY orchestrates the existing
# Gen2 test suites and packaging checks — it defines no new test and modifies
# no business code. Used as the build step of the Gen2 release gate.
#
# Steps:
#   1. composer validate   — root + the 7 Gen2 Composer manifests (--strict)
#   2. composer install     — Gen2-only workspace from path repos
#   3. PHP suites           — the 20 Entity Engine + reference-app suites
#   4. JS suites            — the 5 renderer / view-system / app suites
#   5. npm pack --dry-run   — @ausus/react-renderer publishable shape
#
# Exit non-zero on the first failing group.

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
echo "[ci-gen2] root=$ROOT"
echo "[ci-gen2] php=$(php --version | head -1)"

# ─── 1. composer validate (root + 7 Gen2 packages) ──────────────────────────
echo "[ci-gen2] step 1 — composer validate"
fail=0
for f in composer.json packages/kernel/composer.json packages/entity-engine/composer.json \
         packages/authoring/composer.json packages/persistence-memory/composer.json \
         packages/api-runtime/composer.json packages/view-system/composer.json \
         packages/cli/composer.json; do
    if composer validate --no-check-publish --no-check-lock --strict "$f" >/dev/null 2>&1; then
        echo "  ✓ $f"
    else
        echo "  ✗ $f"; fail=$((fail+1))
    fi
done
[ "$fail" -eq 0 ] || { echo "::error::composer validate failed ($fail)"; exit 2; }

# ─── 2. composer install (Gen2-only workspace) ──────────────────────────────
echo "[ci-gen2] step 2 — composer install"
composer install --no-interaction --prefer-dist 2>&1 | tail -4

# ─── 3. PHP suites (Entity Engine + reference apps) ─────────────────────────
echo "[ci-gen2] step 3 — PHP suites"
PHP_SUITES=(
    packages/kernel/tests/phase0-definition-dtos.php
    packages/entity-engine/tests/canonicalizer-test.php
    packages/entity-engine/tests/hasher-test.php
    packages/entity-engine/tests/closure-validator-test.php
    packages/entity-engine/tests/compiler-test.php
    packages/entity-engine/tests/inmemory-schema-repository-test.php
    packages/entity-engine/tests/authorization-evaluator-test.php
    packages/entity-engine/tests/runtime-entity-test.php
    packages/entity-engine/tests/projection-query-test.php
    packages/entity-engine/tests/projection-aggregation-test.php
    packages/authoring/tests/dsl-definition-test.php
    packages/authoring/tests/dsl-expression-test.php
    packages/authoring/tests/dsl-equivalence-test.php
    packages/persistence-memory/tests/driver-conformance-test.php
    packages/cli/tests/dsl-frontend-test.php
    packages/cli/tests/file-schema-repository-test.php
    packages/cli/tests/compile-entities-test.php
    packages/api-runtime/tests/runtime-api-test.php
    packages/api-runtime/tests/projection-query-http-test.php
    packages/api-runtime/tests/projection-aggregation-http-test.php
    packages/view-system/tests/view-system-test.php
    apps/crm/tests/crm-validation-test.php
    apps/teranga-pms/tests/pms-validation-test.php
    apps/sgh/tests/sgh-validation-test.php
)
pp=0
for t in "${PHP_SUITES[@]}"; do
    out=$(php "$t" 2>&1)
    if [ $? -eq 0 ] && ! echo "$out" | grep -qE 'failed=[1-9]|Fatal|Uncaught|FAILED'; then
        pp=$((pp+1))
    else
        echo "::error::PHP suite failed: $t"; echo "$out" | tail -5; exit 3
    fi
done
echo "  ✓ PHP suites: $pp/${#PHP_SUITES[@]}"

# ─── 4. JS suites (renderer / view-system / apps) ───────────────────────────
echo "[ci-gen2] step 4 — JS suites"
JS_SUITES=(
    packages/react-renderer/tests/renderer.test.ts
    packages/react-renderer/tests/projection-query.test.ts
    packages/react-renderer/tests/projection-aggregation.test.ts
    packages/view-system/tests/view-renderer.test.ts
    apps/crm/tests/crm-renderer.test.ts
    apps/teranga-pms/tests/pms-renderer.test.ts
    apps/sgh/tests/sgh-renderer.test.ts
)
jp=0
for t in "${JS_SUITES[@]}"; do
    f=$(node --test --experimental-strip-types "$t" 2>&1 | grep '^# fail' | grep -oE '[0-9]+' || echo 1)
    if [ "$f" = "0" ]; then jp=$((jp+1)); else echo "::error::JS suite failed: $t"; exit 4; fi
done
echo "  ✓ JS suites: $jp/${#JS_SUITES[@]}"

# ─── 5. npm pack --dry-run (renderer publishable shape) ─────────────────────
echo "[ci-gen2] step 5 — npm pack --dry-run (@ausus/react-renderer)"
(cd packages/react-renderer && npm pack --dry-run > /tmp/ausus-gen2-pack.log 2>&1) \
    || { echo "::error::npm pack --dry-run failed"; tail -10 /tmp/ausus-gen2-pack.log; exit 5; }
echo "  ✓ $(grep -E '^npm notice (name|version|total files):' /tmp/ausus-gen2-pack.log | tr '\n' ' ')"

echo "[ci-gen2] OK — Gen2 line green"

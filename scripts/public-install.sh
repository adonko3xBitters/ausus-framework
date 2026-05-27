#!/usr/bin/env bash
#
# AUSUS — Public Packagist install validation
# -------------------------------------------
# Reproduces a clean-room install of the public ausus/* packages straight
# from Packagist. NO monorepo. NO path-repo. NO symlink. NO pre-existing
# vendor. Exactly what an external consumer sees from the public registry.
#
# Validates:
#   1. composer require ausus/standard-stack:^0.2@alpha resolves
#   2. all ausus/* installed at the expected alpha version
#   3. vendor/ausus/* tarballs do NOT contain the monorepo (no packages/,
#      apps/, docs-site/, renderer/, scripts/, .github/)
#   4. the public class surface is reachable via Composer autoload
#   5. a smoke Application::create(...) call succeeds
#
# Used as the final gate of scripts/ci.sh — should be green before any
# tag/publish. Designed to fail the CI loudly if Packagist or the subtree
# split chain ever regresses to the v0.1.x packaging defect.
#
# Auto-cleans the tmp dir on exit (set KEEP=1 to retain for inspection).
#
# Usage:   scripts/public-install.sh
# Env:     KEEP=1                  keep the tmp dir for inspection
#          EXPECTED_VERSION=...    override the version to assert against
#                                  (default: v0.2.0-alpha.3)

set -euo pipefail

EXPECTED_VERSION="${EXPECTED_VERSION:-v0.2.0-alpha.3}"
TMP_DIR="$(mktemp -d -t ausus-public-install-XXXXXX)"
SMOKE_DB="${TMP_DIR}/ausus-smoke.sqlite"

cleanup() {
    rc=$?
    if [ "${KEEP:-0}" = "1" ]; then
        echo "[public-install] keeping tmp dir: ${TMP_DIR}"
    else
        rm -rf "${TMP_DIR}"
    fi
    if [ "${rc}" -ne 0 ]; then
        echo "[public-install] FAILED (exit=${rc})"
    fi
    return "${rc}"
}
trap cleanup EXIT

cd "${TMP_DIR}"

echo "[public-install] tmp dir=${TMP_DIR}"
echo "[public-install] expected version=${EXPECTED_VERSION}"

# ─── step 1 — composer init with alpha stability ─────────────────────────────
echo "[public-install] step 1 — composer init (alpha stability)"
cat > composer.json <<'JSON'
{
    "name": "ausus-internal/public-install-validation",
    "description": "Synthetic consumer used by scripts/public-install.sh to verify Packagist distribution end-to-end.",
    "type": "project",
    "license": "MIT",
    "minimum-stability": "alpha",
    "prefer-stable": true,
    "require": {}
}
JSON
echo "  ✓ composer.json written (minimum-stability=alpha, prefer-stable=true)"

# ─── step 2 — composer require from Packagist (no local source) ──────────────
echo "[public-install] step 2 — composer require ausus/standard-stack:^0.2@alpha"
if ! composer require "ausus/standard-stack:^0.2@alpha" \
        --no-interaction --no-cache > "${TMP_DIR}/composer.log" 2>&1; then
    echo "  ✗ composer require failed:"
    tail -30 "${TMP_DIR}/composer.log" | sed 's/^/    /'
    exit 2
fi
echo "  ✓ composer require succeeded"

# ─── step 3 — verify resolved versions ───────────────────────────────────────
echo "[public-install] step 3 — verify all ausus/* installed at ${EXPECTED_VERSION}"
fail=0
for pkg in ausus/kernel ausus/runtime-default ausus/persistence-sql ausus/api-http ausus/standard-stack; do
    installed="$(
        composer show "${pkg}" 2>/dev/null \
        | awk '/^versions/ { gsub(/[*,]/, ""); for (i=2; i<=NF; i++) { v=$i; if (v != ":" && v != "") { print v; exit } } }'
    )"
    if [ "${installed}" = "${EXPECTED_VERSION}" ]; then
        printf "  ✓ %-30s %s\n" "${pkg}" "${installed}"
    else
        printf "  ✗ %-30s %s (expected %s)\n" "${pkg}" "${installed}" "${EXPECTED_VERSION}"
        fail=$((fail + 1))
    fi
done
if [ "${fail}" -ne 0 ]; then
    echo "[public-install] version mismatch on ${fail} package(s)"
    exit 3
fi

# ─── step 4 — verify vendor structure (no monorepo embedding) ────────────────
echo "[public-install] step 4 — verify no monorepo embedded in vendor/ausus/*"
fail=0
for pkg in kernel runtime-default persistence-sql api-http standard-stack; do
    found=""
    for sub in packages apps docs-site renderer scripts .github; do
        if [ -d "vendor/ausus/${pkg}/${sub}" ]; then
            found="${found} ${sub}/"
        fi
    done
    if [ -z "${found}" ]; then
        printf "  ✓ vendor/ausus/%-22s clean\n" "${pkg}"
    else
        printf "  ✗ vendor/ausus/%-22s contains:%s\n" "${pkg}" "${found}"
        fail=$((fail + 1))
    fi
done
if [ "${fail}" -ne 0 ]; then
    echo "[public-install] monorepo embedding detected in ${fail} tarball(s)"
    exit 4
fi

# ─── step 5 — verify all required classes are loadable via autoload ──────────
echo "[public-install] step 5 — verify autoload reaches v0.2 public surface"
cat > classes.php <<'PHP'
<?php
require 'vendor/autoload.php';
$want = [
    'Ausus\Application',
    'Ausus\ApplicationConfig',
    'Ausus\Compiler',
    'Ausus\BuiltinEffect',
    'Ausus\InvocationResult',
    'Ausus\Api\Http\Router',
    'Ausus\Api\Http\ErrorMapper',
    'Ausus\Errors\BadRequestError',
    'Ausus\Errors\ForbiddenError',
    'Ausus\Errors\NotFoundError',
    'Ausus\Errors\ConflictError',
    'Ausus\Errors\InternalError',
];
$fail = 0;
foreach ($want as $c) {
    $ok = class_exists($c) || interface_exists($c) || enum_exists($c);
    printf("  %s %s\n", $ok ? "\xE2\x9C\x93" : "\xE2\x9C\x97", $c);
    if (!$ok) $fail++;
}
exit($fail === 0 ? 0 : 5);
PHP
if ! php classes.php; then
    echo "[public-install] autoload check failed"
    exit 5
fi

# ─── step 6 — smoke test: Application::create against SQLite ─────────────────
echo "[public-install] step 6 — smoke test (Application::create on SQLite)"
cat > smoke.php <<PHP
<?php
require 'vendor/autoload.php';
\$app = Ausus\\Application::create(
    Ausus\\ApplicationConfig::make()
        ->tenant('acme')
        ->actor('demo')
        ->sqlite('${SMOKE_DB}')
);
if (\$app instanceof Ausus\\Application) {
    echo "  \xE2\x9C\x93 Application instance created\n";
    exit(0);
}
echo "  \xE2\x9C\x97 Application::create did not return an Application\n";
exit(6);
PHP
if ! php smoke.php; then
    echo "[public-install] smoke test failed"
    exit 6
fi

echo "[public-install] OK"

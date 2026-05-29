#!/usr/bin/env bash
#
# AUSUS — Public Packagist install validation
# -------------------------------------------
# Reproduces a clean-room install of the public ausus/* packages straight
# from Packagist. NO monorepo. NO path-repo. NO symlink. NO pre-existing
# vendor. Exactly what an external consumer sees from the public registry.
#
# Validates:
#   1. composer require ausus/standard-stack:^1.0 resolves
#   2. all ausus/* installed at the expected alpha version
#   3. vendor/ausus/* tarballs do NOT contain the monorepo (no packages/,
#      apps/, docs-site/, renderer/, scripts/, .github/)
#   4. the public class surface is reachable via Composer autoload
#   5. a smoke Application::create(...) call succeeds
#   6. (step 7) the rendered ViewSchema matches the expected wire shape for
#      the published version — schemaVersion plus the per-version key set
#      (1.0.0 baseline, 1.2.0+ adds top-level 'sort' + extended pagination)
#
# Used as the final gate of scripts/ci.sh — should be green before any
# tag/publish. Designed to fail the CI loudly if Packagist or the subtree
# split chain ever regresses to the v0.1.x packaging defect.
#
# Auto-cleans the tmp dir on exit (set KEEP=1 to retain for inspection).
#
# Usage:   scripts/public-install.sh
# Env:     KEEP=1                       keep the tmp dir for inspection
#          EXPECTED_VERSION=...         override the version to assert against
#                                       (default: v0.2.0-rc.1 during the v1.0
#                                        prep window; bumped to v1.0.0
#                                        post-publish)
#          EXPECTED_SCHEMA_VERSION=...  override the wire schemaVersion
#                                       (default derived from EXPECTED_VERSION:
#                                        v0.1.* / v0.2.0-alpha.[1-5] → 1.0.0;
#                                        v0.2.0-alpha.6+ / v0.2.0-beta.* → 1.2.0)
#
# Exit codes:
#   0 = all green
#   2 = composer require failure
#   3 = installed version mismatch
#   4 = monorepo embedded in a tarball
#   5 = autoload class check failure
#   6 = Application::create smoke failure
#   7 = ViewSchema wire shape assertion failure (NEW)

set -euo pipefail

EXPECTED_VERSION="${EXPECTED_VERSION:-v0.2.0-rc.1}"

# Wire-shape pin per release version. v0.1.* and v0.2.0-alpha.[1-5] all ship
# the 1.0.0 ViewSchema (pagination shape was {nextCursor, pageSize}); every
# release from v0.2.0-alpha.6 / v0.2.0-beta.* / v0.2.0-rc.* / v1.* onward
# ships 1.2.0 (pagination extended with {limit, offset, totalCount} plus
# top-level sort echo). Note: the 'schemaVersion' string '1.2.0' is unrelated
# to the package version '1.0.0' — the wire is on its own SemVer.
case "${EXPECTED_VERSION}" in
    v0.1.*|v0.2.0-alpha.[1-5])
        DEFAULT_SCHEMA_VERSION="1.0.0"
        ;;
    *)
        DEFAULT_SCHEMA_VERSION="1.2.0"
        ;;
esac
EXPECTED_SCHEMA_VERSION="${EXPECTED_SCHEMA_VERSION:-${DEFAULT_SCHEMA_VERSION}}"

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
echo "[public-install] expected schema=${EXPECTED_SCHEMA_VERSION}"

# ─── step 1 — composer init with alpha stability ─────────────────────────────
echo "[public-install] step 1 — composer init (alpha stability)"
cat > composer.json <<'JSON'
{
    "name": "ausus-internal/public-install-validation",
    "description": "Synthetic consumer used by scripts/public-install.sh to verify Packagist distribution end-to-end.",
    "type": "project",
    "license": "MIT",
    "minimum-stability": "rc",
    "prefer-stable": true,
    "require": {}
}
JSON
echo "  ✓ composer.json written (minimum-stability=rc during v1.0 prep window)"

# ─── step 2 — composer require from Packagist (no local source) ──────────────
echo "[public-install] step 2 — composer require ausus/standard-stack:^0.2@rc"
if ! composer require "ausus/standard-stack:^0.2@rc" \
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

# ─── step 7 — verify ViewSchema wire shape end-to-end ────────────────────────
# Boots a tiny in-place plugin, renders its `all` projection through the
# alpha-5+ ProjectionRenderer, and pins the wire shape against the version's
# expected schemaVersion. The keys we look at depend on EXPECTED_SCHEMA_VERSION:
#
#   1.0.0 — schemaVersion present + 'filters' top-level key (always []);
#           data.pagination has 'nextCursor' + 'pageSize'.
#   1.2.0 — schemaVersion === 1.2.0; top-level 'filters' AND 'sort' echoes;
#           data.pagination has 'limit' / 'offset' / 'totalCount' / 'pageSize'
#           / 'nextCursor'.
#
# Local-mirror coverage for the 1.2.0 path lives in
# apps/playground/integration-filter-sort-test.php (30 assertions including
# the exact same wire shape pins).
echo "[public-install] step 7 — ViewSchema wire shape (schemaVersion ${EXPECTED_SCHEMA_VERSION})"
cat > wire.php <<PHP
<?php
require 'vendor/autoload.php';

\$plugin = new class extends Ausus\\DslPlugin {
    public function name(): string         { return 'pi'; }
    public function phpNamespace(): string { return 'PI'; }
    public function dsl(Ausus\\Dsl \$dsl): void {
        \$dsl->entity('row')
            ->fields([
                'name'  => Ausus\\Field::string()->max(40),
                'state' => Ausus\\Field::enum('NEW', 'DONE')->default('NEW'),
            ])
            ->workflow(field: 'state', initial: 'NEW')
            ->projection('all', fields: ['id', 'name', 'state']);
    }
};

\$app = Ausus\\Application::create(
    Ausus\\ApplicationConfig::make()
        ->tenant('acme')
        ->actor('demo')
        ->sqlite('${SMOKE_DB}-wire')
)->register(\$plugin)->boot();

\$schema = \$app->renderer()->render('pi.row.all');

\$expectedSchema = '${EXPECTED_SCHEMA_VERSION}';
\$fail = 0;

// Universal invariants — every wire version since 1.0.0 carries these.
if ((\$schema['schemaVersion'] ?? null) !== \$expectedSchema) {
    fwrite(STDERR, "  ✗ schemaVersion=" . var_export(\$schema['schemaVersion'] ?? null, true)
        . " (expected '\$expectedSchema')\n");
    \$fail++;
} else {
    echo "  ✓ schemaVersion = \$expectedSchema\n";
}
foreach (['fields', 'actions', 'filters', 'data'] as \$key) {
    if (!array_key_exists(\$key, \$schema)) {
        fwrite(STDERR, "  ✗ top-level key '\$key' missing\n");
        \$fail++;
    } else {
        echo "  ✓ top-level key '\$key' present\n";
    }
}
if (!is_array(\$schema['data']['pagination'] ?? null)) {
    fwrite(STDERR, "  ✗ data.pagination missing or not an object\n");
    \$fail++;
} else {
    echo "  ✓ data.pagination present\n";
}

// 1.2.0+ invariants — pagination expanded with limit/offset/totalCount and
// the top-level 'sort' echo. 1.0.0 / 1.1.0 consumers do not see these.
if (version_compare(\$expectedSchema, '1.2.0', '>=')) {
    if (!array_key_exists('sort', \$schema)) {
        fwrite(STDERR, "  ✗ top-level 'sort' echo missing (required at >= 1.2.0)\n");
        \$fail++;
    } else {
        echo "  ✓ top-level 'sort' echo present\n";
    }
    foreach (['limit', 'offset', 'totalCount', 'pageSize', 'nextCursor'] as \$pk) {
        if (!array_key_exists(\$pk, \$schema['data']['pagination'])) {
            fwrite(STDERR, "  ✗ data.pagination.\$pk missing (required at >= 1.2.0)\n");
            \$fail++;
        } else {
            echo "  ✓ data.pagination.\$pk present\n";
        }
    }
}

exit(\$fail === 0 ? 0 : 7);
PHP
if ! php wire.php; then
    echo "[public-install] wire shape assertion failed"
    exit 7
fi

echo "[public-install] OK"

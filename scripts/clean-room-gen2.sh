#!/usr/bin/env bash
#
# AUSUS — Clean-room Gen2 (Entity Engine) install test
# ----------------------------------------------------
# Reproduces the documented Gen2 Quick Start end to end in a throwaway temp
# directory, consuming the live Packagist registry ONLY — no path repos, no
# symlinks, no monorepo sources. The Gen2 line is a library set (there is no
# scaffold / `composer boot`), so the external contract is:
#
#   A. `composer require` the Gen2 packages from Packagist (no repositories[]).
#   B. Author one EntityDefinition, compile it (content-addressed .ausus).
#   C. Bind + invoke at runtime (create) — proves the full slice resolves.
#   D. Every required ausus/* dependency installs at EXPECTED_VERSION.
#
# Mirrors scripts/clean-room-install-test.sh (legacy starter) for the Gen2 line.
#
# Usage:   bash scripts/clean-room-gen2.sh
# Env:     EXPECTED_VERSION   target version of ausus/* (default v2.0.0)

set -euo pipefail

EXPECTED_VERSION="${EXPECTED_VERSION:-v2.0.0}"
VER="${EXPECTED_VERSION#v}"
CONSTRAINT="^${VER%%-*}"
TMP="$(mktemp -d -t ausus-clean-room-gen2-XXXXXX)"

cleanup() { rc=$?; rm -rf "$TMP" 2>/dev/null || true; [ "$rc" -ne 0 ] && echo "[clean-room-gen2] FAILED (exit=$rc)"; return "$rc"; }
trap cleanup EXIT

echo "[clean-room-gen2] tmp=$TMP  expected=$EXPECTED_VERSION  constraint=$CONSTRAINT"
cd "$TMP"

# ─── A. composer require from Packagist (no path repos) ─────────────────────
echo "[clean-room-gen2] A — composer require (Packagist only)"
composer init --name=acme/gen2-smoke --no-interaction --stability=stable >/dev/null
composer require --no-interaction --no-cache \
    "ausus/cli:$CONSTRAINT" "ausus/api-runtime:$CONSTRAINT" "ausus/persistence-memory:$CONSTRAINT"

# Gate: scaffold carries no repositories[] (pure registry resolution)
REPO_COUNT="$(jq '(.repositories // []) | length' composer.json)"
[ "$REPO_COUNT" = "0" ] || { echo "::error::composer.json has repositories[] — must resolve from Packagist only"; exit 1; }
echo "  ✓ no repositories[] (Packagist-only resolution)"

# ─── B. author + compile (content-addressed) ────────────────────────────────
echo "[clean-room-gen2] B — author + compile"
mkdir -p entities
cat > entities/Customer.php <<'PHP'
<?php
use Ausus\Authoring\Dsl\Definition;
use Ausus\Authoring\Dsl\Expr;
use Ausus\Definition\Enum\FieldType;
use Ausus\Definition\Enum\ActionKind;
return Definition::make('customer', true)
    ->field('name', FieldType::String)
    ->action('create', ActionKind::Create, ['inputs' => ['name'], 'guard' => Expr::eq(Expr::actor('type'), 'user')])
    ->projection('board', ['fields' => [['field' => 'name']]])
    ->build();
PHP

cat > smoke.php <<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
use Ausus\Cli\Command\CompileEntitiesCommand;
use Ausus\Cli\Repository\FileSchemaRepository;
use Ausus\Engine\Runtime\DefaultEntityEngine;
use Ausus\Engine\Runtime\DefaultAuthorizationEvaluator;
use Ausus\Persistence\Memory\MemoryDriver;
use Ausus\Api\Runtime\Http\RequestContextFactory;

$code = (new CompileEntitiesCommand())->run(__DIR__ . '/entities', __DIR__ . '/.ausus',
    fopen('php://memory', 'r+'), fopen('php://memory', 'r+'));
if ($code !== 0) { fwrite(STDERR, "compile failed\n"); exit(1); }
if (!glob(__DIR__ . '/.ausus/schemas/*.json')) { fwrite(STDERR, "no schema written\n"); exit(1); }

$repo   = new FileSchemaRepository(__DIR__ . '/.ausus');
$engine = new DefaultEntityEngine(new DefaultAuthorizationEvaluator(), $repo);
$ctx    = (new RequestContextFactory(new DateTimeImmutable()))
    ->fromHeaders(['X-Tenant-ID' => 'acme', 'X-Actor-Type' => 'user']);
$created = $engine->bind($repo->resolve('customer'), new MemoryDriver())
    ->invoke('create', ['name' => 'Globex'], $ctx);
if ($created->reference->identityHandle === '') { fwrite(STDERR, "runtime create failed\n"); exit(1); }
echo "compile+runtime OK\n";
PHP

php smoke.php
echo "  ✓ compile + runtime smoke green"

# ─── D. installed versions ──────────────────────────────────────────────────
echo "[clean-room-gen2] D — installed versions"
for pkg in ausus/kernel ausus/entity-engine ausus/authoring ausus/persistence-memory ausus/api-runtime ausus/cli; do
    INSTALLED="$(composer show "$pkg" --format=json 2>/dev/null | jq -r '.versions[0] // ""')"
    [ "${INSTALLED#v}" = "${EXPECTED_VERSION#v}" ] || { echo "::error::$pkg installed=$INSTALLED (expected $EXPECTED_VERSION)"; exit 1; }
    printf "  ✓ %-28s %s\n" "$pkg" "$INSTALLED"
done

echo "[clean-room-gen2] OK — Gen2 quickstart works end to end"

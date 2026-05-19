#!/usr/bin/env bash
#
# AUSUS — package integrity audit.
#
# For each published package:
#   1. Build the consumer-facing artifact (composer archive .tar / npm pack .tgz)
#   2. Extract to a clean /tmp dir
#   3. Inventory contents: file list + sizes + presence of LICENSE/README/CHANGELOG
#   4. Detect accidental internal-file leakage (.DS_Store, tests/, vendor/, .git*, *.bak, node_modules, *.log)
#   5. Verify autoload from the extracted artifact (PHP) / verify `exports` map (npm)
#   6. Cross-check source vs dist (renderer only): every src/*.tsx has a dist/*.js
#
# Output: scripts/audit-artifacts.txt (machine-checkable report)
#
# Usage:  bash scripts/audit-artifacts.sh
# Exits 0 if every check is green; non-zero on first finding.

set -uo pipefail
cd "$(dirname "$0")/.."

REGISTRY="$(mktemp -d -t ausus-audit-XXXXXX)"
EXTRACT="$(mktemp -d -t ausus-audit-extract-XXXXXX)"
REPORT="$(pwd)/scripts/audit-artifacts.txt"
trap 'rm -rf "$REGISTRY" "$EXTRACT"' EXIT

PACKAGES_PHP=(kernel persistence-sql runtime-default api-http tenancy-row audit-database auth-bridge presentation-default standard-stack starter)

ISSUES_FILE="$(mktemp -t ausus-audit-issues-XXXXXX)"
echo 0 > "$ISSUES_FILE"
note()  { printf "  %s\n" "$*"; }
fail()  { printf "  ✗ %s\n" "$*"; echo "$(($(cat "$ISSUES_FILE")+1))" > "$ISSUES_FILE"; }
ok()    { printf "  ✓ %s\n" "$*"; }

echo "AUSUS — package integrity audit"             | tee  "$REPORT"
echo "captured: $(date -u +%FT%TZ)"                | tee -a "$REPORT"
echo "PHP $(php -v | head -1 | awk '{print $2}')   composer $(composer --version | awk '{print $3}')"  | tee -a "$REPORT"
echo "node $(node --version)   npm $(npm --version)"  | tee -a "$REPORT"
echo "═══════════════════════════════════════════════════════════════════════════════════════════════════" | tee -a "$REPORT"

# ─── 1. Build artifacts ─────────────────────────────────────────────────────
echo "" | tee -a "$REPORT"
echo "── build artifacts ──"   | tee -a "$REPORT"
for p in "${PACKAGES_PHP[@]}"; do
    if (cd "packages/$p" && composer archive --dir "$REGISTRY" --format=tar --no-interaction > /dev/null 2>&1); then
        ok "composer archive ausus/$p" | tee -a "$REPORT"
    else
        fail "composer archive FAILED for ausus/$p" | tee -a "$REPORT"
    fi
done
if (cd renderer/react && npm run build > /dev/null 2>&1 && npm pack --pack-destination "$REGISTRY" > /dev/null 2>&1); then
    ok "npm pack @ausus/renderer-react" | tee -a "$REPORT"
else
    fail "npm pack @ausus/renderer-react FAILED" | tee -a "$REPORT"
fi

# ─── 2. Per-package inventory ───────────────────────────────────────────────
echo ""  | tee -a "$REPORT"
echo "── per-package inventory + leakage scan ──"   | tee -a "$REPORT"
for p in "${PACKAGES_PHP[@]}"; do
    artifact="$REGISTRY/ausus-$p-0.1.0.tar"
    target="$EXTRACT/$p"
    mkdir -p "$target"
    [[ -f "$artifact" ]] || { fail "no artifact $artifact"; continue; }
    tar -xf "$artifact" -C "$target"
    size=$(stat -f%z "$artifact" 2>/dev/null || stat -c%s "$artifact")
    files=$(find "$target" -type f | wc -l | tr -d ' ')
    echo "" | tee -a "$REPORT"
    echo "── ausus/$p  ($files files, $(numfmt --to=iec --suffix=B $size 2>/dev/null || echo "$size B"))" | tee -a "$REPORT"
    # Expected files
    [[ -f "$target/composer.json" ]] && ok "composer.json"            | tee -a "$REPORT" || fail "missing composer.json"      | tee -a "$REPORT"
    [[ -f "$target/LICENSE" ]]       && ok "LICENSE"                  | tee -a "$REPORT" || fail "missing LICENSE"            | tee -a "$REPORT"
    [[ -f "$target/README.md" ]]     && ok "README.md"                | tee -a "$REPORT" || fail "missing README.md"          | tee -a "$REPORT"
    if [[ "$p" != "standard-stack" ]]; then
        [[ -f "$target/CHANGELOG.md" ]] && ok "CHANGELOG.md"          | tee -a "$REPORT" || fail "missing CHANGELOG.md"       | tee -a "$REPORT"
    fi
    # Leakage scan — flag if any of these appear
    for pat in 'tests' 'vendor' 'node_modules' '\.DS_Store' '\.git' '\.idea' '\.vscode' '.bak$' '\.log$' '\.swp$' 'composer\.lock'; do
        found=$(find "$target" -type f -o -type d 2>/dev/null | grep -E "(/|^)$pat(/|$)" 2>/dev/null | head -3)
        if [[ -n "$found" ]]; then
            fail "leakage: ausus/$p contains $pat" | tee -a "$REPORT"
            echo "$found" | sed 's|^|      |' | tee -a "$REPORT"
        fi
    done
    # PHP package autoload check (skip skeletons + metapackage)
    case "$p" in
        kernel|persistence-sql|runtime-default|api-http|starter)
            if [[ -d "$target/src" ]]; then
                ok "src/ present" | tee -a "$REPORT"
            else
                fail "missing src/ for $p" | tee -a "$REPORT"
            fi
            ;;
        standard-stack)
            ok "metapackage (no src/ expected)" | tee -a "$REPORT"
            ;;
        tenancy-row|audit-database|auth-bridge|presentation-default)
            ok "skeleton (no src/ expected)" | tee -a "$REPORT"
            ;;
    esac
done

# ─── 3. npm renderer audit ──────────────────────────────────────────────────
echo "" | tee -a "$REPORT"
echo "── @ausus/renderer-react ──" | tee -a "$REPORT"
npm_tgz=$(ls "$REGISTRY"/ausus-renderer-react-*.tgz | head -1)
size=$(stat -f%z "$npm_tgz" 2>/dev/null || stat -c%s "$npm_tgz")
echo "  tarball: $(basename "$npm_tgz")  ($(numfmt --to=iec --suffix=B $size 2>/dev/null || echo "$size B"))" | tee -a "$REPORT"
target="$EXTRACT/renderer-react"; mkdir -p "$target"
tar -xf "$npm_tgz" -C "$target" --strip-components=1
files=$(find "$target" -type f | wc -l | tr -d ' '); echo "  files: $files" | tee -a "$REPORT"

# Required files
for f in package.json LICENSE README.md CHANGELOG.md dist/index.js dist/index.d.ts; do
    [[ -f "$target/$f" ]] && ok "$f" | tee -a "$REPORT" || fail "missing $f" | tee -a "$REPORT"
done

# Leakage scan
for pat in 'node_modules' '\.DS_Store' '\.git' '\.idea' '\.vscode' '\.bak$' '\.log$' 'tsconfig\.tsbuildinfo'; do
    found=$(find "$target" -type f 2>/dev/null | grep -E "$pat" 2>/dev/null | head -3)
    if [[ -n "$found" ]]; then
        fail "leakage: @ausus/renderer-react contains $pat" | tee -a "$REPORT"
        echo "$found" | sed 's|^|      |' | tee -a "$REPORT"
    fi
done

# exports map sanity
node -e "
const pkg = require('$target/package.json');
const tests = {
  'has exports field':                !!pkg.exports,
  'main exports . path':              pkg.exports && pkg.exports['.'] !== undefined,
  'main exports has types':           pkg.exports && pkg.exports['.'] && pkg.exports['.'].types,
  'main exports has import':          pkg.exports && pkg.exports['.'] && pkg.exports['.'].import,
  'subpath ./types declared':         pkg.exports && pkg.exports['./types'] !== undefined,
  'subpath ./package.json declared':  pkg.exports && pkg.exports['./package.json'] !== undefined,
  'type: module':                     pkg.type === 'module',
  'peerDependencies declared':        !!pkg.peerDependencies && !!pkg.peerDependencies.react,
  'engines.node declared':            !!(pkg.engines && pkg.engines.node),
  'sideEffects flag declared':        pkg.sideEffects !== undefined,
};
for (const [k, v] of Object.entries(tests)) {
  console.log('  ' + (v ? '✓' : '✗') + ' ' + k + (v ? '' : ' (FAIL)'));
}
" | tee -a "$REPORT"
# Check declarationMap / sourcemap presence
declMaps=$(find "$target/dist" -name '*.d.ts.map' 2>/dev/null | wc -l | tr -d ' ')
srcMaps=$(find "$target/dist" -name '*.js.map' 2>/dev/null | wc -l | tr -d ' ')
echo "  source maps:        $srcMaps" | tee -a "$REPORT"
echo "  declaration maps:   $declMaps" | tee -a "$REPORT"

# Source/dist parity
src_count=$(find "$target/src" -name '*.tsx' -o -name '*.ts' 2>/dev/null | wc -l | tr -d ' ')
dist_count=$(find "$target/dist" -name '*.js' 2>/dev/null | wc -l | tr -d ' ')
echo "  src .ts/.tsx files: $src_count" | tee -a "$REPORT"
echo "  dist .js files:     $dist_count" | tee -a "$REPORT"
[[ $src_count -eq $dist_count ]] && ok "src↔dist parity" | tee -a "$REPORT" || fail "src↔dist mismatch ($src_count vs $dist_count)" | tee -a "$REPORT"

# 'use client' directive presence (RSC-correctness)
for f in dist/context.js dist/hooks.js dist/components.js dist/ViewSchemaConsumer.js; do
    if [[ -f "$target/$f" ]]; then
        first=$(head -1 "$target/$f")
        if [[ "$first" == '"use client";' ]]; then
            ok "$f starts with \"use client\"" | tee -a "$REPORT"
        else
            fail "$f missing \"use client\" directive (got: $first)" | tee -a "$REPORT"
        fi
    fi
done

# ─── 4. Final ───────────────────────────────────────────────────────────────
echo "" | tee -a "$REPORT"
echo "═══════════════════════════════════════════════════════════════════════════════════════════════════" | tee -a "$REPORT"
final_issues="$(cat "$ISSUES_FILE")"
rm -f "$ISSUES_FILE"
if [[ "$final_issues" -eq 0 ]]; then
    echo "RESULT: 0 issues" | tee -a "$REPORT"
    exit 0
else
    echo "RESULT: $final_issues issues — see $REPORT" | tee -a "$REPORT"
    exit 1
fi

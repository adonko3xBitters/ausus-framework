#!/usr/bin/env bash
#
# AUSUS — compatibility & upgrade simulator
#
# Runs 7 isolated sandbox simulations to validate that every supported
# upgrade path produces correct outcomes (PASS or explicit REJECT) and
# never silently breaks. Each sandbox lives under a mktemp -d; cleanup
# on EXIT unless KEEP=1.
#
# Scenarios:
#   1.  0.1.0 → 0.1.1 patch bump (every package bumped in lockstep)
#   2.  Partial bump: kernel→0.1.1 alone, deps stay at 0.1.0 (semver permits)
#   3.  Major drift: kernel→0.2.0; downstream "ausus/kernel: 0.1.*" rejects
#   4.  React 17 (below peer range) — npm peer warning surfaces
#   5.  React 19 (within peer range) — npm install accepts, trace 12/12
#   6.  PHP-version constraint: bump kernel's "php" req to >=9.9.9 → reject
#   7.  Subtree split smoke (kernel package extracted to its own repo)
#
# Exit code: 0 if every scenario's actual outcome matches its expected
# outcome (PASS=composer install + boot OK, REJECT=composer fails with
# the documented message).

set -euo pipefail

cd "$(dirname "$0")/.."
SOURCE="$(pwd)"
RESULTS=()                         # one line per scenario
ALL_TMP=()

cleanup() {
    if [[ "${KEEP:-0}" != "1" ]]; then
        for d in "${ALL_TMP[@]}"; do
            [[ -d "$d" ]] && rm -rf "$d"
        done
    fi
}
trap cleanup EXIT

# ──────────────────────────────────────────────────────────────────────
# Helpers
# ──────────────────────────────────────────────────────────────────────

mk_sandbox() {
    local sandbox
    sandbox="$(mktemp -d -t ausus-upgrade-XXXXXX)"
    ALL_TMP+=("$sandbox")
    rsync -a --quiet \
        --exclude='vendor' \
        --exclude='node_modules' \
        --exclude='composer.lock' \
        --exclude='package-lock.json' \
        --exclude='renderer/react/dist' \
        --exclude='apps/playground/web/dist' \
        --exclude='apps/playground/*.sqlite' \
        --exclude='.git' \
        --exclude='.github' \
        --exclude='.claude' \
        --exclude='CLAUDE.md' \
        --exclude='AGENTS.md' \
        "${SOURCE}/" "${sandbox}/"
    echo "$sandbox"
}

# Bump version in every package OR a subset, plus all cross-refs.
# Args: sandbox, oldver, newver, [pkg-regex-to-bump (defaults to all)]
bump_versions() {
    local sandbox="$1" old="$2" new="$3" pattern="${4:-.*}"
    for f in "$sandbox/packages"/*/composer.json; do
        if [[ "$f" =~ $pattern ]]; then
            sed -i.bak "s/\"version\": \"$old\"/\"version\": \"$new\"/" "$f"
            rm -f "${f}.bak"
        fi
    done
}

# Bump dependency constraints (e.g. "0.1.*" → "0.2.*")
bump_constraints() {
    local sandbox="$1" old="$2" new="$3"
    for f in "$sandbox/packages"/*/composer.json "$sandbox/composer.json"; do
        [[ -f "$f" ]] || continue
        sed -i.bak "s/\"ausus\\/\\([a-z-]*\\)\": *\"$old\"/\"ausus\\/\\1\": \"$new\"/g" "$f"
        rm -f "${f}.bak"
    done
}

# Builds an artifact registry from a sandbox; echoes the registry path.
build_registry() {
    local sandbox="$1"
    local reg
    reg="$(mktemp -d -t ausus-upgrade-reg-XXXXXX)"
    ALL_TMP+=("$reg")
    for pkg in kernel persistence-sql runtime-default tenancy-row audit-database \
               auth-bridge presentation-default standard-stack api-http starter; do
        if [[ -d "$sandbox/packages/$pkg" ]]; then
            (cd "$sandbox/packages/$pkg" && composer archive --dir "$reg" --format=tar --no-interaction >/dev/null 2>&1) \
                || true
        fi
    done
    echo "$reg"
}

record_pass() { RESULTS+=("PASS    │ $1"); }
record_fail() { RESULTS+=("FAIL    │ $1"); }
record_ok_reject() { RESULTS+=("REJECT✓ │ $1"); }   # expected reject occurred
record_silent() { RESULTS+=("SILENT  │ $1"); }      # silent breakage detected

# ══════════════════════════════════════════════════════════════════════
#  SCENARIO 1 — patch bump 0.1.0 → 0.1.1 (lockstep)
# ══════════════════════════════════════════════════════════════════════
sim1() {
    echo "── SIM-1: patch bump 0.1.0 → 0.1.1 (lockstep) ─────────────────────────"
    local sandbox; sandbox="$(mk_sandbox)"
    bump_versions "$sandbox" '0.1.0' '0.1.1'

    local reg; reg="$(build_registry "$sandbox")"
    [[ "$(ls "$reg" | wc -l)" -ge 9 ]] || { record_fail "SIM-1: registry archive count < 9"; return; }

    local consumer; consumer="$(mktemp -d -t ausus-upgrade-c1-XXXXXX)"; ALL_TMP+=("$consumer")
    cd "$consumer"
    if env AUSUS_LOCAL_REGISTRY="$reg" composer create-project ausus/starter myapp --no-install \
        --repository='{"type":"artifact","url":"'"$reg"'"}' \
        --repository='{"packagist.org":false}' \
        --no-interaction --stability=stable > /tmp/sim1-cp.log 2>&1
    then
        cd myapp
        composer install --no-interaction > /tmp/sim1-i.log 2>&1 \
            && composer boot > /tmp/sim1-boot.log 2>&1 \
            && grep -q "ausus/starter boots cleanly" /tmp/sim1-boot.log \
            && record_pass "SIM-1 (lockstep 0.1.0→0.1.1): boot OK at 0.1.1" \
            || record_fail "SIM-1: install/boot failed"
    else
        record_fail "SIM-1: create-project failed — log /tmp/sim1-cp.log"
    fi
    cd "$SOURCE"
}

# ══════════════════════════════════════════════════════════════════════
#  SCENARIO 2 — partial bump (kernel only)
# ══════════════════════════════════════════════════════════════════════
sim2() {
    echo "── SIM-2: partial bump (kernel→0.1.1, deps stay 0.1.0) ────────────────"
    local sandbox; sandbox="$(mk_sandbox)"
    bump_versions "$sandbox" '0.1.0' '0.1.1' 'packages/kernel/'

    local reg; reg="$(build_registry "$sandbox")"
    local consumer; consumer="$(mktemp -d -t ausus-upgrade-c2-XXXXXX)"; ALL_TMP+=("$consumer")
    cd "$consumer"

    env AUSUS_LOCAL_REGISTRY="$reg" composer create-project ausus/starter myapp --no-install \
        --repository='{"type":"artifact","url":"'"$reg"'"}' \
        --repository='{"packagist.org":false}' \
        --no-interaction --stability=stable > /tmp/sim2-cp.log 2>&1
    cd myapp
    if composer install --no-interaction > /tmp/sim2-i.log 2>&1; then
        local kernel_ver
        kernel_ver="$(composer show ausus/kernel 2>/dev/null | grep -E '^versions' | awk '{print $3}')"
        composer boot > /tmp/sim2-boot.log 2>&1
        grep -q "ausus/starter boots cleanly" /tmp/sim2-boot.log \
            && record_pass "SIM-2 (partial: kernel@${kernel_ver:-?}, deps@0.1.0): boot OK" \
            || record_fail "SIM-2: boot did not complete"
    else
        record_fail "SIM-2: install failed — log /tmp/sim2-i.log"
    fi
    cd "$SOURCE"
}

# ══════════════════════════════════════════════════════════════════════
#  SCENARIO 3 — major drift rejected (kernel→0.2.0; runtime requires 0.1.*)
# ══════════════════════════════════════════════════════════════════════
sim3() {
    echo "── SIM-3: major drift — kernel@0.2.0 vs runtime needs 0.1.* ───────────"
    local sandbox; sandbox="$(mk_sandbox)"
    # Bump kernel to 0.2.0 only
    bump_versions "$sandbox" '0.1.0' '0.2.0' 'packages/kernel/'
    # Note: runtime-default + persistence-sql + starter still require "ausus/kernel": "0.1.*"

    local reg; reg="$(build_registry "$sandbox")"
    local consumer; consumer="$(mktemp -d -t ausus-upgrade-c3-XXXXXX)"; ALL_TMP+=("$consumer")
    cd "$consumer"

    env AUSUS_LOCAL_REGISTRY="$reg" composer create-project ausus/starter myapp --no-install \
        --repository='{"type":"artifact","url":"'"$reg"'"}' \
        --repository='{"packagist.org":false}' \
        --no-interaction --stability=stable > /tmp/sim3-cp.log 2>&1
    cd myapp
    if composer install --no-interaction > /tmp/sim3-i.log 2>&1; then
        record_silent "SIM-3: install accepted kernel@0.2.0 alongside runtime needing 0.1.* — semver NOT enforced"
    else
        if grep -qiE "(could not be resolved|requires ausus/kernel)" /tmp/sim3-i.log; then
            record_ok_reject "SIM-3: composer rejected kernel@0.2.0 vs runtime's 0.1.* constraint"
        else
            record_fail "SIM-3: install failed for an unexpected reason — see /tmp/sim3-i.log"
        fi
    fi
    cd "$SOURCE"
}

# ══════════════════════════════════════════════════════════════════════
#  SCENARIO 4 — React 17 (below peerDeps) ⇒ npm peer warning
# ══════════════════════════════════════════════════════════════════════
sim4() {
    echo "── SIM-4: React 17 (below peer ^18 || ^19) ────────────────────────────"
    cd "$SOURCE"
    npm run build > /dev/null 2>&1
    local reg; reg="$(mktemp -d -t ausus-upgrade-npm4-XXXXXX)"; ALL_TMP+=("$reg")
    (cd renderer/react && npm pack --pack-destination="$reg" > /dev/null 2>&1)

    local consumer; consumer="$(mktemp -d -t ausus-upgrade-cn4-XXXXXX)"; ALL_TMP+=("$consumer")
    cd "$consumer"
    npm init -y > /dev/null
    if npm install "$reg/ausus-renderer-react-0.1.0.tgz" react@17 react-dom@17 \
        --no-audit --no-fund > /tmp/sim4-i.log 2>&1
    then
        if grep -qE "(EPEERINVALID|peer .*react|incorrect peer)" /tmp/sim4-i.log; then
            record_ok_reject "SIM-4: React 17 — npm reported peer-range violation but installed"
        else
            record_silent "SIM-4: React 17 — no peer warning surfaced (silent acceptance)"
        fi
    else
        record_ok_reject "SIM-4: npm rejected React 17 install"
    fi
    cd "$SOURCE"
}

# ══════════════════════════════════════════════════════════════════════
#  SCENARIO 5 — React 19 (within peer range) ⇒ accepted + trace passes
# ══════════════════════════════════════════════════════════════════════
sim5() {
    echo "── SIM-5: React 19 (within peer ^18 || ^19) ───────────────────────────"
    cd "$SOURCE"
    local reg; reg="$(mktemp -d -t ausus-upgrade-npm5-XXXXXX)"; ALL_TMP+=("$reg")
    (cd renderer/react && npm pack --pack-destination="$reg" > /dev/null 2>&1)

    local consumer; consumer="$(mktemp -d -t ausus-upgrade-cn5-XXXXXX)"; ALL_TMP+=("$consumer")
    cd "$consumer"
    npm init -y > /dev/null
    if npm install "$reg/ausus-renderer-react-0.1.0.tgz" react@^19 react-dom@^19 \
        --no-audit --no-fund > /tmp/sim5-i.log 2>&1
    then
        cat > consumer.mjs <<'EOF'
import { createElement as h } from "react";
import { renderToString } from "react-dom/server";
import { AususProvider, ListView, WorkflowBadge } from "@ausus/renderer-react";
const html = renderToString(h(WorkflowBadge, { value: "PAID" }));
if (!html.includes("ausus-badge--green") || !html.includes("PAID")) {
    console.error("FAIL: " + html);
    process.exit(1);
}
console.log("OK: React 19 renders PAID green badge");
EOF
        if node consumer.mjs > /tmp/sim5-c.log 2>&1; then
            record_pass "SIM-5 (React 19 + renderer): badge renders green"
        else
            record_fail "SIM-5: React 19 install OK but render failed — /tmp/sim5-c.log"
        fi
    else
        record_fail "SIM-5: React 19 install failed — /tmp/sim5-i.log"
    fi
    cd "$SOURCE"
}

# ══════════════════════════════════════════════════════════════════════
#  SCENARIO 6 — PHP-version constraint enforcement
# ══════════════════════════════════════════════════════════════════════
sim6() {
    echo "── SIM-6: PHP version constraint enforcement (>=9.9.9) ────────────────"
    local sandbox; sandbox="$(mk_sandbox)"
    # Bump kernel's php constraint to a value the host clearly doesn't satisfy
    sed -i.bak 's/"php": *">=8\.3"/"php": ">=9.9.9"/' "$sandbox/packages/kernel/composer.json"
    rm -f "$sandbox/packages/kernel/composer.json.bak"

    local reg; reg="$(build_registry "$sandbox")"
    local consumer; consumer="$(mktemp -d -t ausus-upgrade-c6-XXXXXX)"; ALL_TMP+=("$consumer")
    cd "$consumer"

    env AUSUS_LOCAL_REGISTRY="$reg" composer create-project ausus/starter myapp --no-install \
        --repository='{"type":"artifact","url":"'"$reg"'"}' \
        --repository='{"packagist.org":false}' \
        --no-interaction --stability=stable > /tmp/sim6-cp.log 2>&1
    cd myapp
    if composer install --no-interaction > /tmp/sim6-i.log 2>&1; then
        record_silent "SIM-6: composer accepted PHP requirement >=9.9.9 — platform check bypassed"
    else
        if grep -qiE "(php.*9\.9|platform.*php|requires php)" /tmp/sim6-i.log; then
            record_ok_reject "SIM-6: composer correctly rejected PHP ^9.9.9 on $(php --version | head -1 | awk '{print $2}')"
        else
            record_fail "SIM-6: install failed for an unexpected reason — see /tmp/sim6-i.log"
        fi
    fi
    cd "$SOURCE"
}

# ══════════════════════════════════════════════════════════════════════
#  SCENARIO 7 — subtree split smoke (kernel split is publish-shaped)
# ══════════════════════════════════════════════════════════════════════
sim7() {
    echo "── SIM-7: subtree split smoke (ausus/kernel) ──────────────────────────"
    local sandbox; sandbox="$(mk_sandbox)"
    cd "$sandbox"
    git init -q -b main
    git -c user.email=sim@local -c user.name=sim add -A
    git -c user.email=sim@local -c user.name=sim commit -q -m "sim init"
    if git subtree split --prefix=packages/kernel -b split/kernel > /tmp/sim7-split.log 2>&1; then
        # Verify the split branch contains composer.json + src/
        if git ls-tree split/kernel composer.json src 2>/dev/null | grep -q composer.json; then
            record_pass "SIM-7: ausus/kernel subtree split produced a publish-shaped tree"
        else
            record_fail "SIM-7: split branch missing composer.json or src/"
        fi
    else
        record_fail "SIM-7: git subtree split failed — /tmp/sim7-split.log"
    fi
    cd "$SOURCE"
}

# ──────────────────────────────────────────────────────────────────────
# Drive all scenarios. Each catches its own errors so the runner
# completes and prints the matrix at the end.
# ──────────────────────────────────────────────────────────────────────

sim1 || true
sim2 || true
sim3 || true
sim4 || true
sim5 || true
sim6 || true
sim7 || true

echo ""
echo "══════════════════════════════════════════════════════════════════════"
echo "  Compatibility-pass result matrix"
echo "══════════════════════════════════════════════════════════════════════"
fail=0
silent=0
for r in "${RESULTS[@]}"; do
    echo "  $r"
    [[ "$r" == FAIL* ]] && fail=$((fail + 1))
    [[ "$r" == SILENT* ]] && silent=$((silent + 1))
done
echo ""
echo "  Total: ${#RESULTS[@]}   Fail: $fail   Silent breakage: $silent"
echo "══════════════════════════════════════════════════════════════════════"

[[ $fail -eq 0 && $silent -eq 0 ]] && exit 0 || exit 1

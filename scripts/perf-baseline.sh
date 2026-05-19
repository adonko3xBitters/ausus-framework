#!/usr/bin/env bash
#
# AUSUS — perf baseline orchestrator.
#
# Runs the parts of the perf baseline that need real network and/or
# file-system isolation:
#
#   A. HTTP roundtrip (cold + warm) against a live `php -S` server
#   B. Package install time (composer + npm), cold and warm
#
# The PHP & JS bench scripts (apps/playground/perf-baseline.php and
# apps/playground/web/perf-baseline.tsx) are invoked separately to keep
# each measurement focused.
#
# Run:  bash scripts/perf-baseline.sh

set -euo pipefail
cd "$(dirname "$0")/.."

PORT="${PORT:-8788}"
HOST="${HOST:-127.0.0.1}"
DB="/tmp/ausus-perf-server.sqlite"
LOG="/tmp/ausus-perf-server.log"
PID_FILE="/tmp/ausus-perf-server.pid"

cleanup() {
    if [[ -f "$PID_FILE" ]]; then
        local pid
        pid="$(cat "$PID_FILE")"
        kill "$pid" 2>/dev/null || true
        rm -f "$PID_FILE"
    fi
    rm -f "$DB" "$LOG"
}
trap cleanup EXIT

echo "AUSUS perf baseline — orchestrator"
echo "════════════════════════════════════════════════════════════════════════════════"

# ─────────────────────────────────────────────────────────────────────────────
# A. HTTP roundtrip
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "── A. HTTP roundtrip (live php -S + curl) ──────────────────────────────"

rm -f "$DB"
AUSUS_DB_PATH="$DB" nohup php -S "$HOST:$PORT" apps/playground/server.php > "$LOG" 2>&1 &
echo "$!" > "$PID_FILE"

# Wait for /api/_health
for i in 1 2 3 4 5 6 7 8 9 10; do
    if curl -fsS "http://$HOST:$PORT/api/_health" >/dev/null 2>&1; then
        break
    fi
    sleep 0.1
done

# Cold-cache GET projection (first request after server start)
T0="$(date +%s%N)"
curl -fsS -H "X-Tenant-ID: acme" "http://$HOST:$PORT/api/projections/billing.invoice.summary?locale=en-US&renderer=react.web.v1&acceptSchemaVersions=1.0.0" > /dev/null
T1="$(date +%s%N)"
echo "  cold GET /projections/billing.invoice.summary       $(( (T1-T0)/1000 )) µs"

# 200 warm health probes — capture per-request latency
samples=()
for i in $(seq 1 200); do
    T0="$(date +%s%N)"
    curl -fsS "http://$HOST:$PORT/api/_health" > /dev/null
    T1="$(date +%s%N)"
    samples+=( "$(( (T1-T0)/1000 ))" )
done
# Sort + p50/p95
sorted=( $(printf "%s\n" "${samples[@]}" | sort -n) )
n=${#sorted[@]}
p50_idx=$(( n/2 ))
p95_idx=$(( n*95/100 ))
echo "  200× GET /api/_health (warm)                        p50 ${sorted[$p50_idx]} µs   p95 ${sorted[$p95_idx]} µs   min ${sorted[0]} µs   max ${sorted[$((n-1))]} µs"

# 50 warm POST issue cycles. Each iter targets a different invoice from the seed
# pool. We tolerate 4xx/5xx (set -e off for the inner block) because some
# subjects might already be in a terminal state on re-runs against a persisted DB.
samples=()
set +e
for i in $(seq 1 50); do
    SUBJECT=$(curl -fsS -H "X-Tenant-ID: acme" "http://$HOST:$PORT/api/projections/billing.invoice.summary" \
                | grep -oE '"id":"[^"]+"' | sed -n "${i}p" | cut -d'"' -f4)
    [[ -z "$SUBJECT" ]] && break
    T0="$(date +%s%N)"
    curl -fsS -o /dev/null \
         -X POST -H "X-Tenant-ID: acme" -H "Content-Type: application/json" \
         -d "{\"subject\":{\"tenantId\":\"acme\",\"entityFqn\":\"billing.invoice\",\"identityHandle\":\"$SUBJECT\"},\"inputs\":{}}" \
         "http://$HOST:$PORT/api/actions/billing.invoice.issue" 2>/dev/null
    rc=$?
    T1="$(date +%s%N)"
    [[ $rc -eq 0 ]] && samples+=( "$(( (T1-T0)/1000 ))" )
done
set -e
if [[ ${#samples[@]} -gt 0 ]]; then
    sorted=( $(printf "%s\n" "${samples[@]}" | sort -n) )
    n=${#sorted[@]}
    echo "  ${n}× POST /api/actions/billing.invoice.issue (warm)   p50 ${sorted[$((n/2))]} µs   p95 ${sorted[$((n*95/100))]} µs   min ${sorted[0]} µs   max ${sorted[$((n-1))]} µs"
fi

# ─────────────────────────────────────────────────────────────────────────────
# B. Package install time (warm — i.e. with composer/npm cache present)
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "── B. Package install time (warm cache) ────────────────────────────────"

SANDBOX="$(mktemp -d -t ausus-perf-install-XXXXXX)"
rsync -a --quiet \
    --exclude='vendor' --exclude='node_modules' \
    --exclude='composer.lock' --exclude='package-lock.json' \
    --exclude='renderer/react/dist' --exclude='apps/playground/web/dist' \
    --exclude='.git' --exclude='.github' --exclude='.claude' \
    --exclude='CLAUDE.md' --exclude='AGENTS.md' --exclude='*.sqlite' \
    "$(pwd)/" "$SANDBOX/"

cd "$SANDBOX"

# composer install (warm — cache is populated from prior runs)
T0="$(date +%s%N)"
composer install --no-interaction --prefer-dist > /dev/null 2>&1
T1="$(date +%s%N)"
echo "  composer install (warm cache)                       $(( (T1-T0)/1000000 )) ms"

# composer install (cold cache simulation — clear vendor + lock)
rm -rf vendor composer.lock
T0="$(date +%s%N)"
composer install --no-interaction --prefer-dist > /dev/null 2>&1
T1="$(date +%s%N)"
echo "  composer install (lockless: re-resolve + install)   $(( (T1-T0)/1000000 )) ms"

# npm install (warm — node_modules might be missing in sandbox)
T0="$(date +%s%N)"
npm install --no-audit --no-fund > /dev/null 2>&1
T1="$(date +%s%N)"
echo "  npm install (with npm cache populated)              $(( (T1-T0)/1000000 )) ms"

# npm run build
T0="$(date +%s%N)"
npm run build > /dev/null 2>&1
T1="$(date +%s%N)"
echo "  npm run build (tsc dist/)                           $(( (T1-T0)/1000000 )) ms"

cd - > /dev/null
rm -rf "$SANDBOX"

# ─────────────────────────────────────────────────────────────────────────────
# C. Git subtree split time (one package as proxy)
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "── C. Git subtree split (ausus/kernel) ─────────────────────────────────"
SPLIT_SANDBOX="$(mktemp -d -t ausus-perf-split-XXXXXX)"
rsync -a --quiet --exclude='vendor' --exclude='node_modules' --exclude='.git' \
      --exclude='*.sqlite' --exclude='renderer/react/dist' --exclude='apps/playground/web/dist' \
      --exclude='.claude' --exclude='CLAUDE.md' --exclude='AGENTS.md' \
      "$(pwd)/" "$SPLIT_SANDBOX/"
cd "$SPLIT_SANDBOX"
git init -q -b main
git -c user.email=perf@local -c user.name=perf add -A
git -c user.email=perf@local -c user.name=perf commit -q -m "perf seed"
T0="$(date +%s%N)"
git subtree split --prefix=packages/kernel -b split/kernel > /dev/null
T1="$(date +%s%N)"
echo "  git subtree split --prefix=packages/kernel          $(( (T1-T0)/1000000 )) ms"

cd - > /dev/null
rm -rf "$SPLIT_SANDBOX"

echo ""
echo "════════════════════════════════════════════════════════════════════════════════"
echo "  Orchestrator done. See apps/playground/perf-baseline.php for PHP numbers,"
echo "  apps/playground/web/perf-baseline.tsx for JS/SSR numbers."
echo "════════════════════════════════════════════════════════════════════════════════"

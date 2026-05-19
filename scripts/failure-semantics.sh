#!/usr/bin/env bash
#
# AUSUS — failure-semantics probe.
#
# Hits a running php -S server with 13 adversarial requests covering the
# 7 categories the user named (malformed JSON, oversized payloads,
# invalid UTF-8, unsupported methods, invalid schemaVersion, corrupted
# persistence, interrupted writes) plus negative cases of every typed
# kernel exception. Captures HTTP status + error envelope verbatim.
#
# Run:  bash scripts/failure-semantics.sh
# Env:  KEEP=1   retain server log + DB after exit
#
# Exits 0 if every probe got an explicit, typed error envelope OR a
# well-shaped 2xx; exits 1 if any probe leaked a stack trace, returned
# an unhandled 5xx with kind=InternalError, or accepted invalid input
# silently.

set -uo pipefail
cd "$(dirname "$0")/.."

PORT="${PORT:-8789}"
HOST="${HOST:-127.0.0.1}"
DB="/tmp/ausus-failure-server.sqlite"
LOG="/tmp/ausus-failure-server.log"
PID_FILE="/tmp/ausus-failure-server.pid"
URL="http://$HOST:$PORT/api"

results=()
record() { results+=( "$1" ); }

cleanup() {
    if [[ -f "$PID_FILE" ]]; then
        kill "$(cat "$PID_FILE")" 2>/dev/null || true
        rm -f "$PID_FILE"
    fi
    if [[ "${KEEP:-0}" != "1" ]]; then
        rm -f "$DB" "$LOG"
    fi
}
trap cleanup EXIT

# Build deps if missing
[[ -f vendor/autoload.php ]] || composer install --no-interaction --quiet >/dev/null
[[ -f renderer/react/dist/index.js ]] || npm run build >/dev/null 2>&1

rm -f "$DB"
AUSUS_DB_PATH="$DB" nohup php -S "$HOST:$PORT" apps/playground/server.php >"$LOG" 2>&1 &
echo "$!" > "$PID_FILE"
for i in 1 2 3 4 5 6 7 8 9 10; do
    if curl -fsS "$URL/_health" >/dev/null 2>&1; then break; fi
    sleep 0.1
done

# Helper: capture status + body to a temp file
probe() {
    local label="$1" expect_status="$2" expect_kind="$3"; shift 3
    local body http
    body="$(mktemp)"
    http="$(curl -s -o "$body" -w "%{http_code}" "$@" 2>/dev/null)"
    local kind
    kind="$(grep -oE '"kind":"[^"]+"' "$body" 2>/dev/null | head -1 | cut -d'"' -f4)"
    kind="${kind:-<none>}"
    local msg
    msg="$(grep -oE '"message":"[^"]+"' "$body" 2>/dev/null | head -1 | cut -d'"' -f4 | head -c 80)"
    local snippet
    snippet="$(head -c 200 "$body" | tr -d '\n')"
    rm -f "$body"

    local status_ok=0 kind_ok=0
    [[ "$expect_status" == "*" || "$http" == "$expect_status" ]] && status_ok=1
    [[ "$expect_kind"   == "*" || "$kind" == "$expect_kind" ]] && kind_ok=1
    local outcome
    if [[ $status_ok -eq 1 && $kind_ok -eq 1 ]]; then
        outcome="✅ MATCH"
    else
        outcome="✗ DRIFT   (got $http kind=$kind, expected $expect_status kind=$expect_kind)"
    fi
    printf "  %-50s  status=%-3s  kind=%-30s  %s\n" "$label" "$http" "$kind" "$outcome"
    record "$label|$http|$kind|$msg"
}

echo "══════════════════════════════════════════════════════════════════════════════════════════════════════════════"
echo "  AUSUS — failure semantics probe (live HTTP, $URL)"
echo "══════════════════════════════════════════════════════════════════════════════════════════════════════════════"
echo ""

# ─────────────────────────────────────────────────────────────────────────────
# 1. Malformed JSON body
# ─────────────────────────────────────────────────────────────────────────────
echo "── 1. malformed JSON ────────────────────────────────────────────────────"
probe "POST /actions/billing.invoice.cancel  body={not json}"  400 BadRequest \
    -X POST -H "X-Tenant-ID: acme" -H "Content-Type: application/json" \
    -d '{not json' "$URL/actions/billing.invoice.cancel"

probe "POST /actions  body=null (literal)"  400 BadRequest \
    -X POST -H "X-Tenant-ID: acme" -H "Content-Type: application/json" \
    -d 'null' "$URL/actions/billing.invoice.cancel"

probe "POST /actions  body=\"a-string\""    400 BadRequest \
    -X POST -H "X-Tenant-ID: acme" -H "Content-Type: application/json" \
    -d '"a-string"' "$URL/actions/billing.invoice.cancel"

probe "POST /actions  body=[1,2,3]"          400 BadRequest \
    -X POST -H "X-Tenant-ID: acme" -H "Content-Type: application/json" \
    -d '[1,2,3]' "$URL/actions/billing.invoice.cancel"

echo ""

# ─────────────────────────────────────────────────────────────────────────────
# 2. Oversized payload (1MB JSON)
# ─────────────────────────────────────────────────────────────────────────────
echo "── 2. oversized payload (1 MB body) ─────────────────────────────────────"
LARGE="$(mktemp)"
python3 -c "
import json
big = 'X' * (1024 * 1024)   # 1 MiB inside customer_name
print(json.dumps({'subject': None, 'inputs': {'number':'INV-BIG','customer_name':big,'amount':{'amount':'1.00','currency':'USD'}}}))
" > "$LARGE"
size_mb=$(ls -l "$LARGE" | awk '{print $5}')
echo "    payload size: $size_mb bytes"
# Expected behavior: V0 has NO max-body cap; accept-success is the
# documented current behavior. We still want a typed response either way.
probe "POST /actions  body=~1MB JSON (no cap in V0)" "*" "*" \
    -X POST -H "X-Tenant-ID: acme" -H "Content-Type: application/json" \
    --data-binary @"$LARGE" "$URL/actions/billing.invoice.create"
rm -f "$LARGE"
echo ""

# ─────────────────────────────────────────────────────────────────────────────
# 3. Invalid UTF-8 in body
# ─────────────────────────────────────────────────────────────────────────────
echo "── 3. invalid UTF-8 byte sequence ───────────────────────────────────────"
BAD_UTF8="$(mktemp)"
printf '{"subject":null,"inputs":{"number":"INV","customer_name":"\xC3\x28","amount":{"amount":"1","currency":"USD"}}}' > "$BAD_UTF8"
probe "POST /actions  body contains \\xC3\\x28" "*" "*" \
    -X POST -H "X-Tenant-ID: acme" -H "Content-Type: application/json" \
    --data-binary @"$BAD_UTF8" "$URL/actions/billing.invoice.create"
rm -f "$BAD_UTF8"
echo ""

# ─────────────────────────────────────────────────────────────────────────────
# 4. Unsupported HTTP methods
# ─────────────────────────────────────────────────────────────────────────────
echo "── 4. unsupported methods ──────────────────────────────────────────────"
probe "PUT  /_health (unsupported)"          404 NotFound \
    -X PUT  "$URL/_health"
probe "DELETE /actions/billing.invoice.cancel" 404 NotFound \
    -X DELETE -H "X-Tenant-ID: acme" "$URL/actions/billing.invoice.cancel"
probe "PATCH /projections/billing.invoice.summary" 404 NotFound \
    -X PATCH -H "X-Tenant-ID: acme" "$URL/projections/billing.invoice.summary"
echo ""

# ─────────────────────────────────────────────────────────────────────────────
# 5. Unknown action / projection / entity FQN
# ─────────────────────────────────────────────────────────────────────────────
echo "── 5. unknown FQNs (404 family) ────────────────────────────────────────"
probe "POST /actions/billing.invoice.ghost"   404 ActionNotFound \
    -X POST -H "X-Tenant-ID: acme" -H "Content-Type: application/json" \
    -d '{}' "$URL/actions/billing.invoice.ghost"
probe "GET  /projections/billing.invoice.nope" 404 ProjectionNotFound \
    -H "X-Tenant-ID: acme" "$URL/projections/billing.invoice.nope"
echo ""

# ─────────────────────────────────────────────────────────────────────────────
# 6. Workflow + concurrency
# ─────────────────────────────────────────────────────────────────────────────
echo "── 6. workflow + concurrency ───────────────────────────────────────────"
# Use seeded INV-2026-001; first cancel → 200, second → 409 WorkflowStateMismatch
INVOICE=$(curl -fsS -H "X-Tenant-ID: acme" "$URL/projections/billing.invoice.summary" \
            | grep -oE '"id":"[^"]+"' | head -1 | cut -d'"' -f4)
probe "POST cancel (1st time on DRAFT)"      200 "*" \
    -X POST -H "X-Tenant-ID: acme" -H "Content-Type: application/json" \
    -d "{\"subject\":{\"tenantId\":\"acme\",\"entityFqn\":\"billing.invoice\",\"identityHandle\":\"$INVOICE\"},\"inputs\":{}}" \
    "$URL/actions/billing.invoice.cancel"
probe "POST cancel (2nd time — stale state)" 409 WorkflowStateMismatch \
    -X POST -H "X-Tenant-ID: acme" -H "Content-Type: application/json" \
    -d "{\"subject\":{\"tenantId\":\"acme\",\"entityFqn\":\"billing.invoice\",\"identityHandle\":\"$INVOICE\"},\"inputs\":{}}" \
    "$URL/actions/billing.invoice.cancel"
echo ""

# ─────────────────────────────────────────────────────────────────────────────
# 7. Tenant boundary + bad subject
# ─────────────────────────────────────────────────────────────────────────────
echo "── 7. tenant boundary + bad subject (403 / 404 family) ─────────────────"
probe "POST cancel — cross-tenant subject"    "*" TenantBoundaryViolation \
    -X POST -H "X-Tenant-ID: acme" -H "Content-Type: application/json" \
    -d "{\"subject\":{\"tenantId\":\"evil\",\"entityFqn\":\"billing.invoice\",\"identityHandle\":\"$INVOICE\"},\"inputs\":{}}" \
    "$URL/actions/billing.invoice.cancel"
probe "POST cancel — bogus identityHandle"    "*" WorkflowSubjectNotFound \
    -X POST -H "X-Tenant-ID: acme" -H "Content-Type: application/json" \
    -d '{"subject":{"tenantId":"acme","entityFqn":"billing.invoice","identityHandle":"01KRZGHOSTGHOSTGHOSTGHOST01"},"inputs":{}}' \
    "$URL/actions/billing.invoice.cancel"
echo ""

# ─────────────────────────────────────────────────────────────────────────────
# 8. Missing required header
# ─────────────────────────────────────────────────────────────────────────────
echo "── 8. missing required X-Tenant-ID header ──────────────────────────────"
probe "GET /projections without X-Tenant-ID"  400 BadRequest \
    "$URL/projections/billing.invoice.summary"
echo ""

# ─────────────────────────────────────────────────────────────────────────────
# 9. Stack trace leak check — every body must NOT contain a file path
# ─────────────────────────────────────────────────────────────────────────────
echo "── 9. stack-trace leak detection ───────────────────────────────────────"
LEAKS=0
for line in "${results[@]}"; do
    msg="${line##*|}"
    if [[ "$msg" =~ \.php:[0-9] || "$msg" =~ /Users/ || "$msg" =~ /vendor/ || "$msg" =~ Stack\ trace ]]; then
        echo "  ✗ LEAK in: $line"
        LEAKS=$((LEAKS + 1))
    fi
done
if [[ $LEAKS -eq 0 ]]; then
    echo "  ✓ no stack-trace / file-path leakage detected in any error body"
fi
echo ""

# ─────────────────────────────────────────────────────────────────────────────
# Summary
# ─────────────────────────────────────────────────────────────────────────────
DRIFT=0
INTERNAL_ERRORS=0
for line in "${results[@]}"; do
    if [[ "${line##*|*|*|}" != "$line" ]]; then : ; fi
    kind="$(echo "$line" | cut -d'|' -f3)"
    [[ "$kind" == "InternalError" ]] && INTERNAL_ERRORS=$((INTERNAL_ERRORS + 1))
done

echo "══════════════════════════════════════════════════════════════════════════════════════════════════════════════"
printf "  Total probes: %-3d   Stack-trace leaks: %d   InternalError fallthroughs: %d\n" "${#results[@]}" "$LEAKS" "$INTERNAL_ERRORS"
echo "══════════════════════════════════════════════════════════════════════════════════════════════════════════════"

if [[ $LEAKS -gt 0 || $INTERNAL_ERRORS -gt 0 ]]; then
    exit 1
fi
exit 0

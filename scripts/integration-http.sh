#!/usr/bin/env bash
#
# AUSUS L4 — end-to-end HTTP integration runner
# ---------------------------------------------
# Boots `php -S` against apps/playground/server.php, polls /api/_health
# until ready, runs apps/playground/web/live-trace.tsx, then tears the
# server down. Designed for CI + local validation.
#
# Usage:    scripts/integration-http.sh
# Env:      PORT=8787       HTTP port (default 8787)
#           HOST=127.0.0.1  bind address (default 127.0.0.1)
#           KEEP=1          retain server log on success

set -euo pipefail

cd "$(dirname "$0")/.."

PORT="${PORT:-8787}"
HOST="${HOST:-127.0.0.1}"
LOG="/tmp/ausus-integration-http.log"
# Use an absolute path explicitly — sys_get_temp_dir() returns
# /var/folders/.../T/ on macOS, which would persist DBs across runs and
# defeat the "fresh boot" guarantee. /tmp is portable across macOS + Linux.
DB="/tmp/ausus-server-integration.sqlite"
PID_FILE="/tmp/ausus-integration-http.pid"

cleanup() {
    local pid
    if [[ -f "$PID_FILE" ]]; then
        pid="$(cat "$PID_FILE")"
        if kill -0 "$pid" 2>/dev/null; then
            kill "$pid" 2>/dev/null || true
            sleep 0.2
            kill -9 "$pid" 2>/dev/null || true
        fi
        rm -f "$PID_FILE"
    fi
    if [[ "${KEEP:-0}" != "1" ]]; then
        rm -f "$LOG" "$DB"
    fi
}
trap cleanup EXIT

echo "[integration-http] reset SQLite + boot PHP server on $HOST:$PORT"
rm -f "$DB"

# IMPORTANT: do NOT export AUSUS_RESET_DB to the server process.
# server.php would re-execute the wipe + seed on every request, generating
# fresh ULIDs each time and breaking POST/PUT round-trips. We've already
# deleted the DB above; the first request will detect file_exists()==false
# and seed exactly once.
AUSUS_DB_PATH="$DB" nohup php -S "$HOST:$PORT" apps/playground/server.php \
    >"$LOG" 2>&1 &
echo "$!" > "$PID_FILE"
SERVER_PID="$(cat "$PID_FILE")"
echo "[integration-http] server pid=$SERVER_PID log=$LOG"

# Poll until ready (or fail fast)
for i in 1 2 3 4 5 6 7 8 9 10; do
    if curl -fsS "http://$HOST:$PORT/api/_health" >/dev/null 2>&1; then
        echo "[integration-http] server ready after ${i}00ms"
        break
    fi
    if ! kill -0 "$SERVER_PID" 2>/dev/null; then
        echo "[integration-http] server died during boot — log:"
        cat "$LOG"
        exit 2
    fi
    sleep 0.1
done

if ! curl -fsS "http://$HOST:$PORT/api/_health" >/dev/null 2>&1; then
    echo "[integration-http] server never became ready"
    cat "$LOG"
    exit 3
fi

# Ensure renderer is built (live-trace imports from dist/)
if [[ ! -f renderer/react/dist/index.js ]]; then
    echo "[integration-http] building renderer first"
    npm run build >/dev/null
fi

# Run the live trace
echo "[integration-http] running live-trace.tsx"
AUSUS_API_BASE_URL="http://$HOST:$PORT/api" \
    npx --no-install tsx apps/playground/web/live-trace.tsx
RC=$?

if [[ "$RC" -ne 0 ]]; then
    echo "[integration-http] live-trace failed (rc=$RC)"
    exit "$RC"
fi

# ─── Filter + sort live HTTP coverage ───────────────────────────────────────
# Add live-HTTP assertions against the running server for the new wire surface
# (?filter, ?sort, schemaVersion 1.2.0 echoes). The in-process Router tests in
# apps/playground/integration-filter-sort-test.php exercise the same shape;
# this block is the live-HTTP mirror that goes through socket + PHP-FPM + body
# parsing instead of an in-process ServerRequest.
echo ""
echo "[integration-http] filter + sort live HTTP coverage"

API="http://$HOST:$PORT/api"
TENANT_HEADER='X-Tenant-ID: acme'
ROLE_HEADER='X-Actor-Roles: invoice.creator,invoice.issuer,invoice.viewer'
INTPASS=0
INTFAIL=0
ipass() { printf "  ✓ %s\n" "$1"; INTPASS=$((INTPASS + 1)); }
ifail() { printf "  ✗ %s — %s\n" "$1" "$2"; INTFAIL=$((INTFAIL + 1)); }
iassert() {
    # iassert <name> <actual> <expected>
    if [[ "$2" == "$3" ]]; then ipass "$1"; else ifail "$1" "got '$2', expected '$3'"; fi
}

# Seed a handful more invoices so filter/sort have something to bite on.
seed_invoice() {
    # Router expects {subject, inputs: {...}} per the renderer-shaped POST
    # contract; live-trace.tsx uses the same envelope.
    curl -fsS -X POST \
        -H "$TENANT_HEADER" -H "$ROLE_HEADER" -H 'Content-Type: application/json' \
        --data "{\"subject\":null,\"inputs\":{\"number\":\"$1\",\"customer_name\":\"$2\",\"amount\":{\"amount\":\"$3\",\"currency\":\"USD\"}}}" \
        "$API/actions/billing.invoice.create" > /dev/null
}
seed_invoice "INV-2026-003" "ACME Holdings"       "750.00"
seed_invoice "INV-2026-004" "Initech LLC"         "1200.00"
seed_invoice "INV-2026-005" "Vandelay Industries" "900.00"

# State at this point: live-trace.tsx ran a full transition cycle —
#   invoice 1 (INV-2026-001 / ACME Corporation)   → ISSUED
#   invoice 2 (INV-2026-002 / Globex Industries)  → CANCELLED
#   invoice 3 (INV-FORM-001 / Form Co)            → DRAFT  (created by test 12)
# Plus the 3 we just seeded above:
#   INV-2026-003 / ACME Holdings                  → DRAFT
#   INV-2026-004 / Initech LLC                    → DRAFT
#   INV-2026-005 / Vandelay Industries            → DRAFT
# Total = 6 invoices; 1 ISSUED, 1 CANCELLED, 4 DRAFT; 2 customers contain "acme".
ALL="$(curl -fsS -H "$TENANT_HEADER" "$API/projections/billing.invoice.summary")"
TOTAL="$(echo "$ALL" | jq -r '.data.pagination.totalCount')"
iassert 'baseline projection: totalCount = 6' "$TOTAL" "6"

# 1. schemaVersion echo: 1.2.0 confirmed from main runtime
SCHEMA="$(echo "$ALL" | jq -r '.schemaVersion')"
iassert 'schemaVersion = 1.2.0' "$SCHEMA" "1.2.0"

# 2. top-level filters/sort echoes
iassert 'baseline filters[] echoed as []'  "$(echo "$ALL" | jq -c '.filters')" '[]'
iassert 'baseline sort[] echoed as []'     "$(echo "$ALL" | jq -c '.sort')"    '[]'

# 3. pagination expanded keys
iassert 'pagination.limit = 50 default'    "$(echo "$ALL" | jq -r '.data.pagination.limit')"      "50"
iassert 'pagination.offset = 0 default'    "$(echo "$ALL" | jq -r '.data.pagination.offset')"     "0"
iassert 'pagination.totalCount = 6'        "$(echo "$ALL" | jq -r '.data.pagination.totalCount')" "6"
iassert 'pagination.nextCursor = null'     "$(echo "$ALL" | jq -r '.data.pagination.nextCursor')" "null"

# 4. filter eq (status=DRAFT → 4 of 6 are draft)
RESP="$(curl -fsS -H "$TENANT_HEADER" "$API/projections/billing.invoice.summary?filter.status.eq=DRAFT")"
iassert 'filter eq DRAFT: totalCount = 4'  "$(echo "$RESP" | jq -r '.data.pagination.totalCount')" "4"
iassert 'filter eq DRAFT: filters[].op'    "$(echo "$RESP" | jq -r '.filters[0].op')"             "eq"
iassert 'filter eq DRAFT: filters[].field' "$(echo "$RESP" | jq -r '.filters[0].field')"          "status"

# 5. filter contains (case-insensitive substring on customer_name)
RESP="$(curl -fsS -H "$TENANT_HEADER" "$API/projections/billing.invoice.summary?filter.customer_name.contains=acme")"
iassert 'filter contains acme: totalCount = 2' "$(echo "$RESP" | jq -r '.data.pagination.totalCount')" "2"

# 6. filter in (comma list)
INRESP="$(curl -fsS -H "$TENANT_HEADER" "$API/projections/billing.invoice.summary?filter.number.in=INV-2026-001,INV-2026-005")"
iassert 'filter in (2 numbers): totalCount = 2' "$(echo "$INRESP" | jq -r '.data.pagination.totalCount')" "2"

# 7. sort asc on number — lexicographic, so 'F' > '2' puts INV-FORM-001 last
RESP="$(curl -fsS -H "$TENANT_HEADER" "$API/projections/billing.invoice.summary?sort=number:asc")"
FIRST="$(echo "$RESP" | jq -r '.data.items[0].number')"
LAST="$(echo  "$RESP" | jq -r '.data.items[-1].number')"
iassert 'sort number:asc — first item' "$FIRST" "INV-2026-001"
iassert 'sort number:asc — last item'  "$LAST"  "INV-FORM-001"

# 8. sort desc on number — reverses asc
RESP="$(curl -fsS -H "$TENANT_HEADER" "$API/projections/billing.invoice.summary?sort=number:desc")"
FIRST="$(echo "$RESP" | jq -r '.data.items[0].number')"
LAST="$(echo  "$RESP" | jq -r '.data.items[-1].number')"
iassert 'sort number:desc — first item' "$FIRST" "INV-FORM-001"
iassert 'sort number:desc — last item'  "$LAST"  "INV-2026-001"

# 9. filter + sort combined
RESP="$(curl -fsS -H "$TENANT_HEADER" "$API/projections/billing.invoice.summary?filter.customer_name.contains=acme&sort=number:desc")"
iassert 'filter+sort: totalCount = 2'      "$(echo "$RESP" | jq -r '.data.pagination.totalCount')" "2"
iassert 'filter+sort: items[0] = 003-acme' "$(echo "$RESP" | jq -r '.data.items[0].number')"     "INV-2026-003"

# 10. pagination + sort stability — slice the sorted set in 2 pages, verify
#     concatenation is monotonically ascending. 6 total → page1 (3) + page2 (3).
P1="$(curl -fsS -H "$TENANT_HEADER" "$API/projections/billing.invoice.summary?sort=number:asc&limit=3&offset=0")"
P2="$(curl -fsS -H "$TENANT_HEADER" "$API/projections/billing.invoice.summary?sort=number:asc&limit=3&offset=3")"
COMBINED="$(jq -n --argjson a "$P1" --argjson b "$P2" '$a.data.items + $b.data.items | map(.number)')"
SORTED="$(echo "$COMBINED" | jq 'sort')"
if [[ "$COMBINED" == "$SORTED" ]]; then ipass 'paginated sort: concat is monotonically asc'
else                                    ifail 'paginated sort: concat is monotonically asc' 'order broken'; fi
iassert 'paginated sort: page1 has 3 items' "$(echo "$P1" | jq -r '.data.items | length')" "3"
iassert 'paginated sort: page2 has 3 items' "$(echo "$P2" | jq -r '.data.items | length')" "3"

# 11. invalid operator → HTTP 400
HTTP="$(curl -s -o /dev/null -w '%{http_code}' \
    -H "$TENANT_HEADER" "$API/projections/billing.invoice.summary?filter.status.regex=foo")"
iassert 'invalid filter operator → 400' "$HTTP" "400"

# 12. invalid sort direction → HTTP 400
HTTP="$(curl -s -o /dev/null -w '%{http_code}' \
    -H "$TENANT_HEADER" "$API/projections/billing.invoice.summary?sort=number:UPPERCASE")"
iassert 'invalid sort direction → 400' "$HTTP" "400"

# 13. unknown field on filter → HTTP 400
HTTP="$(curl -s -o /dev/null -w '%{http_code}' \
    -H "$TENANT_HEADER" "$API/projections/billing.invoice.summary?filter.bogus.eq=x")"
iassert 'unknown filter field → 400' "$HTTP" "400"

echo ""
echo "[integration-http] live filter+sort: passed=$INTPASS failed=$INTFAIL"
if [[ "$INTFAIL" -gt 0 ]]; then
    echo "[integration-http] FAILED (live filter+sort)"
    exit 4
fi
echo "[integration-http] exit code: 0"

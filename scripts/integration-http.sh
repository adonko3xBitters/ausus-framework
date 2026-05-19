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

echo ""
echo "[integration-http] exit code: $RC"
exit $RC

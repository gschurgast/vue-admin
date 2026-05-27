#!/usr/bin/env bash
# Phase 03 — automatable smoke tests for the public transformation route.
# Runs tests #2, #3, #6 from 03-HUMAN-UAT.md.
#
# Requires the stack running: docker compose up -d
# Requires asset id=2 with isPublic=true and transformation code="test" present.

set -u
API=http://localhost:8080
URL="$API/t/test/2.webp"

GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; NC='\033[0m'
pass=0; fail=0

ok()   { echo -e "  ${GREEN}✓${NC} $1"; pass=$((pass+1)); }
ko()   { echo -e "  ${RED}✗${NC} $1"; fail=$((fail+1)); }
info() { echo -e "  ${YELLOW}•${NC} $1"; }

header() { echo; echo "═══ $1 ═══"; }

# ───────────────────────────────────────────────────────────────
# #2 — Cache hit + 304 conditionnel
# ───────────────────────────────────────────────────────────────
header "#2 Cache hit + 304 conditionnel"

# Warm cache first (idempotent: if already warm, this is the hit)
curl -sI "$URL" > /dev/null

start_ns=$(date +%s%N)
HDR1=$(curl -sI "$URL")
end_ns=$(date +%s%N)
latency_ms=$(( (end_ns - start_ns) / 1000000 ))

status1=$(echo "$HDR1" | head -1 | tr -d '\r')
etag=$(echo "$HDR1" | grep -i '^Etag:' | cut -d' ' -f2- | tr -d '\r"')

info "2nd request: $status1 (${latency_ms}ms), ETag=\"$etag\""
echo "$status1" | grep -q "200 OK" && ok "200 served from cache" || ko "expected 200, got $status1"
[ "$latency_ms" -lt 200 ] && ok "latency < 200ms (cache hit)" || ko "latency ${latency_ms}ms — cache likely missed"

if [ -n "$etag" ]; then
    HDR2=$(curl -sI -H "If-None-Match: \"$etag\"" "$URL")
    status2=$(echo "$HDR2" | head -1 | tr -d '\r')
    body_len=$(curl -s -o /dev/null -w '%{size_download}' -H "If-None-Match: \"$etag\"" "$URL")
    info "If-None-Match: \"$etag\" → $status2 (body=${body_len}B)"
    echo "$status2" | grep -q "304" && ok "304 Not Modified" || ko "expected 304, got $status2"
    [ "$body_len" = "0" ] && ok "empty body on 304" || ko "expected empty body, got ${body_len}B"
else
    ko "no ETag header in 2nd response"
fi

# ───────────────────────────────────────────────────────────────
# #6 — CORS preflight
# ───────────────────────────────────────────────────────────────
header "#6 CORS preflight"

# Preflight OPTIONS check (Allow-Origin / Allow-Methods)
PREFLIGHT=$(curl -sI -X OPTIONS \
    -H 'Origin: https://example.com' \
    -H 'Access-Control-Request-Method: GET' \
    "$URL")

cors_origin=$(echo "$PREFLIGHT" | grep -i '^Access-Control-Allow-Origin:' | cut -d' ' -f2- | tr -d '\r')
cors_methods=$(echo "$PREFLIGHT" | grep -i '^Access-Control-Allow-Methods:' | cut -d' ' -f2- | tr -d '\r')
preflight_status=$(echo "$PREFLIGHT" | head -1 | tr -d '\r')

info "Preflight: $preflight_status | Allow-Origin: $cors_origin | Allow-Methods: $cors_methods"
echo "$preflight_status" | grep -qE "2[0-9]{2}" && ok "2xx preflight" || ko "expected 2xx, got $preflight_status"
[ -n "$cors_origin" ] && ok "Access-Control-Allow-Origin present" || ko "missing Allow-Origin"
echo "$cors_methods" | grep -qi 'GET' && ok "Allow-Methods includes GET" || ko "Allow-Methods missing GET"

# Expose-Headers is sent on the ACTUAL GET response, not on the preflight.
GET_HDR=$(curl -sI -H 'Origin: https://example.com' "$URL")
cors_expose=$(echo "$GET_HDR" | grep -i '^Access-Control-Expose-Headers:' | cut -d' ' -f2- | tr -d '\r')
info "Expose-Headers (on GET): $cors_expose"
echo "$cors_expose" | grep -qi 'etag' && ok "Expose-Headers includes ETag" || ko "Expose-Headers missing ETag"
echo "$cors_expose" | grep -qi 'x-transformation-warnings' && ok "Expose-Headers includes X-Transformation-Warnings" || ko "Expose-Headers missing X-Transformation-Warnings"

# ───────────────────────────────────────────────────────────────
# #3 — Feature flag OFF
# Toggles TRANSFORMATIONS_PUBLIC_ROUTE_ENABLED=0, restarts api, hits the route.
# Captures Doctrine query log before/after to assert no SQL was emitted.
# ───────────────────────────────────────────────────────────────
header "#3 Feature flag OFF (destructive — restarts api)"

if [ "${SKIP_RESTART:-0}" = "1" ]; then
    info "SKIP_RESTART=1 — skipping feature-flag test"
else
    ENV_LOCAL=api/.env.local
    BACKUP=
    if [ -f "$ENV_LOCAL" ]; then
        BACKUP=$(mktemp)
        cp "$ENV_LOCAL" "$BACKUP"
    fi
    cleanup() {
        if [ -n "$BACKUP" ]; then cp "$BACKUP" "$ENV_LOCAL" && rm -f "$BACKUP"
        else rm -f "$ENV_LOCAL"; fi
        info "Restoring API with flag back ON"
        docker compose restart api > /dev/null 2>&1
        for i in $(seq 1 20); do
            curl -sf "$API/api/docs.json" > /dev/null 2>&1 && break
            sleep 0.5
        done
    }
    trap cleanup EXIT

    info "Adding TRANSFORMATIONS_PUBLIC_ROUTE_ENABLED=0 to $ENV_LOCAL"
    echo "TRANSFORMATIONS_PUBLIC_ROUTE_ENABLED=0" >> "$ENV_LOCAL"

    info "Restarting api container to reload .env.local"
    docker compose restart api > /dev/null 2>&1
    for i in $(seq 1 30); do
        curl -sf "$API/api/docs.json" > /dev/null 2>&1 && break
        sleep 0.5
    done

    # Snapshot SQL count before (Doctrine logs all queries at DEBUG in dev)
    sql_before=$(docker compose logs --tail=1000 api 2>&1 | grep -c 'asset_transformation' || true)

    HDR_OFF=$(curl -sI "$URL")
    status_off=$(echo "$HDR_OFF" | head -1 | tr -d '\r')
    cache_off=$(echo "$HDR_OFF" | grep -i '^Cache-Control:' | cut -d' ' -f2- | tr -d '\r')

    # Brief pause to ensure logs flushed
    sleep 0.3
    sql_after=$(docker compose logs --tail=1000 api 2>&1 | grep -c 'asset_transformation' || true)
    sql_delta=$((sql_after - sql_before))

    info "Status: $status_off"
    info "Cache-Control: $cache_off"
    info "asset_transformation SQL hits during request: $sql_delta"

    echo "$status_off" | grep -q "404" && ok "404 with flag OFF" || ko "expected 404, got $status_off"
    echo "$cache_off" | grep -q 'max-age=300' && ok "Cache-Control max-age=300 (404 TTL)" || ko "expected max-age=300, got '$cache_off'"
    [ "$sql_delta" = "0" ] && ok "no SQL on asset_transformation (early exit)" || ko "expected 0 SQL queries, got $sql_delta"
fi

# ───────────────────────────────────────────────────────────────
echo
echo "═══════════════════════════════════════════════════════════════"
echo "  Smoke results: ${GREEN}${pass} passed${NC} / ${RED}${fail} failed${NC}"
echo "═══════════════════════════════════════════════════════════════"

[ "$fail" -eq 0 ] || exit 1

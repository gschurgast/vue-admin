#!/usr/bin/env bash
# Phase 4 — Bench BiRefNet inference latency. Used for D-13 checklist item 4 signoff.
#
# Usage: ./embedder/bin/bench_bgremove.sh <image.jpg> [iterations=10] [model=birefnet]
#
# Reports p50/p95/p99 latency in ms, plus mean and max.
# Pass criteria: p95 < 3000ms on 2048x2048 product photos (BGREMOVE-05, D-13).
set -euo pipefail

IMG="${1:-embedder/tests/fixtures/product_2048.png}"
N="${2:-10}"
MODEL="${3:-birefnet}"
URL="${EMBEDDER_URL:-http://localhost:8000}/img/remove-background"

if [[ ! -f "$IMG" ]]; then
    echo "ERR: image not found: $IMG" >&2
    exit 2
fi

echo "Bench: $N iterations, model=$MODEL, image=$IMG, url=$URL"
LATENCIES=()
for i in $(seq 1 "$N"); do
    T=$(curl -s -o /tmp/bench_out.png -w "%{time_total}" \
        -F "image=@${IMG}" \
        -F "params={\"model\":\"${MODEL}\"}" \
        "$URL")
    MS=$(awk -v t="$T" 'BEGIN { printf "%d", t*1000 }')
    LATENCIES+=("$MS")
    printf "  [%2d/%d] %s ms\n" "$i" "$N" "$MS"
done

# Sort and compute percentiles
SORTED=$(printf '%s\n' "${LATENCIES[@]}" | sort -n)
P50_IDX=$(( N / 2 ))
P95_IDX=$(( (N * 95 + 99) / 100 - 1 ))
P99_IDX=$(( (N * 99 + 99) / 100 - 1 ))
[[ $P95_IDX -lt 0 ]] && P95_IDX=0
[[ $P99_IDX -lt 0 ]] && P99_IDX=0

P50=$(echo "$SORTED" | sed -n "$((P50_IDX+1))p")
P95=$(echo "$SORTED" | sed -n "$((P95_IDX+1))p")
P99=$(echo "$SORTED" | sed -n "$((P99_IDX+1))p")
MAX=$(echo "$SORTED" | tail -1)
MEAN=$(printf '%s\n' "${LATENCIES[@]}" | awk '{s+=$1} END {printf "%d", s/NR}')

echo ""
echo "Results (ms):"
echo "  mean = $MEAN"
echo "  p50  = $P50"
echo "  p95  = $P95   ( target < 3000 for BGREMOVE-05 )"
echo "  p99  = $P99"
echo "  max  = $MAX"

if (( P95 >= 3000 )); then
    echo ""
    echo "FAIL: p95 ($P95 ms) >= 3000 ms — does NOT pass D-13 item 4."
    exit 1
fi
echo "PASS: p95 < 3000 ms."

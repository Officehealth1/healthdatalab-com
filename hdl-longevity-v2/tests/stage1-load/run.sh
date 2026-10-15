#!/usr/bin/env bash
# Stage 1 lead route under load (v0.48.16). Costs nothing: every payload has no
# q9 and no rate_of_ageing_result, so no rate is computed and no PDF can fire.
#
#   tests/stage1-load/run.sh <host> <requests-per-second> <seconds>
#   tests/stage1-load/run.sh stby.healthdatalab.net 10 30
#
# Prints one line: rate, 2xx, 429, 503, other, p50, p95 (seconds).
# Clean up afterwards: DELETE FROM wp_hdlv2_widget_leads WHERE visitor_email LIKE 'loadtest+%@example.com'
set -euo pipefail
host="${1:?host}"; rate="${2:?rate}"; secs="${3:?seconds}"
[ "$host" = "healthdatalab.net" ] && { echo "refusing the live host" >&2; exit 2; }

out="$(mktemp)"
run="$(date +%s)"
for s in $(seq 1 "$secs"); do
  for i in $(seq 1 "$rate"); do
    n="${run}-${s}-${i}"
    curl -s -o /dev/null -m 30 -w '%{http_code} %{time_total}\n' -X POST "https://${host}/wp-json/hdl-v2/v1/widget/lead" \
      -H 'Content-Type: application/json' \
      -d "{\"practitioner_id\":122,\"name\":\"Load ${n}\",\"email\":\"loadtest+${n}@example.com\",\"q1_age\":44,\"q1_sex\":\"male\"}" >> "$out" &
  done
  sleep 1
done
wait

sort -k2 -n "$out" | awk -v r="$rate" '
  { c[NR] = $2; code = $1
    if (code ~ /^2/) ok++; else if (code == 429) lim++; else if (code == 503) busy++; else other++ }
  END { p50 = c[int(NR * 0.50) > 0 ? int(NR * 0.50) : 1]; p95 = c[int(NR * 0.95) > 0 ? int(NR * 0.95) : 1]
        printf "rate=%s 2xx=%d 429=%d 503=%d other=%d p50=%.2f p95=%.2f\n", r, ok, lim, busy, other, p50, p95 }'
rm -f "$out"

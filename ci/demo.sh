#!/usr/bin/env bash
#
# Start the aDataForSeo demo stack, seed a demo project, run every job kind
# on offline demo data, start the queue worker and the scheduler, print the URLs.
#
# Usage: ci/demo.sh [--no-build]
#
# Env: PORT (CMS, default 8080), EVO_SRC, PHP_VERSION, DOCKER (default docker),
#      WAIT_SECONDS (how long to wait for the CMS to be built, default 900)
#
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
docker_bin=${DOCKER:-docker}
compose=("$docker_bin" compose -f "$here/compose.demo.yaml")
port=${PORT:-8080}
wait_seconds=${WAIT_SECONDS:-900}

step() { printf '\n==> %s\n' "$*"; }

step "Build and start the stack"
if [ "${1:-}" = "--no-build" ]; then
    "${compose[@]}" up -d
else
    "${compose[@]}" up -d --build
fi

step "Wait for the CMS (it is installed on first start)"
ready=0
for _ in $(seq 1 "$((wait_seconds / 3))"); do
    code=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept-Language: en-US,en;q=0.9' "http://localhost:$port/manager/index.php" || true)
    if [ "$code" = "200" ]; then ready=1; break; fi
    sleep 3
done
[ "$ready" = "1" ] || { echo "demo.sh: the CMS did not come up in ${wait_seconds}s; see: ${compose[*]} logs serve" >&2; exit 1; }

step "Seed the demo project and run every job (offline demo data, nothing is charged)"
"${compose[@]}" exec -T serve sh -c 'cd /build && php core/artisan adfs:demo --documents --run --domain=localhost:'"$port"

step "Start the queue worker and the scheduler (what cron + supervisor do on a real server)"
# system:task-worker --once takes one queued slice (stask:worker when sTask is
# installed, as it is in the CMS checkout); schedule:run fires
# adfs:tick, which wakes jobs waiting for DataForSEO and runs schedules.
"${compose[@]}" exec -T -d serve sh -c 'cd /build && while true; do php core/artisan system:task-worker --once --silent; php core/artisan stask:worker >/dev/null 2>&1 || true; php core/artisan schedule:run --quiet; sleep 3; done'

cat <<EOF

aDataForSeo demo is up.

  Manager        http://localhost:$port/manager/      admin / Passw0rd123
  Module         Modules → Search (or the "Search" widget on the welcome page)

Stop and wipe:   docker compose -f ci/compose.demo.yaml down -v
EOF

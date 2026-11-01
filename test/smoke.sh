#!/usr/bin/env bash
# Boots the standalone stack against a freshly built image and verifies the
# container comes up, serves the health endpoint, renders the UI, and bootstraps
# the database schema without fatal errors. Used by CI and can be run locally:
#
#   SPOTWEB_IMAGE=spotweb:test ./test/smoke.sh
#
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE_FILE="deploy/standalone/docker-compose.yml"
export SPOTWEB_IMAGE="${SPOTWEB_IMAGE:-spotweb:test}"
export MYSQL_PASSWORD="smoketest"
export SPOTWEB_PORT="8081"
# Exercises the entrypoint's fallback config; the host is never contacted.
export SPOTWEB_NNTP_FALLBACK_1_HOST="news.example.invalid"
export SPOTWEB_NNTP_FALLBACK_1_PASS='smoke"pa$$'

cleanup() {
    echo "--- spotweb logs ---"
    docker compose -f "$COMPOSE_FILE" logs spotweb || true
    docker compose -f "$COMPOSE_FILE" down -v || true
}
trap cleanup EXIT

docker compose -f "$COMPOSE_FILE" up -d

echo "Waiting for spotweb to become healthy..."
status="starting"
for _ in $(seq 1 60); do
    status="$(docker inspect --format '{{.State.Health.Status}}' spotweb 2>/dev/null || echo starting)"
    [ "$status" = "healthy" ] && break
    [ "$status" = "unhealthy" ] && { echo "container reported unhealthy"; exit 1; }
    sleep 5
done
if [ "$status" != "healthy" ]; then
    echo "spotweb did not become healthy in time"
    exit 1
fi

base="http://127.0.0.1:${SPOTWEB_PORT}"

echo "Checking ${base}/healthz"
curl -fsS "$base/healthz" | grep -q ok

echo "Checking ${base}/ returns a valid HTTP status"
code="$(curl -s -o /dev/null -w '%{http_code}' "$base/")"
case "$code" in
    200|302) echo "GET / -> $code" ;;
    *) echo "GET / returned unexpected status $code"; exit 1 ;;
esac

echo "Verifying the schema upgrade ran without fatal errors"
if docker compose -f "$COMPOSE_FILE" logs spotweb | grep -qiE 'SpotWeb crashed|PHP Fatal|Fatal error'; then
    echo "Found a fatal error in the logs"
    exit 1
fi

echo "Verifying the fallback newsserver config was generated"
docker compose -f "$COMPOSE_FILE" exec -T spotweb sh -c '
    test "$(stat -c "%U:%G %a" /etc/spotweb/nntp-fallback.json)" = "root:www-data 640" &&
    su-exec www-data php -r "exit(json_decode(file_get_contents(\"/etc/spotweb/nntp-fallback.json\"), true)[\"servers\"][0][\"host\"] === \"news.example.invalid\" ? 0 : 1);"
' || { echo "nntp-fallback.json missing or wrong"; exit 1; }

./test/nntp-fallback.sh

echo "Smoke test passed"

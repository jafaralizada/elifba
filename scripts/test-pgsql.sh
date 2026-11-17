#!/usr/bin/env bash
#
# The Pest suite on a throwaway PostgreSQL 17, as the CI `pgsql` job runs it.
#
#   composer test:pgsql                     # the whole suite
#   scripts/test-pgsql.sh --filter=Rankings # arguments go to `php artisan test`
#
# Starts its own `postgres:17` container on a free 127.0.0.1 port, runs the
# suite with ELIFBA_TEST_PGSQL_URL (tests/bootstrap.php) in a clean
# environment, so no DB_*, APP_* or REVERB_* from the shell leaks in, and
# removes the container on exit. Needs Docker; touches no other container.

set -euo pipefail

cd "$(dirname "$0")/.."

name="elifba-test-pgsql-$$"

docker run --detach --rm --name "$name" \
    --env POSTGRES_DB=elifba \
    --env POSTGRES_USER=elifba \
    --env POSTGRES_PASSWORD=elifba \
    --publish 127.0.0.1::5432 \
    postgres:17 >/dev/null

trap 'docker stop "$name" >/dev/null 2>&1 || true' EXIT

# The image's init runs a server on the socket only, then restarts it on
# TCP; ready on 127.0.0.1 means the real server is up.
for _ in $(seq 1 60); do
    if docker exec "$name" pg_isready --quiet --host=127.0.0.1 --username=elifba; then
        break
    fi
    sleep 1
done
docker exec "$name" pg_isready --quiet --host=127.0.0.1 --username=elifba

port=$(docker port "$name" 5432/tcp | head -n 1 | sed 's/.*://')
echo "PostgreSQL 17 on 127.0.0.1:${port} (container ${name})"

# Only what finds PHP and its php.ini crosses over (Herd's PHP reads its ini
# directory from HERD_PHP_*_INI_SCAN_DIR; without it PHP runs with no ini).
keep=()
while IFS= read -r line; do
    keep+=("$line")
done < <(env | grep -E '^(PHP_INI_SCAN_DIR|HERD_[A-Z0-9_]+)=' || true)

env -i HOME="$HOME" PATH="$PATH" TERM="${TERM:-dumb}" ${keep[@]+"${keep[@]}"} \
    ELIFBA_TEST_PGSQL_URL="pgsql://elifba:elifba@127.0.0.1:${port}/elifba" \
    php artisan test "$@"

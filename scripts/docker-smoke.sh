#!/usr/bin/env bash
# Smoke test of the Docker stack (docker-compose.yml, docs/architecture.md
# §10), run by the `docker` job in .github/workflows/ci.yml and by hand from a
# copy of the repository:
#
#   scripts/docker-smoke.sh
#
# Without a .env it writes one for http://localhost:${ELIFBA_SMOKE_PORT:-8088}
# (scripts/docker-env.sh). It builds and starts the stack, then checks: every
# service healthy; /up through Caddy; the login page with built assets and no
# Vite dev server; a WebSocket handshake to Reverb through Caddy; Reverb's HTTP
# API not reachable from outside but reachable from `app`; the scheduler's
# four tasks; a backup dump; data surviving `down` and `up`.
#
# With the profile `voice` (COMPOSE_PROFILES=voice in .env, or
# ELIFBA_SMOKE_VOICE=1, which names it for this run and takes the address
# 127.0.0.1 unless .env has one) it also checks our voice server (§10 "Live
# voice"): `voice` healthy; only 7882/udp and 7881/tcp published beside the
# proxy's ports; /rtc through Caddy answering as the voice server does, its API
# not public and reachable from `app`; the config it runs with; a voice room
# made through the API, joined through Caddy with a ticket minted here, whose
# join response offers no ICE server but the instance's own address; no ticket
# in a log; the server healthy on a network with no route out. Without the
# profile the checks are the ones above, as many as before.
#
# ELIFBA_SMOKE_BUILD=0 starts the image already built (the second run of CI's
# `docker` job). ELIFBA_SMOKE_DOWN=1 removes the stack and its volumes at the end.

set -euo pipefail

cd "$(dirname "$0")/.."

failures=0
pass() { printf '  \033[32mok\033[0m   %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; failures=$((failures + 1)); }
step() { printf '\n== %s\n' "$1"; }
check() { # check <description> <command...>
    local description=$1
    shift
    if "$@"; then pass "$description"; else fail "$description"; fi
}

if [ ! -f .env ]; then
    step "Writing .env for an http try-out"
    scripts/docker-env.sh localhost http "${ELIFBA_SMOKE_PORT:-8088}"
fi

# One value from .env, without its quotes; empty when .env has no such line.
env_value() {
    { grep -E "^$1=" .env || true; } | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

if [ "${ELIFBA_SMOKE_VOICE:-0}" = 1 ]; then
    export COMPOSE_PROFILES=voice
    ELIFBA_ROOMS_VOICE_IP=${ELIFBA_ROOMS_VOICE_IP:-$(env_value ELIFBA_ROOMS_VOICE_IP)}
    export ELIFBA_ROOMS_VOICE_IP=${ELIFBA_ROOMS_VOICE_IP:-127.0.0.1}
fi
case ",${COMPOSE_PROFILES-$(env_value COMPOSE_PROFILES)}," in
    *,voice,*) voice=1 ;;
    *) voice=0 ;;
esac

domain=$(env_value ELIFBA_DOMAIN)
scheme=$(env_value ELIFBA_SCHEME)
port=$(env_value ELIFBA_PORT)
reverb_key=$(env_value REVERB_APP_KEY)
reverb_id=$(env_value REVERB_APP_ID)
base="$scheme://$domain:$port"

compose() { docker compose "$@"; }
status_of() { # status_of <url> [curl options...]
    local url=$1
    shift
    curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$@" "$url" || true
}

services="proxy app reverb scheduler db backup"
[ "$voice" = 0 ] || services="$services voice"

if [ "${ELIFBA_SMOKE_BUILD:-1}" = 0 ]; then
    step "Starting the stack for $base (${services// /, })"
    compose up -d --wait --wait-timeout 300
else
    step "Building and starting the stack for $base (${services// /, })"
    compose up -d --build --wait --wait-timeout 300
fi

step "Services"
for service in $services; do
    health=$(docker inspect --format '{{.State.Health.Status}}' "$(compose ps -q "$service")" 2>/dev/null || echo missing)
    check "$service is $health" test "$health" = healthy
done

step "HTTP through Caddy"
check "/up answers 200" test "$(status_of "$base/up")" = 200

login=$(curl -fsS --max-time 10 "$base/login" || true)
asset=$(grep -oE '/build/assets/[^"]+\.js' <<<"$login" | head -n 1 || true)
check "the login page names a built script ($asset)" test -n "$asset"
check "the built script loads" test "$(status_of "$base$asset")" = 200
check "the page does not point at a Vite dev server" bash -c '! grep -q "@vite/client" <<<"$1"' _ "$login"
check "fonts come from the app itself" bash -c '! grep -qE "fonts\.(googleapis|bunny)" <<<"$1"' _ "$login"

step "Reverb through Caddy"
handshake() { # handshake <origin>: the status line and first frame of a WebSocket to /app/{key}
    curl -s -i --http1.1 --max-time 3 \
        -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
        -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
        -H "Origin: $1" \
        "$base/app/$reverb_key?protocol=7&client=js&version=8.4.0&flash=false" |
        LC_ALL=C tr -c '[:print:]\n' '.' || true
}
own=$(handshake "$base")
check "a WebSocket from the app's origin is switched (101)" grep -q '^HTTP/1.1 101' <<<"$own"
check "… and Reverb accepts it (pusher:connection_established)" grep -q 'pusher:connection_established' <<<"$own"
check "a WebSocket from another origin is refused (4009)" \
    grep -q '"code\\":4009' <<<"$(handshake http://elsewhere.example)"
check "Reverb's HTTP API is not public: GET /apps/… is 404" \
    test "$(status_of "$base/apps/$reverb_id/channels")" = 404
check "Reverb's HTTP API is not public: POST /apps/…/events is 404" \
    test "$(status_of "$base/apps/$reverb_id/events" -X POST -H 'Content-Type: application/json' -d '{}')" = 404
check "app reaches Reverb on the internal network" \
    compose exec -T app curl -fsS --max-time 5 -o /dev/null http://reverb:8080/up

if [ "$voice" = 1 ]; then
    step "Live voice"
    voice_ip=${ELIFBA_ROOMS_VOICE_IP:-$(env_value ELIFBA_ROOMS_VOICE_IP)}
    voice_key=$(env_value ELIFBA_ROOMS_VOICE_KEY)
    voice_secret=$(env_value ELIFBA_ROOMS_VOICE_SECRET)
    redirect_port=$(env_value ELIFBA_REDIRECT_PORT)

    published=$(for s in $services; do
        compose ps --format '{{range .Publishers}}{{if .PublishedPort}}{{.PublishedPort}}/{{.Protocol}} {{end}}{{end}}' "$s"
    done | tr ' ' '\n' | grep . | sort -u | tr '\n' ' ')
    expected=$(printf '%s\n' "$port/tcp" "${redirect_port:-80}/tcp" 7881/tcp 7882/udp | sort -u | tr '\n' ' ')
    check "only 7882/udp and 7881/tcp are published beside the proxy's ($published)" test "$published" = "$expected"

    upgrade=(-H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==')
    check "/rtc through Caddy answers as the voice server does (401 without a ticket)" \
        test "$(status_of "$base/rtc" --http1.1 "${upgrade[@]}")" = 401
    check "the voice server's API is not public: POST /twirp/… reaches the app (404)" \
        test "$(status_of "$base/twirp/livekit.RoomService/ListRooms" -X POST -H 'Content-Type: application/json' -d '{}')" = 404
    check "app reaches the voice server on the internal network" \
        compose exec -T app curl -fsS --max-time 5 -o /dev/null http://voice:7880/

    config=$(compose exec -T voice cat /etc/livekit.yaml)
    stun=$(awk '/^ *stun_servers:/ { list = 1; next } list && /^ *- / { print $2; next } list { list = 0 }' <<<"$config" | tr '\n' ' ')
    check "its config gives the address (node_ip: $voice_ip, use_external_ip: false)" \
        bash -c 'grep -qE "^ +node_ip: $2\$" <<<"$1" && grep -qE "^ +use_external_ip: false\$" <<<"$1"' _ "$config" "$voice_ip"
    check "its stun_servers name that address only ($stun)" test "$stun" = "$voice_ip:7882 "
    check "its config has no webhook, TURN, Redis or tracing" \
        bash -c '! grep -qE "^(webhook|turn|redis|tracing|ingress|egress|signal_relay):" <<<"$1"' _ "$config"
    check "it makes no room on its own and unmutes nobody" \
        bash -c 'grep -qE "^ +auto_create: false\$" <<<"$1" && grep -qE "^ +enable_remote_unmute: false\$" <<<"$1"' _ "$config"
    check "it logged the address it was given ($voice_ip)" \
        bash -c 'docker compose logs voice | grep "using explicit node IP" | grep -q "\"V4\":\"$1\""' _ "$voice_ip"

    # A ticket, as the app will mint it (HS256 with the voice secret; ADR 030 decision 20).
    b64url() { openssl base64 -A | tr '+/' '-_' | tr -d '='; }
    voice_ticket() { # voice_ticket <claims, without iss/nbf/exp>
        local now head body
        now=$(date +%s)
        head=$(printf '{"alg":"HS256","typ":"JWT"}' | b64url)
        body=$(printf '{"iss":"%s","nbf":%d,"exp":%d,%s}' "$voice_key" "$((now - 5))" "$((now + 60))" "$1" | b64url)
        printf '%s.%s.%s' "$head" "$body" "$(printf '%s.%s' "$head" "$body" | openssl dgst -sha256 -hmac "$voice_secret" -binary | b64url)"
    }
    twirp() { # twirp <method> <claims> <json>: a call of the app's, from inside `app`
        compose exec -T app curl -sS --max-time 5 -X POST \
            -H "Authorization: Bearer $(voice_ticket "$2")" -H 'Content-Type: application/json' \
            -d "$3" "http://voice:7880/twirp/livekit.RoomService/$1" || true
    }
    rtc_join() { # rtc_join <ticket>: the status line and first frames of /rtc through Caddy
        curl -s -i --http1.1 --max-time 3 "${upgrade[@]}" -H "Origin: $base" \
            "$base/rtc?access_token=$1&protocol=16&sdk=js&version=2.24.1&auto_subscribe=1" |
            LC_ALL=C tr -c '[:print:]\n' '.' || true
    }

    room="elifba-smoke-$(date +%s)"
    check "app makes a voice room through the API" \
        grep -q "\"name\":\"$room\"" <<<"$(twirp CreateRoom '"video":{"roomCreate":true}' "{\"name\":\"$room\"}")"
    ticket=$(voice_ticket "\"sub\":\"smoke-listener\",\"video\":{\"room\":\"$room\",\"roomJoin\":true,\"canPublish\":false,\"canPublishData\":false}")
    join=$(rtc_join "$ticket")
    check "a ticket joins it through Caddy (101)" grep -q '^HTTP/1.1 101' <<<"$join"
    offered=$(grep -oE '(stun|turn)s?:' <<<"$join" | wc -l | tr -d ' ')
    own=$(grep -oF "stun:$voice_ip:7882" <<<"$join" | wc -l | tr -d ' ')
    check "its join response offers no ICE server but stun:$voice_ip:7882 ($own of $offered)" \
        test "$own" -ge 1 -a "$offered" = "$own"
    check "… and names neither Twilio nor Google" bash -c '! grep -qiE "twilio|google" <<<"$1"' _ "$join"
    nowhere=$(voice_ticket '"sub":"smoke-listener","video":{"room":"elifba-smoke-nowhere","roomJoin":true}')
    check "a ticket for a room the app did not make joins nothing (404)" \
        grep -q '^HTTP/1.1 404' <<<"$(rtc_join "$nowhere")"
    twirp DeleteRoom '"video":{"roomCreate":true}' "{\"room\":\"$room\"}" >/dev/null
    signature=${ticket##*.}
    check "neither Caddy nor the voice server logged the ticket" \
        bash -c '! docker compose logs proxy voice 2>&1 | grep -qF -e "$1" -e access_token=' _ "$signature"

    step "Live voice with no route out"
    image=$(compose config --images | grep -m 1 '^livekit/livekit-server:')
    offline=elifba-smoke-voice-offline
    docker rm -f "$offline" >/dev/null 2>&1 || true
    docker network rm "$offline" >/dev/null 2>&1 || true
    docker network create --internal "$offline" >/dev/null
    run_voice() { # run_voice <address> [docker run options...]: the service's entry point, alone
        local address=$1
        shift
        docker run --network "$offline" -e ELIFBA_ROOMS_VOICE_IP="$address" \
            -e ELIFBA_ROOMS_VOICE_KEY="$voice_key" -e ELIFBA_ROOMS_VOICE_SECRET="$voice_secret" \
            -v "$PWD/docker/voice:/etc/elifba-voice:ro" --entrypoint /bin/sh "$@" "$image" /etc/elifba-voice/start.sh
    }
    refused=$(run_voice '' --rm 2>&1 || true)
    check "the voice service refuses to start without its address" grep -q 'ELIFBA_ROOMS_VOICE_IP' <<<"$refused"
    run_voice "$voice_ip" -d --name "$offline" >/dev/null
    healthy=no
    for _ in $(seq 1 20); do
        if docker exec "$offline" wget -qO /dev/null http://127.0.0.1:7880/ 2>/dev/null; then healthy=yes; break; fi
        sleep 1
    done
    check "the network has no route out" bash -c '! docker exec "$1" wget -qO /dev/null -T 3 http://1.1.1.1/ 2>/dev/null' _ "$offline"
    check "the voice server is healthy there ($healthy)" test "$healthy" = yes
    check "… with the address it was given and no lookup of its own" \
        bash -c 'docker logs "$1" 2>&1 | grep -q "using explicit node IP" && ! docker logs "$1" 2>&1 | grep -qiE "stun|external ip"' _ "$offline"
    docker rm -f "$offline" >/dev/null
    docker network rm "$offline" >/dev/null
fi

step "The image"
check "no public/hot in the image" compose exec -T app test ! -e public/hot
check "no .env in the image" compose exec -T app test ! -e .env
check "public/content links to content/assets" compose exec -T app test -L public/content -a -d public/content/
check "content is compiled (content:cache)" compose exec -T app test -f bootstrap/cache/content.php

step "Scheduler"
schedule=$(compose exec -T scheduler php artisan schedule:list 2>&1 || true)
for task in classroom:prune-archived play:abandon-stale play:prune-old-games play:prune-guest-boards; do
    check "schedule:list has $task" grep -q "$task" <<<"$schedule"
done

step "Backup"
compose exec -T backup elifba-backup now
latest=$(compose exec -T backup sh -c 'ls -1t /backups/elifba-*.dump | head -n 1' | tr -d '\r')
check "a dump was written ($latest)" test -n "$latest"
check "the dump is a readable pg_dump archive" \
    bash -c 'docker compose exec -T backup pg_restore --list "$1" | grep -q "TABLE public migrations"' _ "$latest"

step "Data survives down and up"
psql() { compose exec -T db psql -U elifba -d elifba -tAc "$1"; }
psql "DELETE FROM cache WHERE key = 'elifba-docker-smoke'" >/dev/null
psql "INSERT INTO cache (key, value, expiration) VALUES ('elifba-docker-smoke', 'kept', 2147483647)" >/dev/null
compose down
compose up -d --wait --wait-timeout 300
check "the row written before down is there" test "$(psql "SELECT value FROM cache WHERE key = 'elifba-docker-smoke'")" = kept
check "the backup volume is there" compose exec -T backup test -s "$latest"
psql "DELETE FROM cache WHERE key = 'elifba-docker-smoke'" >/dev/null
check "/up answers 200 again" test "$(status_of "$base/up")" = 200

if [ "${ELIFBA_SMOKE_DOWN:-0}" = 1 ]; then
    step "Removing the stack and its volumes"
    compose down --volumes
fi

if [ "$failures" -gt 0 ]; then
    printf '\n%d check(s) failed.\n' "$failures"
    compose ps
    compose logs --tail 50
    exit 1
fi

printf '\nAll checks passed.\n'

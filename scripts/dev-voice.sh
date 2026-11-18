#!/bin/sh
# `composer dev:voice` (docs/architecture.md §10 "Live voice"): our voice
# server on this machine for development, with docker/voice/livekit.dev.yaml
# (a fixed development key, node_ip 127.0.0.1, its own STUN entry, never the
# bare --dev), and the five lines for .env. It runs in the foreground until
# Ctrl-C and removes its container then. `composer dev` runs beside it,
# unchanged; the image is the one docker-compose.yml pins.

set -eu

cd "$(dirname "$0")/.."

# The one pin lives in docker-compose.yml, where Dependabot reads it.
image=$(sed -nE 's/^[[:space:]]*image:[[:space:]]*(livekit\/livekit-server:[^[:space:]]+).*/\1/p' docker-compose.yml | head -n 1)
if [ -z "$image" ]; then
    echo "docker-compose.yml names no livekit/livekit-server image." >&2
    exit 1
fi

command -v docker >/dev/null || {
    echo "Docker is needed to run the voice server." >&2
    exit 1
}

config=docker/voice/livekit.dev.yaml
# The development key and secret: the one pair under `keys:`.
key=$(awk '/^keys:/ { inside = 1; next } inside && /^[[:space:]]+[^#[:space:]]/ { sub(/^[[:space:]]+/, ""); sub(/:.*/, ""); print; exit }' "$config")
secret=$(awk '/^keys:/ { inside = 1; next } inside && /^[[:space:]]+[^#[:space:]]/ { sub(/^[^:]*:[[:space:]]*/, ""); print; exit }' "$config")

cat <<EOF
The voice server for development ($image) on 127.0.0.1:7880,
media on 127.0.0.1 UDP 7882 and TCP 7881. Put these five lines in .env:

ELIFBA_ROOMS_VOICE=true
ELIFBA_ROOMS_VOICE_KEY=$key
ELIFBA_ROOMS_VOICE_SECRET=$secret
ELIFBA_ROOMS_VOICE_URL=ws://127.0.0.1:7880
ELIFBA_ROOMS_VOICE_API_URL=http://127.0.0.1:7880

A microphone needs a secure context: https://elifba.test in Chrome (it may
open ws://127.0.0.1) or http://localhost:8000. Ctrl-C stops the server.

EOF

exec docker run --rm --init --name elifba-voice-dev \
    -p 127.0.0.1:7880:7880 \
    -p 127.0.0.1:7881:7881 \
    -p 127.0.0.1:7882:7882/udp \
    -v "$PWD/$config:/etc/livekit.yaml:ro" \
    "$image" --config /etc/livekit.yaml

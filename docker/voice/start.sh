#!/bin/sh
# The `voice` service's entry point (docker-compose.yml, profile `voice`;
# docs/architecture.md §10 "Live voice"). It refuses to start without the
# instance's address, the key or the secret, writes the address into a copy of
# docker/voice/livekit.yaml and starts the voice server with it.
#
# Compose cannot refuse these itself: it reads every ${...} of the file before
# it looks at profiles, so a required value of this service would stop an
# instance without the profile too.

set -eu

missing=
[ -n "${ELIFBA_ROOMS_VOICE_IP:-}" ] || missing="$missing ELIFBA_ROOMS_VOICE_IP"
[ -n "${ELIFBA_ROOMS_VOICE_KEY:-}" ] || missing="$missing ELIFBA_ROOMS_VOICE_KEY"
[ -n "${ELIFBA_ROOMS_VOICE_SECRET:-}" ] || missing="$missing ELIFBA_ROOMS_VOICE_SECRET"
if [ -n "$missing" ]; then
    echo "voice: empty in .env:$missing. The voice profile needs the host's public IPv4 address, the key and the secret (scripts/docker-env.sh writes the last two)." >&2
    exit 1
fi

ip=$ELIFBA_ROOMS_VOICE_IP
if ! echo "$ip" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$'; then
    echo "voice: ELIFBA_ROOMS_VOICE_IP must be an IPv4 address, not a name." >&2
    exit 1
fi

sed "s/__ELIFBA_ROOMS_VOICE_IP__/$ip/g" /etc/elifba-voice/livekit.yaml >/etc/livekit.yaml

LIVEKIT_KEYS="$ELIFBA_ROOMS_VOICE_KEY: $ELIFBA_ROOMS_VOICE_SECRET"
export LIVEKIT_KEYS
unset ELIFBA_ROOMS_VOICE_KEY ELIFBA_ROOMS_VOICE_SECRET

exec /livekit-server --config /etc/livekit.yaml

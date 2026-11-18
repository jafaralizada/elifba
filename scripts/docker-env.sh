#!/bin/sh
# Writes `.env` for docker-compose.yml from .env.production.example, with the
# address given and fresh secrets (APP_KEY, Reverb's app id, key and secret,
# the database password, the voice server's key and secret). Needs only
# openssl, no PHP. Never overwrites an existing .env. Live voice stays off and
# its profile out. docs/architecture.md §10.
#
#   scripts/docker-env.sh elifba.example.az              # https on 443
#   scripts/docker-env.sh localhost http 8088            # a try-out on this machine
#   scripts/docker-env.sh 192.168.1.20 http 8088         # a try-out on the school network
#   scripts/docker-env.sh 192.168.1.20 https 8443        # https on the LAN, for a phone's microphone

set -eu

cd "$(dirname "$0")/.."

domain=${1:-}
scheme=${2:-https}
port=${3:-}

if [ -z "$domain" ]; then
    echo "usage: scripts/docker-env.sh <domain> [https|http] [port]" >&2
    exit 2
fi

# Caddy's port 80 serves only https redirects and certificate checks. An http
# try-out does not use it, so it goes to the next port and leaves 80 alone
# (often taken on a laptop). Neither does https on an IP address: Caddy signs
# that certificate with its own authority, which a phone trusts once
# (§10 "Live voice").
case "$scheme" in
    https)
        port=${port:-443}
        if echo "$domain" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$'; then
            redirect_port=$((port + 1))
        else
            redirect_port=80
        fi
        ;;
    http)
        port=${port:-8088}
        redirect_port=$((port + 1))
        ;;
    *)
        echo "scheme must be https or http, not '$scheme'" >&2
        exit 2
        ;;
esac

if [ -e .env ]; then
    echo ".env already exists; move it away first (it is not overwritten)." >&2
    exit 1
fi

command -v openssl >/dev/null || {
    echo "openssl is needed to make the secrets." >&2
    exit 1
}

awk \
    -v domain="$domain" \
    -v scheme="$scheme" \
    -v port="$port" \
    -v redirect_port="$redirect_port" \
    -v app_key="base64:$(openssl rand -base64 32)" \
    -v reverb_id="$(openssl rand -hex 4)" \
    -v reverb_key="$(openssl rand -hex 10)" \
    -v reverb_secret="$(openssl rand -hex 20)" \
    -v db_password="$(openssl rand -hex 20)" \
    -v voice_key="$(openssl rand -hex 8)" \
    -v voice_secret="$(openssl rand -hex 32)" \
    '
    /^ELIFBA_DOMAIN=/ { print "ELIFBA_DOMAIN=" domain; next }
    /^ELIFBA_SCHEME=/ { print "ELIFBA_SCHEME=" scheme; next }
    /^ELIFBA_PORT=/ { print "ELIFBA_PORT=" port; next }
    /^ELIFBA_REDIRECT_PORT=/ { print "ELIFBA_REDIRECT_PORT=" redirect_port; next }
    /^APP_KEY=/ { print "APP_KEY=" app_key; next }
    /^REVERB_APP_ID=/ { print "REVERB_APP_ID=" reverb_id; next }
    /^REVERB_APP_KEY=/ { print "REVERB_APP_KEY=" reverb_key; next }
    /^REVERB_APP_SECRET=/ { print "REVERB_APP_SECRET=" reverb_secret; next }
    /^DB_PASSWORD=/ { print "DB_PASSWORD=" db_password; next }
    /^ELIFBA_ROOMS_VOICE_KEY=/ { print "ELIFBA_ROOMS_VOICE_KEY=" voice_key; next }
    /^ELIFBA_ROOMS_VOICE_SECRET=/ { print "ELIFBA_ROOMS_VOICE_SECRET=" voice_secret; next }
    { print }
    ' .env.production.example > .env

chmod 600 .env

echo "Wrote .env for $scheme://$domain:$port."
echo "Fill ELIFBA_OPERATOR_NAME and ELIFBA_OPERATOR_EMAIL (and mail, for real teachers), then:"
echo "  docker compose up -d --build --wait"

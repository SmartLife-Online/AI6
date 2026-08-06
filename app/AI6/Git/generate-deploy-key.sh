#!/usr/bin/dash
set -eu

keygen_binary="$1"
shift

exec "$keygen_binary" -q -t ed25519 -N '' "$@"

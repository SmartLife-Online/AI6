#!/bin/sh
set -eu

unset GIT_SSH_COMMAND GIT_SSH SSH_AUTH_SOCK

: "${AI6_GIT_SSH_KEY:?AI6_GIT_SSH_KEY is required}"
: "${AI6_GIT_KNOWN_HOSTS:?AI6_GIT_KNOWN_HOSTS is required}"
ssh_binary=${AI6_GIT_SSH_BINARY:-/usr/bin/ssh}

[ -f "$AI6_GIT_SSH_KEY" ] && [ ! -L "$AI6_GIT_SSH_KEY" ] || exit 78
[ -f "$AI6_GIT_KNOWN_HOSTS" ] && [ ! -L "$AI6_GIT_KNOWN_HOSTS" ] || exit 78
[ -f "$ssh_binary" ] && [ ! -L "$ssh_binary" ] && [ -x "$ssh_binary" ] || exit 78

exec "$ssh_binary" \
    -F /dev/null \
    -o BatchMode=yes \
    -o PasswordAuthentication=no \
    -o KbdInteractiveAuthentication=no \
    -o PubkeyAuthentication=yes \
    -o IdentitiesOnly=yes \
    -o IdentityAgent=none \
    -o ForwardAgent=no \
    -o ClearAllForwardings=yes \
    -o PermitLocalCommand=no \
    -o ProxyCommand=none \
    -o StrictHostKeyChecking=yes \
    -o CheckHostIP=yes \
    -o VerifyHostKeyDNS=no \
    -o UpdateHostKeys=no \
    -o GlobalKnownHostsFile=/dev/null \
    -o "UserKnownHostsFile=$AI6_GIT_KNOWN_HOSTS" \
    -i "$AI6_GIT_SSH_KEY" \
    -- "$@"

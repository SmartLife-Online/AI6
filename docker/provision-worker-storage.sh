#!/bin/sh
set -eu

# Run as root with all persistent roles stopped. No application bootstrap or DB
# migration is needed, so this also prepares empty volumes for offline restore.
fail() {
    printf '%s\n' 'Worker storage provisioning refused: unsafe paths or conflicting credentials.' >&2
    exit 78
}

[ "$#" -ge 1 ] && [ "$#" -le 2 ] || fail
managed_root="$1"
mode="${2:-}"
case "$mode" in
    ''|--check-legacy|--relocate-credentials) ;;
    *) fail ;;
esac
case "$managed_root" in
    /*) ;;
    *) fail ;;
esac
case "$managed_root/" in
    *'/../'*|*'/./'*|*'//'*) fail ;;
esac
[ "$(id -u)" -eq 0 ] || fail
# Init checks this before provisioning any volume. Only an explicit maintenance
# invocation may move files whose database references still need relocation.
if [ "$mode" != '--relocate-credentials' ]; then
    for name in deploy-keys known_hosts; do
        if [ -e "$managed_root/$name" ] || [ -L "$managed_root/$name" ]; then
            printf '%s\n' 'worker_storage_legacy_credentials: README: Bestehende Credentials einmalig übernehmen' >&2
            exit 78
        fi
    done
fi
[ "$mode" != '--check-legacy' ] || exit 0
[ -d "$managed_root" ] && [ ! -L "$managed_root" ] || fail
[ "$(realpath -e -- "$managed_root")" = "$managed_root" ] || fail
[ "$(stat -c '%u:%g:%a' -- "$managed_root")" = '0:0:755' ] || fail

credentials="$managed_root/credentials"
backups="$managed_root/backups"
for directory in "$credentials" "$backups" "$managed_root/deploy-keys" "$credentials/deploy-keys"; do
    [ ! -L "$directory" ] || fail
    if [ -e "$directory" ]; then
        [ -d "$directory" ] || fail
    fi
done
for hosts in "$managed_root/known_hosts" "$credentials/known_hosts"; do
    [ ! -L "$hosts" ] || fail
    if [ -e "$hosts" ]; then
        [ -f "$hosts" ] && [ "$(stat -c '%h' -- "$hosts")" -eq 1 ] || fail
    fi
done
# Validate both transfers before any mutation. A rerun after either rename is
# safe; two existing copies require an operator decision, never an overwrite.
for name in deploy-keys known_hosts; do
    if [ -e "$managed_root/$name" ] && [ -e "$credentials/$name" ]; then
        fail
    fi
done
for tree in "$managed_root/deploy-keys" "$credentials"; do
    if [ -d "$tree" ]; then
        unsafe="$(find "$tree" \( ! -type d ! -type f \) -o \( -type f -links +1 \))"
        [ -z "$unsafe" ] || fail
    fi
done

umask 0077
mkdir -p "$credentials" "$backups"
chmod 0700 "$credentials" "$backups"
for name in deploy-keys known_hosts; do
    if [ -e "$managed_root/$name" ]; then
        mv -T -- "$managed_root/$name" "$credentials/$name"
    fi
done
mkdir -p "$credentials/deploy-keys"
chown -R 10001:10001 "$credentials"
chown 10001:10001 "$backups"
chmod 0700 "$credentials/deploy-keys"
if [ -f "$credentials/known_hosts" ]; then
    chmod 0600 "$credentials/known_hosts"
fi

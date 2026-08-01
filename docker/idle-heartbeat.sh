#!/bin/sh
set -eu

role="${1:-}"
directory="${AI6_HEARTBEAT_DIRECTORY:-}"
interval="${AI6_HEARTBEAT_INTERVAL:-5}"

case "$role" in
    agent|checker)
        ;;
    *)
        printf 'Unsupported idle role: %s\n' "$role" >&2
        exit 64
        ;;
esac

if [ "$directory" != "/run/ai6/heartbeat/$role" ] || [ ! -d "$directory" ]; then
    printf 'Invalid heartbeat directory for role %s.\n' "$role" >&2
    exit 78
fi

case "$interval" in
    ''|*[!0-9]*|0*)
        printf 'AI6_HEARTBEAT_INTERVAL must be a positive integer.\n' >&2
        exit 78
        ;;
esac

boot_id="$(sed -n '1{s/^\([0-9a-f][0-9a-f]*\)$/\1/p;}' "$directory/boot-id")"
if [ "${#boot_id}" -ne 32 ]; then
    printf 'Invalid container boot ID.\n' >&2
    exit 78
fi

while :; do
    recorded_at="$(date +%s)"
    printf '{"role":"%s","boot_id":"%s","recorded_at":%s}\n' "$role" "$boot_id" "$recorded_at" > "$directory/heartbeat.json.tmp"
    mv "$directory/heartbeat.json.tmp" "$directory/heartbeat.json"
    sleep "$interval"
done

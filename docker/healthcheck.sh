#!/bin/sh
set -eu

role="${1:-}"

check_file_heartbeat() {
    checked_role="$1"
    directory="${AI6_HEARTBEAT_DIRECTORY:-}"
    max_age="${AI6_HEARTBEAT_MAX_AGE:-}"

    if [ "$directory" != "/run/ai6/heartbeat/$checked_role" ]; then
        return 1
    fi

    case "$max_age" in
        ''|*[!0-9]*) return 1 ;;
    esac

    current_boot_id="$(sed -n '1{s/^\([0-9a-f][0-9a-f]*\)$/\1/p;}' "$directory/boot-id" 2>/dev/null || true)"
    heartbeat_boot_id="$(sed -n 's/.*"boot_id":"\([0-9a-f][0-9a-f]*\)".*/\1/p' "$directory/heartbeat.json" 2>/dev/null || true)"
    recorded_at="$(sed -n 's/.*"recorded_at":\([0-9][0-9]*\).*/\1/p' "$directory/heartbeat.json" 2>/dev/null || true)"

    if [ "${#current_boot_id}" -ne 32 ] || [ "$heartbeat_boot_id" != "$current_boot_id" ]; then
        return 1
    fi

    case "$recorded_at" in
        ''|*[!0-9]*) return 1 ;;
    esac

    now="$(date +%s)"
    age=$((now - recorded_at))
    [ "$age" -ge 0 ] && [ "$age" -le "$max_age" ]
}

case "$role" in
    app)
        exec curl --fail --silent --show-error --max-time 3 http://127.0.0.1:8080/health
        ;;
    worker)
        exec php /opt/ai6/artisan ai6:runtime-health --role=worker --no-interaction
        ;;
    scheduler)
        exec php /opt/ai6/artisan ai6:runtime-health --role=scheduler --no-interaction
        ;;
    agent)
        check_file_heartbeat agent
        ;;
    checker)
        check_file_heartbeat checker
        ;;
    *)
        printf 'Unknown healthcheck role: %s\n' "$role" >&2
        exit 64
        ;;
esac


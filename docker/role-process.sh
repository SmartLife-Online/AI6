#!/bin/sh
set -eu

role="${1:-}"
expected_directory="/run/ai6/heartbeat/$role"

case "$role" in
    worker|scheduler|agent|checker)
        ;;
    *)
        printf 'Unsupported durable role: %s\n' "$role" >&2
        exit 64
        ;;
esac

if [ "${AI6_HEARTBEAT_DIRECTORY:-}" != "$expected_directory" ] || [ ! -d "$expected_directory" ]; then
    printf 'Invalid heartbeat directory for role %s.\n' "$role" >&2
    exit 78
fi

umask 0077
boot_id="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
printf '%s\n' "$boot_id" > "$expected_directory/boot-id"

case "$role" in
    worker)
        worker_timeout="${AI6_WORKER_TIMEOUT:-60}"
        queue_retry_after="${DB_QUEUE_RETRY_AFTER:-360}"
        heartbeat_max_age="${AI6_HEARTBEAT_MAX_AGE:-75}"

        case "$worker_timeout" in
            ''|*[!0-9]*|0*)
                printf 'AI6_WORKER_TIMEOUT must be a positive base-10 integer.\n' >&2
                exit 78
                ;;
        esac

        case "$queue_retry_after" in
            ''|*[!0-9]*|0*)
                printf 'DB_QUEUE_RETRY_AFTER must be a positive base-10 integer.\n' >&2
                exit 78
                ;;
        esac

        case "$heartbeat_max_age" in
            ''|*[!0-9]*|0*)
                printf 'AI6_HEARTBEAT_MAX_AGE must be a positive base-10 integer.\n' >&2
                exit 78
                ;;
        esac

        if [ "${#worker_timeout}" -gt 18 ] || [ "${#queue_retry_after}" -gt 18 ] || [ "${#heartbeat_max_age}" -gt 18 ]; then
            printf 'Worker timing values must not exceed 18 digits.\n' >&2
            exit 78
        fi

        if [ "$queue_retry_after" -le "$worker_timeout" ]; then
            printf 'DB_QUEUE_RETRY_AFTER must be greater than AI6_WORKER_TIMEOUT.\n' >&2
            exit 78
        fi

        if [ "$heartbeat_max_age" -le "$worker_timeout" ]; then
            printf 'AI6_HEARTBEAT_MAX_AGE must be greater than AI6_WORKER_TIMEOUT.\n' >&2
            exit 78
        fi

        exec php /opt/ai6/artisan queue:work database --queue=default --sleep=2 "--timeout=$worker_timeout" --tries=3 --no-interaction
        ;;
    scheduler)
        exec php /opt/ai6/artisan schedule:work --no-interaction
        ;;
    agent)
        exec /opt/ai6/docker/idle-heartbeat.sh agent
        ;;
    checker)
        exec /opt/ai6/docker/idle-heartbeat.sh checker
        ;;
esac

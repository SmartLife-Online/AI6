#!/bin/sh
set -eu

role="${1:-}"

case "$role" in
    init)
        managed_root="${AI6_MANAGED_PROJECT_ROOT:-/var/lib/ai6/managed}"
        effect_lock_directory="${AI6_EFFECT_LOCK_DIRECTORY:-/var/lib/ai6/managed/effect-locks}"
        effect_lock_count="${AI6_EFFECT_LOCK_OBJECT_COUNT:-64}"
        effect_lock_owner_uid="${AI6_EFFECT_LOCK_OWNER_UID:-0}"
        if [ "$managed_root" != "/var/lib/ai6/managed" ] || [ "$effect_lock_directory" != "$managed_root/effect-locks" ]; then
            printf '%s\n' 'Managed root or effect-lock directory differs from the container contract.' >&2
            exit 78
        fi
        case "$effect_lock_count" in
            ''|*[!0-9]*|0*) printf '%s\n' 'AI6_EFFECT_LOCK_OBJECT_COUNT must be a positive integer.' >&2; exit 78 ;;
        esac
        if [ "${#effect_lock_count}" -gt 5 ] || [ "$effect_lock_count" -gt 10000 ]; then
            printf '%s\n' 'AI6_EFFECT_LOCK_OBJECT_COUNT exceeds its maximum.' >&2
            exit 78
        fi
        case "$effect_lock_owner_uid" in
            ''|*[!0-9]*) printf '%s\n' 'AI6_EFFECT_LOCK_OWNER_UID must be a non-negative integer.' >&2; exit 78 ;;
        esac
        mkdir -p /var/lib/ai6/database /opt/ai6/storage/app/private /opt/ai6/storage/app/public /opt/ai6/storage/framework/cache/data /opt/ai6/storage/framework/sessions /opt/ai6/storage/framework/testing /opt/ai6/storage/framework/views /opt/ai6/storage/logs
        mkdir -p "$managed_root/.control-staging" "$managed_root/deploy-keys" "$effect_lock_directory"
        chown root:root "$managed_root"
        chown "$effect_lock_owner_uid:0" "$effect_lock_directory"
        chmod 0755 "$managed_root"
        chmod 0555 "$effect_lock_directory"
        chown ai6:ai6 "$managed_root/.control-staging" "$managed_root/deploy-keys"
        chmod 0700 "$managed_root/.control-staging" "$managed_root/deploy-keys"
        lock_index=1
        original_umask="$(umask)"
        # A crash between object creation and the chmod below must never leave a
        # world/group-writable lock object behind: a restrictive umask makes the
        # object non-writable to group/other from the instant it is created, so
        # the later chmod 0444 only tightens an already-safe mode instead of being
        # the sole guard against a transient writable window.
        umask 0377
        while [ "$lock_index" -le "$effect_lock_count" ]; do
            lock_object="$effect_lock_directory/lock-$(printf '%04d' "$lock_index")"
            if [ -L "$lock_object" ] || { [ -e "$lock_object" ] && [ ! -f "$lock_object" ]; }; then
                printf '%s\n' 'An effect-lock object has an unsafe type.' >&2
                exit 78
            fi
            if [ ! -e "$lock_object" ]; then
                : > "$lock_object"
                chown "$effect_lock_owner_uid:0" "$lock_object"
                chmod 0444 "$lock_object"
            else
                lock_owner="$(stat -c '%u' -- "$lock_object")"
                lock_mode="$(stat -c '%a' -- "$lock_object")"
                if [ "$lock_owner" != "$effect_lock_owner_uid" ] || [ "$lock_mode" != "444" ]; then
                    printf '%s\n' 'An existing effect-lock object has unsafe ownership or mode.' >&2
                    exit 78
                fi
            fi
            lock_index=$((lock_index + 1))
        done
        umask "$original_umask"
        touch /var/lib/ai6/database/database.sqlite
        chown -R ai6:ai6 /var/lib/ai6/database /opt/ai6/storage
        chmod 0770 /var/lib/ai6/database /opt/ai6/storage
        chmod 0660 /var/lib/ai6/database/database.sqlite
        php /opt/ai6/artisan migrate --force --no-interaction
        chown -R ai6:ai6 /var/lib/ai6/database
        find /var/lib/ai6/database -maxdepth 1 -type f -exec chmod 0660 {} \;
        ;;
    app)
        exec apache2-foreground
        ;;
    worker)
        exec /opt/ai6/docker/role-process.sh worker
        ;;
    scheduler)
        exec /opt/ai6/docker/role-process.sh scheduler
        ;;
    agent)
        exec /opt/ai6/docker/role-process.sh agent
        ;;
    checker)
        exec /opt/ai6/docker/role-process.sh checker
        ;;
    *)
        printf 'Unknown AI6 role: %s\n' "$role" >&2
        exit 64
        ;;
esac

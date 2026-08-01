#!/bin/sh
set -eu

role="${1:-}"

case "$role" in
    init)
        mkdir -p /var/lib/ai6/database /opt/ai6/storage/app/private /opt/ai6/storage/app/public /opt/ai6/storage/framework/cache/data /opt/ai6/storage/framework/sessions /opt/ai6/storage/framework/testing /opt/ai6/storage/framework/views /opt/ai6/storage/logs
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

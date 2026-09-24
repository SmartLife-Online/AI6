ARG COMPOSER_IMAGE="composer:2.10.1@sha256:7725eb4545c438629ae8bde3ef0bb9a5038ef566126ad878442a69007242d267"
ARG PHP_IMAGE="php:8.5.5-apache-bookworm@sha256:e340b45ad8e72aa7addc22a991c2c66f424309f090695c63cdb2e005933a5c86"

FROM ${COMPOSER_IMAGE} AS composer

FROM ${PHP_IMAGE} AS runtime

ARG DEBIAN_SNAPSHOT="20260731T000000Z"
ARG SQLITE_ARCHIVE_VERSION="3530400"
ARG SQLITE_SHA3_256="454e45f61c6bd75b7420e7190732dea03ce6639c63ada47bbc592f67fc340338"
ARG SQLITE_VERSION="3.53.4"

LABEL org.opencontainers.image.title="AI6" \
      org.opencontainers.image.description="AI6 modular Laravel runtime" \
      org.opencontainers.image.version="php-8.5.5_sqlite-3.53.4" \
      org.opencontainers.image.base.name="php:8.5.5-apache-bookworm@sha256:e340b45ad8e72aa7addc22a991c2c66f424309f090695c63cdb2e005933a5c86" \
      ai6.sqlite.version="3.53.4" \
      ai6.sqlite.source.sha3-256="454e45f61c6bd75b7420e7190732dea03ce6639c63ada47bbc592f67fc340338" \
      ai6.debian.snapshot="20260731T000000Z"

ENV APP_ENV=production \
    APP_DEBUG=false \
    APACHE_DOCUMENT_ROOT=/opt/ai6/public \
    APACHE_LOCK_DIR=/tmp \
    APACHE_LOG_DIR=/tmp \
    APACHE_PID_FILE=/tmp/apache2.pid \
    APACHE_RUN_DIR=/tmp \
    AI6_GIT_EXECUTION_HOME=/var/lib/ai6/git-home \
    AI6_GIT_XDG_CONFIG_HOME=/var/lib/ai6/git-home/xdg \
    AI6_GIT_GLOBAL_CONFIG=/opt/ai6/etc/gitconfig \
    AI6_GIT_HOOKS_PATH=/opt/ai6/etc/git-hooks \
    LD_LIBRARY_PATH=/usr/local/lib \
    PATH=/opt/ai6/vendor/bin:${PATH}

RUN set -eux; \
    printf '%s\n' \
        "deb [check-valid-until=no] https://snapshot.debian.org/archive/debian/${DEBIAN_SNAPSHOT}/ bookworm main" \
        "deb [check-valid-until=no] https://snapshot.debian.org/archive/debian/${DEBIAN_SNAPSHOT}/ bookworm-updates main" \
        "deb [check-valid-until=no] https://snapshot.debian.org/archive/debian-security/${DEBIAN_SNAPSHOT}/ bookworm-security main" \
        > /etc/apt/sources.list.d/debian.sources.list; \
    rm -f /etc/apt/sources.list.d/debian.sources; \
    saved_apt_mark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends curl libicu-dev libonig-dev $PHPIZE_DEPS; \
    apt-get install -y --no-install-recommends git openssh-client procps util-linux bubblewrap; \
    curl --fail --location --retry 3 \
        --output /tmp/sqlite.tar.gz \
        "https://sqlite.org/2026/sqlite-autoconf-${SQLITE_ARCHIVE_VERSION}.tar.gz"; \
    php -r '$actual = hash_file("sha3-256", $argv[1]); if (! hash_equals($argv[2], $actual)) { fwrite(STDERR, "SQLite source digest mismatch.\n"); exit(1); }' \
        /tmp/sqlite.tar.gz "${SQLITE_SHA3_256}"; \
    mkdir /tmp/sqlite; \
    tar --extract --gzip --file /tmp/sqlite.tar.gz --directory /tmp/sqlite --strip-components=1; \
    cd /tmp/sqlite; \
    CFLAGS="-O2 -DSQLITE_ENABLE_COLUMN_METADATA" \
        ./configure --prefix=/usr/local --disable-static --enable-shared; \
    make -j"$(nproc)"; \
    make install; \
    ldconfig; \
    docker-php-ext-configure intl; \
    docker-php-ext-install -j"$(nproc)" intl mbstring pcntl; \
    sqlite3 --version | grep -E "^${SQLITE_VERSION} "; \
    php -r '$pdo = new PDO("sqlite::memory:"); $version = $pdo->query("select sqlite_version()")?->fetchColumn(); $metadata = $pdo->query("select sqlite_compileoption_used(\"ENABLE_COLUMN_METADATA\")")?->fetchColumn(); if ($version !== $argv[1] || (int) $metadata !== 1) { fwrite(STDERR, "Unexpected SQLite runtime or compile options.\n"); exit(1); }' "${SQLITE_VERSION}"; \
    apt-mark auto '.*' > /dev/null; \
    if [ -n "${saved_apt_mark}" ]; then apt-mark manual ${saved_apt_mark}; fi; \
    apt-mark manual curl libicu72 libonig5; \
    apt-mark manual git openssh-client procps util-linux bubblewrap; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/* /tmp/sqlite /tmp/sqlite.tar.gz; \
    curl --version > /dev/null; \
    for executable in /usr/bin/git /usr/bin/ssh /usr/bin/ssh-keygen /usr/bin/flock /usr/bin/stat /usr/bin/setsid /usr/bin/kill /usr/bin/unshare /usr/bin/mount /usr/bin/find /usr/bin/setpriv /usr/bin/dash; do test -x "$executable"; test ! -L "$executable"; done; \
    php -r 'foreach (["intl", "mbstring", "openssl"] as $extension) { if (! extension_loaded($extension)) { fwrite(STDERR, "Required PHP extension is missing.\n"); exit(1); } }'

# AGT-010: native transport pins and immutable download integrity. No installer
# execution, floating release, package-manager wrapper, or runtime self-update.
RUN set -eux; \
    test "$(dpkg --print-architecture)" = amd64; \
    curl --fail --location --retry 3 --proto '=https' --proto-redir '=https' \
        --output /tmp/ai6-codex.tar.gz \
        https://github.com/openai/codex/releases/download/rust-v0.129.0-alpha.15/codex-x86_64-unknown-linux-musl.tar.gz; \
    echo 'cbac890b22472413320625ff3dd47e78fac2c684df82dd55f8502c1a2b6455fa  /tmp/ai6-codex.tar.gz' | sha256sum --check --strict; \
    curl --fail --location --retry 3 --proto '=https' --proto-redir '=https' \
        --output /tmp/ai6-copilot.tar.gz \
        https://github.com/github/copilot-cli/releases/download/v1.0.83/copilot-linux-x64.tar.gz; \
    echo 'ffbe1c429664b8a05efed67ecdb467123e40fcaa3c6c14ef9a98ba74da4687b7  /tmp/ai6-copilot.tar.gz' | sha256sum --check --strict; \
    curl --fail --location --retry 3 --proto '=https' --proto-redir '=https' \
        --output /tmp/ai6-grok \
        https://x.ai/cli/grok-1.0.5-linux-x86_64; \
    echo '9ba87444e1819e8f6104adbbf4676a870c204380aa5c3e1c38a926c4ea677238  /tmp/ai6-grok' | sha256sum --check --strict; \
    install -m 0755 /tmp/ai6-grok /usr/local/bin/grok; \
    mkdir /tmp/ai6-cli; \
    tar -xzf /tmp/ai6-codex.tar.gz -C /tmp/ai6-cli codex-x86_64-unknown-linux-musl; \
    tar -xzf /tmp/ai6-copilot.tar.gz -C /tmp/ai6-cli copilot; \
    install -m 0755 /tmp/ai6-cli/codex-x86_64-unknown-linux-musl /usr/local/bin/codex; \
    install -m 0755 /tmp/ai6-cli/copilot /usr/local/bin/copilot; \
    rm -rf /tmp/ai6-cli /tmp/ai6-codex.tar.gz /tmp/ai6-copilot.tar.gz /tmp/ai6-grok


FROM runtime AS vendor

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /opt/ai6

COPY composer.json composer.lock ./

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip; \
    composer install \
        --no-autoloader \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false unzip; \
    rm -rf /var/lib/apt/lists/*

COPY app ./app

RUN composer dump-autoload \
        --classmap-authoritative \
        --no-dev \
        --no-interaction \
        --no-scripts

FROM runtime

WORKDIR /opt/ai6

COPY . .
COPY --from=vendor /opt/ai6/vendor ./vendor
COPY --chmod=0444 docker/apache-ports.conf /etc/apache2/ports.conf
COPY --chmod=0444 docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

RUN set -eux; \
    groupadd --gid 10001 ai6; \
    useradd --uid 10001 --gid ai6 --home-dir /nonexistent --no-create-home --shell /usr/sbin/nologin ai6; \
    sed -ri 's/^export APACHE_RUN_USER=.*/export APACHE_RUN_USER=ai6/' /etc/apache2/envvars; \
    sed -ri 's/^export APACHE_RUN_GROUP=.*/export APACHE_RUN_GROUP=ai6/' /etc/apache2/envvars; \
    mkdir -p \
        /opt/ai6/storage/app/private \
        /opt/ai6/storage/app/public \
        /opt/ai6/storage/framework/cache/data \
        /opt/ai6/storage/framework/sessions \
        /opt/ai6/storage/framework/testing \
        /opt/ai6/storage/framework/views \
        /opt/ai6/storage/logs \
        /var/lib/ai6/database \
        /var/lib/ai6/executions \
        /var/lib/ai6/git-home/cache \
        /var/lib/ai6/git-home/xdg \
        /opt/ai6/etc/git-hooks; \
    find /opt/ai6 -path /opt/ai6/storage -prune -o -type d -exec chmod 0555 {} +; \
    find /opt/ai6 -path /opt/ai6/storage -prune -o -type f -exec chmod 0444 {} +; \
    chmod 0555 /opt/ai6/artisan /opt/ai6/docker/*.sh /opt/ai6/bin/ai6-git-ssh.sh /opt/ai6/app/AI6/Git/generate-deploy-key.sh /opt/ai6/app/AI6/Shared/Process/control-process-wrapper.sh /opt/ai6/app/AI6/Shared/Process/checker-process-wrapper.sh; \
    chown -R ai6:ai6 /opt/ai6/storage /var/lib/ai6; \
    mkdir -p /opt/ai6/bootstrap/cache; \
    chown -R ai6:ai6 /opt/ai6/bootstrap/cache; \
    chmod 0770 /opt/ai6/bootstrap/cache; \
    runuser -u ai6 -- php artisan package:discover --ansi; \
    printf '%s\n' \
        '[core]' \
        '    hooksPath = /opt/ai6/etc/git-hooks' \
        '    fsmonitor = false' \
        '[credential]' \
        '    helper =' \
        '[commit]' \
        '    gpgSign = false' \
        '[tag]' \
        '    gpgSign = false' \
        '[submodule]' \
        '    recurse = false' \
        > /opt/ai6/etc/gitconfig; \
    find /opt/ai6 -path /opt/ai6/storage -prune -o -type d -exec chmod 0555 {} +; \
    find /opt/ai6 -path /opt/ai6/storage -prune -o -type f -exec chmod 0444 {} +; \
    chmod 0555 /opt/ai6/artisan /opt/ai6/docker/*.sh /opt/ai6/bin/ai6-git-ssh.sh /opt/ai6/app/AI6/Git/generate-deploy-key.sh /opt/ai6/app/AI6/Shared/Process/control-process-wrapper.sh /opt/ai6/app/AI6/Shared/Process/checker-process-wrapper.sh; \
    chmod 0555 /opt/ai6/etc /opt/ai6/etc/git-hooks; \
    chmod 0444 /opt/ai6/etc/gitconfig; \
    chmod 0700 /var/lib/ai6/git-home /var/lib/ai6/git-home/cache /var/lib/ai6/git-home/xdg; \
    chmod 0770 /opt/ai6/storage /var/lib/ai6/database /var/lib/ai6/executions

EXPOSE 8080

USER 10001:10001

ENTRYPOINT ["/opt/ai6/docker/entrypoint.sh"]
CMD ["app"]

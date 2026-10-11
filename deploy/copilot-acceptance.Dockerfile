# Operator overlay on the verified VPS runtime with the pinned native Copilot package.
# Validate AI6_RUNTIME_BASE against its recorded image digest before building.
ARG AI6_RUNTIME_BASE=ai6-upgrade:0600c8e-copilot-session-20261011
FROM composer:2.10.1@sha256:7725eb4545c438629ae8bde3ef0bb9a5038ef566126ad878442a69007242d267 AS composer
FROM ${AI6_RUNTIME_BASE}
ARG AI6_REVISION
LABEL org.opencontainers.image.revision=$AI6_REVISION
USER 0:0
COPY app/ /opt/ai6/app/
COPY config/ /opt/ai6/config/
COPY docs/ /opt/ai6/docs/
COPY tickets/ /opt/ai6/tickets/
COPY docker/ /opt/ai6/docker/
COPY README.md /opt/ai6/README.md
COPY --chmod=0555 app/AI6/Shared/Process/native-session-wrapper.sh /usr/local/lib/ai6/native-session-wrapper.sh
COPY --chmod=0444 app/AI6/Shared/Process/native-session-verify.php /usr/local/lib/ai6/native-session-verify.php
COPY --from=composer /usr/bin/composer /tmp/ai6-composer
RUN chmod -R u+w /opt/ai6/vendor/composer \
    && php /tmp/ai6-composer dump-autoload --classmap-authoritative --no-dev --no-interaction --no-scripts \
    && rm /tmp/ai6-composer \
    && find /opt/ai6/app /opt/ai6/config /opt/ai6/vendor /opt/ai6/docs /opt/ai6/tickets /opt/ai6/docker -type f -exec chmod 0444 {} + \
    && find /opt/ai6/app /opt/ai6/config /opt/ai6/vendor /opt/ai6/docs /opt/ai6/tickets /opt/ai6/docker -type d -exec chmod 0555 {} + \
    && chmod 0444 /opt/ai6/README.md \
    && chmod 0555 /opt/ai6/docker/*.sh /opt/ai6/app/AI6/Shared/Process/control-process-wrapper.sh /opt/ai6/app/AI6/Shared/Process/checker-process-wrapper.sh /opt/ai6/app/AI6/Git/generate-deploy-key.sh
USER 10001:10001

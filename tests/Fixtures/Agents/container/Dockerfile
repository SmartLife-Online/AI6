# Build AI6_RUNTIME_IMAGE with the repository's pinned root Dockerfile first.
ARG AI6_RUNTIME_IMAGE=ai6-native-runtime
FROM ${AI6_RUNTIME_IMAGE}
USER 0:0
RUN groupadd --gid 1000 ai6-test && useradd --uid 1000 --gid 1000 --home-dir /tmp --no-create-home --shell /usr/sbin/nologin ai6-test
COPY binaries/ /usr/local/lib/ai6-test-binaries/
RUN chmod -R a-w /usr/local/lib/ai6-test-binaries && chmod -R a+rx /usr/local/lib/ai6-test-binaries
USER 1000:1000

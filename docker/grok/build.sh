#!/bin/sh
# AI6-035: pinned public Grok source with the local sandbox storage correction.
set -eu

cd /build/grok
curl --fail --location --retry 3 --proto '=https' --proto-redir '=https' \
    --output /tmp/grok-source.tar.gz \
    https://codeload.github.com/xai-org/grok-build/tar.gz/37949780c144e37df692e3d669051a21fec24f20
echo 'e9d9791ce047da4c296cc7960a82cd7c2542029a80c9e3e0858cbd285879eff3  /tmp/grok-source.tar.gz' | sha256sum --check --strict
tar --extract --gzip --file /tmp/grok-source.tar.gz --strip-components=1
test "$(cat SOURCE_REV)" = c4ea71cfdbcdb21e32e41bc25a0043d7d4836714
patch --batch --fuzz=0 -p1 < /build/sandbox-work-dir.patch

# The same protoc archive pinned by upstream bin/protoc, without an installer.
curl --fail --location --retry 3 --proto '=https' --proto-redir '=https' \
    --output /tmp/grok-protoc.zip \
    https://github.com/protocolbuffers/protobuf/releases/download/v29.3/protoc-29.3-linux-x86_64.zip
echo '3e866620c5be27664f3d2fa2d656b5f3e09b5152b42f1bedbf427b333e90021a  /tmp/grok-protoc.zip' | sha256sum --check --strict
unzip -q /tmp/grok-protoc.zip -d /opt/protoc
export PROTOC=/opt/protoc/bin/protoc
export GROK_VERSION=1.0.24-ai6.1+37949780c144
export RUSTFLAGS='-C force-unwind-tables=yes -C link-arg=-Wl,-z,relro,-z,now,-z,noexecstack'
cargo build --locked -j 2 --profile release-dist -p xai-grok-pager-bin
install -m 0755 target/release-dist/xai-grok-pager /usr/local/bin/grok

mkdir -p /usr/local/share/licenses/grok
cp LICENSE THIRD-PARTY-NOTICES SOURCE_REV /usr/local/share/licenses/grok/
cp /build/sandbox-work-dir.patch /usr/local/share/licenses/grok/
printf '%s\n' 'AI6 local build 1.0.24-ai6.1+37949780c144.' \
    'Source: https://github.com/xai-org/grok-build/tree/37949780c144e37df692e3d669051a21fec24f20' \
    'Modified: sandbox storage paths in paths.rs, lib.rs and read_deny_verify.rs.' \
    'The accompanying patch retains native sandbox enforcement and verification.' \
    > /usr/local/share/licenses/grok/AI6-NOTICE

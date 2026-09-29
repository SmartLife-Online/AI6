#!/bin/bash
# Dedicated Linux test resources only; never mounts the Docker socket into a container.
set -euo pipefail
if [ "$#" -ne 3 ] || [ "$(id -u)" -ne 1000 ]; then
  echo 'Usage (uid 1000): bash run.sh SOURCE AI6_RUNTIME_IMAGE BASE_COMMIT' >&2
  exit 2
fi
source_input=$(realpath "$1")
runtime=$(sudo -n docker image inspect "$2" --format '{{.Id}}')
commit=$3
[[ "$runtime" =~ ^sha256:[a-f0-9]{64}$ && "$commit" =~ ^[a-f0-9]{40}$ ]]
test -f "$source_input/vendor/autoload.php"
test ! -e "$source_input/.env"
root=$(mktemp -d /tmp/ai6-native.XXXXXXXX)
identity=ai6-native-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')
echo "Evidence directory: $root"
mkdir -p "$root"/{source,work,bridge,harness,build/binaries,include/abstractions}
# Copy only reviewed source/dependencies; no local contexts, Git data, credentials or old logs.
tar -C "$source_input" --exclude='*.sqlite' --exclude='*.sqlite-*' --exclude='bootstrap/cache/*.php' \
  --exclude='.env' --exclude='.env.*' -cf - app bin bootstrap config database docker docs public resources routes scripts tests tickets vendor \
  artisan composer.json composer.lock phpunit.xml README.md Dockerfile | tar -C "$root/source" -xf -
source="$root/source"
mkdir -p "$source/deploy"
cp "$source_input/.env.example" "$source/.env.example"
cp "$source_input/docker-compose.yml" "$source/docker-compose.yml"
cp "$source_input/deploy/Caddyfile" "$source/deploy/Caddyfile"
mkdir -p "$source"/storage/{app/private,framework/cache/data,framework/sessions,framework/testing,framework/views,logs} "$source/bootstrap/cache"
fixture="$source/tests/Fixtures/Agents/container"
cp "$fixture"/*.php "$root/harness/"
cp "$fixture/Dockerfile" "$root/build/Dockerfile"
chmod 0444 "$source/app/AI6/Shared/Process/control-process-wrapper.sh" "$source/bin/ai6-git-ssh.sh"
cp "$source/docker/apparmor/ai6-execution" "$root/harness/ai6-execution"
cp "$source/docker/apparmor/ai6-container-base" "$root/include/abstractions/ai6-container-base"
# Test the shipped profile byte-for-byte; never reload the host's live profiles.
cmp "$source/docker/apparmor/ai6-execution" "$root/harness/ai6-execution"
sudo -n apparmor_parser -Q -K -I "$root/include" "$root/harness/ai6-execution"
supervisor=''
profile_loaded=false
cleanup() {
  touch "$root/bridge/stop"
  sudo -n docker rm -f "$identity-agent" "$identity-worker" >/dev/null 2>&1 || true
  if [ -n "$supervisor" ]; then wait "$supervisor" || true; fi
  for part in inputs outputs private; do sudo -n docker volume rm "$identity-$part" >/dev/null 2>&1 || true; done
  if $profile_loaded; then sudo -n apparmor_parser -R -n "$identity" -I "$root/include" "$root/harness/ai6-execution"; fi
}
trap cleanup EXIT
sudo -n apparmor_parser -a -K -n "$identity" -I "$root/include" "$root/harness/ai6-execution"
profile_loaded=true
sudo -n docker run --rm --network none --read-only --user 1000:1000 \
  -v "$source:/source:ro" -v "$root/harness:/harness:ro" -v "$root/build/binaries:/out" \
  --entrypoint php "$runtime" /source/tests/Fixtures/Agents/container/generate-binaries.php
sudo -n docker build --network none --build-arg "AI6_RUNTIME_IMAGE=$2" -t "$identity" "$root/build" > "$root/build.log" 2>&1
test "$runtime" = "$(sudo -n docker image inspect "$2" --format '{{.Id}}')"
image=$(sudo -n docker image inspect "$identity" --format '{{.Id}}')
printf '{"image":"%s","identity":"%s"}\n' "$image" "$identity" > "$root/harness/launcher.json"
for part in inputs outputs private; do
  sudo -n docker volume create --driver local --opt type=tmpfs --opt device=tmpfs \
    --opt 'o=size=128m,uid=1000,gid=1000,mode=0700,nosuid,nodev,noexec' "$identity-$part" >/dev/null
done
sudo -n docker run -d --rm --name "$identity-worker" --user 1000:1000 \
  --cpus 2 --memory 2g --network none --read-only --cap-drop ALL \
  --security-opt no-new-privileges:true --security-opt "apparmor=:$identity://ai6-agent-v1" \
  --security-opt systempaths=unconfined --security-opt "seccomp=$source/docker/agent-seccomp-moby-29.6.1.json" \
  --tmpfs /tmp:rw,nosuid,mode=1777 -e APP_ENV=testing -e HOME=/tmp -e TMPDIR=/work \
  -e AI6_NATIVE_PROVIDER_TEST_RUNNER=/test-harness/request-agent.php -e "AI6_NATIVE_PROVIDER_TEST_ID=$identity" \
  -v "$source:$source" -v "$root/work:/work" -v "$root/harness:/test-harness:ro" -v "$root/bridge:/test-control" \
  -v "$identity-inputs:/var/lib/ai6/agent-executions" -v "$identity-outputs:/var/lib/ai6/agent-outputs" \
  -v "$identity-private:/run/ai6/provider-private" --workdir "$source" --entrypoint /bin/sh "$image" -c 'exec sleep infinity' >/dev/null
sudo -n docker exec "$identity-worker" php /test-harness/volume-identities.php > "$root/harness/volume-identities.json"
php "$root/harness/host-supervisor.php" "$root" > "$root/supervisor.log" 2>&1 &
supervisor=$!
tests=(tests/Feature/Reviews/CopilotSecurityReviewTest.php tests/Feature/Shared/Doctor/GitHubCopilotCliDoctorCheckTest.php
  tests/Feature/Agents/GitHubCopilotCliExecutionTest.php tests/Feature/Reviews/FindingVerificationRoundTest.php
  tests/Unit/Agents/GitHubCopilotCliAdapterTest.php tests/Feature/Agents/GitHubCopilotCliSmokeTest.php
  tests/Unit/Shared/Runtime/RuntimeComposeContractTest.php)
command=(sudo -n docker exec "$identity-worker" php vendor/bin/phpunit "${tests[@]}" --testdox)
contract_command=(sudo -n docker exec "$identity-worker" php vendor/bin/phpunit tests/Feature/Agents/AgentExecutionMailboxTest.php
  --filter=test_runner_home_paths_match_the_shipped_apparmor_projection_contract --testdox)
{
  printf 'base_commit=%s\nruntime_image=%s\ntest_image=%s\n' "$commit" "$runtime" "$image"
  printf 'command='; printf '%q ' "${command[@]}"; printf '\n'
  printf 'contract_command='; printf '%q ' "${contract_command[@]}"; printf '\n'
  printf 'profile_sha256='; sha256sum "$root/harness/ai6-execution"
  sudo -n docker exec "$identity-worker" cat /proc/self/attr/current
} > "$root/evidence.txt"
(cd "$source" && find app bin bootstrap config database deploy docker docs public resources routes scripts tests tickets vendor \
  -type f ! -path 'bootstrap/cache/*' -print0 | sort -z | xargs -0 sha256sum
  sha256sum artisan composer.json composer.lock phpunit.xml README.md Dockerfile .env.example docker-compose.yml) > "$root/source.sha256"
sha256sum "$root/source.sha256" >> "$root/evidence.txt"
"${contract_command[@]}" > "$root/contract.log" 2>&1
printf 'contract_exit=0\n' >> "$root/evidence.txt"
set +e
sudo -n docker exec "$identity-worker" /usr/bin/bwrap --die-with-parent --unshare-user --unshare-pid \
  --unshare-ipc --unshare-uts --cap-drop ALL --new-session --ro-bind / / --proc /proc --dev /dev \
  --tmpfs /tmp -- /usr/bin/true > "$root/root-mount-negative.log" 2>&1
negative_result=$?
set -e
test "$negative_result" -ne 0
grep -F "Can't bind mount /oldroot/ on /newroot/" "$root/root-mount-negative.log"
grep -F 'Permission denied' "$root/root-mount-negative.log"
printf 'root_mount_refused_exit=%s\n' "$negative_result" >> "$root/evidence.txt"
set +e
"${command[@]}" > "$root/phpunit.log" 2>&1
result=$?
set -e
printf 'phpunit_exit=%s\n' "$result" >> "$root/evidence.txt"
tail -n 6 "$root/phpunit.log" | tee -a "$root/evidence.txt"
exit "$result"

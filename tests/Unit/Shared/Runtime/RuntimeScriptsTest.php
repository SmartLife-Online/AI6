<?php

namespace Tests\Unit\Shared\Runtime;

use PHPUnit\Framework\TestCase;

final class RuntimeScriptsTest extends TestCase
{
    public function test_entrypoint_has_six_fixed_role_branches_and_rejects_unknown_roles(): void
    {
        $script = $this->read('docker/entrypoint.sh');

        foreach (['init', 'app', 'worker', 'scheduler', 'agent', 'checker'] as $role) {
            self::assertSame(1, preg_match_all('/^    '.preg_quote($role, '/').'\)$/m', $script));
        }

        self::assertSame(1, substr_count($script, 'php /opt/ai6/artisan migrate'));
        self::assertStringNotContainsString('eval ', $script);
        self::assertMatchesRegularExpression('/\*\).*?exit 64/s', $script);

        foreach (['app', 'worker', 'scheduler', 'agent', 'checker'] as $role) {
            $branch = $this->branch($script, $role);
            self::assertSame(1, preg_match_all('/^        exec /m', $branch), $role.' must start exactly one argument list.');
            self::assertStringNotContainsString('migrate', $branch);
        }
    }

    public function test_init_preprovisions_immutable_effect_locks_without_replacing_existing_objects(): void
    {
        $branch = $this->branch($this->read('docker/entrypoint.sh'), 'init');

        self::assertStringContainsString('effect_lock_directory="${AI6_EFFECT_LOCK_DIRECTORY:-/var/lib/ai6/managed/effect-locks}"', $branch);
        self::assertStringContainsString('effect_lock_owner_uid="${AI6_EFFECT_LOCK_OWNER_UID:-0}"', $branch);
        self::assertStringContainsString('while [ "$lock_index" -le "$effect_lock_count" ]', $branch);
        self::assertStringContainsString('if [ ! -e "$lock_object" ]', $branch);
        self::assertStringContainsString(': > "$lock_object"', $branch);
        self::assertStringContainsString('chown "$effect_lock_owner_uid:0" "$lock_object"', $branch);
        self::assertStringContainsString('chmod 0444 "$lock_object"', $branch);
        self::assertStringContainsString("stat -c '%u' -- \"\$lock_object\"", $branch);
        self::assertStringContainsString("stat -c '%a' -- \"\$lock_object\"", $branch);
        self::assertLessThan(strpos($branch, "else\n                lock_owner="), strpos($branch, 'chown "$effect_lock_owner_uid:0" "$lock_object"'));
        self::assertStringNotContainsString('rm ', $branch);
        self::assertStringNotContainsString('mv ', $branch);
        self::assertLessThan(strpos($branch, 'php /opt/ai6/artisan migrate'), strpos($branch, 'chmod 0444 "$lock_object"'));

        $script = $this->read('docker/entrypoint.sh');
        foreach (['app', 'worker', 'scheduler', 'agent', 'checker'] as $role) {
            $roleBranch = $this->branch($script, $role);
            self::assertStringNotContainsString('effect_lock_directory', $roleBranch);
            self::assertStringNotContainsString('lock_object', $roleBranch);
            self::assertStringNotContainsString('AI6_EFFECT_LOCK_OBJECT_COUNT', $roleBranch);
        }

        $dockerfile = $this->read('Dockerfile');
        self::assertStringNotContainsString('COPY --chown=0:0', $dockerfile);
        self::assertStringNotContainsString('/var/lib/ai6/managed/effect-locks', $dockerfile);
    }

    public function test_agent_and_checker_healthchecks_do_not_start_php_or_artisan(): void
    {
        $script = $this->read('docker/healthcheck.sh');

        foreach (['agent', 'checker'] as $role) {
            $branch = $this->branch($script, $role);
            self::assertStringNotContainsString('php', strtolower($branch));
            self::assertStringNotContainsString('artisan', strtolower($branch));
            self::assertStringContainsString('check_file_heartbeat '.$role, $branch);
        }
    }

    public function test_worker_rejects_unsafe_timeout_and_retry_after_values_before_start(): void
    {
        $branch = $this->branch($this->read('docker/role-process.sh'), 'worker');

        self::assertStringContainsString('worker_timeout="${AI6_WORKER_TIMEOUT:-60}"', $branch);
        self::assertStringContainsString('queue_retry_after="${DB_QUEUE_RETRY_AFTER:-90}"', $branch);
        self::assertStringContainsString('heartbeat_max_age="${AI6_HEARTBEAT_MAX_AGE:-75}"', $branch);
        self::assertStringContainsString("''|*[!0-9]*|0*)", $branch);
        self::assertStringContainsString('[ "${#worker_timeout}" -gt 18 ]', $branch);
        self::assertStringContainsString('[ "${#queue_retry_after}" -gt 18 ]', $branch);
        self::assertStringContainsString('[ "${#heartbeat_max_age}" -gt 18 ]', $branch);
        self::assertStringContainsString('[ "$queue_retry_after" -le "$worker_timeout" ]', $branch);
        self::assertStringContainsString('DB_QUEUE_RETRY_AFTER must be greater than AI6_WORKER_TIMEOUT.', $branch);
        self::assertStringContainsString('[ "$heartbeat_max_age" -le "$worker_timeout" ]', $branch);
        self::assertStringContainsString('AI6_HEARTBEAT_MAX_AGE must be greater than AI6_WORKER_TIMEOUT.', $branch);
        self::assertLessThan(
            strpos($branch, 'exec php /opt/ai6/artisan queue:work'),
            strpos($branch, '[ "$queue_retry_after" -le "$worker_timeout" ]'),
        );
    }

    public function test_role_process_uses_one_fixed_argument_list_for_each_durable_role(): void
    {
        $script = $this->read('docker/role-process.sh');
        $commands = [
            'worker' => 'exec php /opt/ai6/artisan queue:work database --queue=default --sleep=2 "--timeout=$worker_timeout" --tries=3 --no-interaction',
            'scheduler' => 'exec php /opt/ai6/artisan schedule:work --no-interaction',
            'agent' => 'exec /opt/ai6/docker/idle-heartbeat.sh agent',
            'checker' => 'exec /opt/ai6/docker/idle-heartbeat.sh checker',
        ];

        self::assertStringNotContainsString('eval ', $script);
        self::assertStringNotContainsString('sh -c', $script);

        foreach ($commands as $role => $command) {
            $branch = $this->branch($script, $role);
            self::assertSame(1, preg_match_all('/^        exec /m', $branch));
            self::assertStringContainsString($command, $branch);
        }
    }

    public function test_role_process_has_no_test_only_service_override(): void
    {
        $script = $this->read('docker/role-process.sh');

        self::assertStringNotContainsString('AI6_TEST_', $script);
        self::assertStringNotContainsString('APP_ENV', $script);
        self::assertStringNotContainsString('sleep infinity', $script);
        self::assertSame(4, preg_match_all('/^        exec /m', $script));
        self::assertStringNotContainsString('sh -c', $script);
        self::assertStringNotContainsString('eval ', $script);
    }

    public function test_tc19_disables_the_producer_only_in_its_compose_override(): void
    {
        $smoke = $this->read('tests/Feature/Shared/Runtime/RuntimeComposeSmokeTest.php');
        $override = $this->decodeJson($this->path('tests/Feature/Shared/Runtime/Fixtures/agent-without-heartbeat.compose.json'));
        $agent = $override['services']['agent'] ?? null;
        self::assertIsArray($agent);

        self::assertStringContainsString('agentWithoutHeartbeatProducerOverridePath', $smoke);
        self::assertStringNotContainsString('AI6_TEST_DISABLE_HEARTBEAT_PRODUCER', $smoke);
        self::assertSame([], $agent['command'] ?? null);
        self::assertSame('/bin/sh', $agent['entrypoint'][0] ?? null);
        self::assertSame('-c', $agent['entrypoint'][1] ?? null);
        self::assertIsString($agent['entrypoint'][2] ?? null);
        self::assertStringContainsString('exec sleep infinity', $agent['entrypoint'][2]);
        self::assertStringNotContainsString('AI6_TEST_DISABLE_HEARTBEAT_PRODUCER', $agent['entrypoint'][2]);
        self::assertArrayNotHasKey('environment', $agent);
    }

    public function test_idle_heartbeat_producer_writes_role_boot_id_and_timestamp_atomically(): void
    {
        $script = $this->read('docker/idle-heartbeat.sh');

        self::assertMatchesRegularExpression('/^    agent\|checker\)$/m', $script);
        self::assertStringContainsString('if [ "$directory" != "/run/ai6/heartbeat/$role" ]', $script);
        self::assertStringContainsString("''|*[!0-9]*|0*)", $script);
        self::assertStringContainsString('boot_id="$(sed ', $script);
        self::assertStringContainsString('"role":"%s","boot_id":"%s","recorded_at":%s', $script);
        self::assertStringContainsString('> "$directory/heartbeat.json.tmp"', $script);
        self::assertStringContainsString('mv "$directory/heartbeat.json.tmp" "$directory/heartbeat.json"', $script);
        self::assertStringContainsString('sleep "$interval"', $script);
        self::assertStringNotContainsString('eval ', $script);
        self::assertStringNotContainsString('sh -c', $script);
    }

    public function test_image_and_sqlite_sources_are_pinned_and_code_runs_unprivileged(): void
    {
        $dockerfile = $this->read('Dockerfile');

        self::assertStringContainsString('php:8.5.5-apache-bookworm@sha256:e340b45a', $dockerfile);
        self::assertStringContainsString('composer:2.10.1@sha256:7725eb45', $dockerfile);
        self::assertStringContainsString('SQLITE_VERSION="3.53.4"', $dockerfile);
        self::assertStringContainsString('SQLITE_SHA3_256="454e45f61c6bd75b7420e7190732dea03ce6639c63ada47bbc592f67fc340338"', $dockerfile);
        self::assertStringContainsString('-DSQLITE_ENABLE_COLUMN_METADATA', $dockerfile);
        self::assertStringContainsString('sqlite_compileoption_used', $dockerfile);
        self::assertStringContainsString('DEBIAN_SNAPSHOT="20260731T000000Z"', $dockerfile);
        self::assertStringContainsString('apt-get install -y --no-install-recommends curl libicu-dev libonig-dev $PHPIZE_DEPS;', $dockerfile);
        self::assertStringContainsString('docker-php-ext-configure intl;', $dockerfile);
        self::assertStringContainsString('docker-php-ext-install -j"$(nproc)" intl mbstring pcntl;', $dockerfile);
        self::assertStringContainsString('apt-mark manual curl libicu72 libonig5;', $dockerfile);
        self::assertStringContainsString('foreach (["intl", "mbstring", "openssl"] as $extension)', $dockerfile);
        self::assertStringContainsString('extension_loaded($extension)', $dockerfile);
        self::assertStringContainsString('curl --version > /dev/null', $dockerfile);
        self::assertStringContainsString('composer install', $dockerfile);
        self::assertStringContainsString('FROM ${COMPOSER_IMAGE} AS composer', $dockerfile);
        self::assertStringContainsString('FROM ${PHP_IMAGE} AS runtime', $dockerfile);
        self::assertStringContainsString('FROM runtime AS vendor', $dockerfile);
        self::assertStringContainsString('COPY --from=composer /usr/bin/composer /usr/local/bin/composer', $dockerfile);
        self::assertStringContainsString('apt-get install -y --no-install-recommends unzip;', $dockerfile);
        self::assertStringContainsString('apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false unzip;', $dockerfile);
        self::assertStringContainsString('COPY --from=vendor /opt/ai6/vendor ./vendor', $dockerfile);
        self::assertStringContainsString('--no-scripts', $dockerfile);
        self::assertStringContainsString('USER 10001:10001', $dockerfile);
        self::assertStringContainsString('/usr/bin/ssh-keygen', $dockerfile);
        self::assertStringNotContainsString('/var/lib/ai6/managed \\', $dockerfile);
        self::assertStringNotContainsString('composer update', $dockerfile);
        self::assertStringNotContainsString('--ignore-platform-req', $dockerfile);
        self::assertStringContainsString('bootstrap/cache/*.php', $this->read('.dockerignore'));
    }

    public function test_locked_vendor_install_runs_against_the_extended_runtime_platform(): void
    {
        $dockerfile = $this->read('Dockerfile');
        $runtimeStage = strpos($dockerfile, 'FROM ${PHP_IMAGE} AS runtime');
        $extensionInstall = strpos($dockerfile, 'docker-php-ext-install -j"$(nproc)" intl mbstring pcntl;');
        $vendorStage = strpos($dockerfile, 'FROM runtime AS vendor');
        $composerCopy = strpos($dockerfile, 'COPY --from=composer /usr/bin/composer /usr/local/bin/composer');
        $unzipInstall = strpos($dockerfile, 'apt-get install -y --no-install-recommends unzip;');
        $composerInstall = strpos($dockerfile, 'composer install');
        $unzipPurge = strpos($dockerfile, 'apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false unzip;');
        $finalStage = strrpos($dockerfile, "\nFROM runtime\n");

        foreach ([$runtimeStage, $extensionInstall, $vendorStage, $composerCopy, $unzipInstall, $composerInstall, $unzipPurge, $finalStage] as $position) {
            self::assertIsInt($position);
        }

        self::assertLessThan($extensionInstall, $runtimeStage);
        self::assertLessThan($vendorStage, $extensionInstall);
        self::assertLessThan($composerCopy, $vendorStage);
        self::assertLessThan($unzipInstall, $composerCopy);
        self::assertLessThan($composerInstall, $unzipInstall);
        self::assertLessThan($unzipPurge, $composerInstall);
        self::assertLessThan($finalStage, $unzipPurge);
    }

    public function test_dockerignore_recursively_excludes_local_environment_files(): void
    {
        $dockerignore = $this->read('.dockerignore');

        self::assertStringContainsString("**/.env\n", $dockerignore);
        self::assertStringContainsString("**/.env.*\n", $dockerignore);
        self::assertStringContainsString("!.env.example\n", $dockerignore);
        self::assertStringNotContainsString("\n.env\n", "\n".$dockerignore);
        self::assertStringNotContainsString("\n.env.*\n", "\n".$dockerignore);
        self::assertLessThan(
            strpos($dockerignore, '!.env.example'),
            strpos($dockerignore, '**/.env.*'),
        );
    }

    public function test_dockerignore_excludes_non_runtime_repository_content(): void
    {
        $lines = file($this->path('.dockerignore'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        foreach ([
            'AGENTS.md', 'CLAUDE.md', 'README.md', 'ai', 'deploy', 'docs', 'docker-compose.yml',
            'phpstan.neon', 'phpunit.xml', 'pint.json', 'scripts', 'tests', 'ticket-prompt',
            'tickets', 'tools',
        ] as $excluded) {
            self::assertContains($excluded, $lines);
        }
    }

    public function test_composer_contract_and_installed_packages_match_the_ai6_005a_platform_contract(): void
    {
        self::assertSame('09d3c8abe5ae0fd01e8cab2a266ab87ecf648844ef72bd6ceeacd46348d5cea8', hash_file('sha256', $this->path('composer.json')));
        self::assertSame('3f0ac9af6a8b47de3895ae1585e0fb95abb9827f36d55dcd2fa7d6c1c1adb312', hash_file('sha256', $this->path('composer.lock')));

        $lock = $this->decodeJson($this->path('composer.lock'));
        self::assertSame('*', $lock['platform']['ext-intl'] ?? null);
        self::assertSame('*', $lock['platform']['ext-mbstring'] ?? null);
        self::assertSame('*', $lock['platform']['ext-openssl'] ?? null);
        $installed = $this->decodeJson($this->path('vendor/composer/installed.json'));
        $lockedNames = [];
        $lockedVersions = [];

        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $lockedNames[] = $package['name'];
                $lockedVersions[$package['name']] = $package['version'] ?? null;
            }
        }

        self::assertSame('v2.2.0', $lockedVersions['lbuchs/webauthn'] ?? null);
        self::assertSame('v9.0.0', $lockedVersions['pragmarx/google2fa'] ?? null);

        foreach ($installed['packages'] ?? [] as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null) || $package['name'] === 'ai6/ai6') {
                continue;
            }

            self::assertContains($package['name'], $lockedNames, $package['name'].' is installed but absent from composer.lock.');
        }
    }

    private function branch(string $script, string $role): string
    {
        $matched = preg_match('/^    '.preg_quote($role, '/').'\)\R(.*?)(?=^    (?:[a-z*]|\*)[^\r\n]*\)\R|^esac\R?)/ms', $script, $matches);
        self::assertSame(1, $matched, 'Missing branch for '.$role.'.');

        return $matches[1];
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $path): array
    {
        $contents = file_get_contents($path);
        self::assertNotFalse($contents);
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->path($path));
        self::assertNotFalse($contents);

        return $contents;
    }

    private function path(string $path): string
    {
        return dirname(__DIR__, 4).'/'.$path;
    }
}

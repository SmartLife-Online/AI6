<?php

namespace Tests\Unit\Shared\Runtime;

use App\AI6\Shared\Runtime\RuntimeHeartbeat;
use PHPUnit\Framework\TestCase;

final class RuntimeComposeContractTest extends TestCase
{
    /** @var array<string, list<string>> */
    private const ENVIRONMENT_ALLOWLIST = [
        'caddy' => [],
        'init' => [
            'APP_DEBUG', 'APP_ENV', 'DB_BUSY_TIMEOUT', 'DB_CONNECTION', 'DB_DATABASE',
            'DB_FOREIGN_KEYS', 'DB_JOURNAL_MODE', 'DB_SYNCHRONOUS',
        ],
        'app' => [
            'APP_DEBUG', 'APP_ENV', 'APP_KEY', 'APP_URL', 'CACHE_STORE', 'DB_BUSY_TIMEOUT',
            'DB_CONNECTION', 'DB_DATABASE', 'DB_FOREIGN_KEYS', 'DB_JOURNAL_MODE',
            'DB_SYNCHRONOUS', 'LOG_CHANNEL', 'QUEUE_CONNECTION', 'SESSION_DRIVER',
        ],
        'worker' => [
            'AI6_EXECUTION_DIRECTORY', 'AI6_HEARTBEAT_DIRECTORY', 'AI6_HEARTBEAT_MAX_AGE',
            'AI6_WORKER_TIMEOUT', 'APP_DEBUG', 'APP_ENV', 'CACHE_STORE', 'DB_BUSY_TIMEOUT',
            'DB_CONNECTION', 'DB_DATABASE', 'DB_FOREIGN_KEYS', 'DB_JOURNAL_MODE',
            'DB_QUEUE_RETRY_AFTER', 'DB_SYNCHRONOUS', 'LOG_CHANNEL', 'QUEUE_CONNECTION',
        ],
        'scheduler' => [
            'AI6_HEARTBEAT_DIRECTORY', 'AI6_HEARTBEAT_MAX_AGE', 'APP_DEBUG', 'APP_ENV',
            'CACHE_STORE', 'DB_BUSY_TIMEOUT', 'DB_CONNECTION', 'DB_DATABASE',
            'DB_FOREIGN_KEYS', 'DB_JOURNAL_MODE', 'DB_QUEUE_RETRY_AFTER',
            'DB_SYNCHRONOUS', 'LOG_CHANNEL', 'QUEUE_CONNECTION',
        ],
        'agent' => ['AI6_HEARTBEAT_DIRECTORY', 'AI6_HEARTBEAT_INTERVAL', 'AI6_HEARTBEAT_MAX_AGE'],
        'checker' => ['AI6_HEARTBEAT_DIRECTORY', 'AI6_HEARTBEAT_INTERVAL', 'AI6_HEARTBEAT_MAX_AGE'],
    ];

    /** @var array<string, list<string>> */
    private const MOUNT_ALLOWLIST = [
        'caddy' => ['bind:./deploy/Caddyfile:/etc/caddy/Caddyfile:ro'],
        'init' => [
            'tmpfs::/tmp:rw',
            'volume:ai6_database:/var/lib/ai6/database:rw',
            'volume:ai6_storage:/opt/ai6/storage:rw',
        ],
        'app' => [
            'tmpfs::/tmp:rw',
            'volume:ai6_database:/var/lib/ai6/database:rw',
            'volume:ai6_executions:/var/lib/ai6/executions:ro',
            'volume:ai6_storage:/opt/ai6/storage:rw',
        ],
        'worker' => [
            'tmpfs::/run/ai6/heartbeat/worker:rw',
            'tmpfs::/tmp:rw',
            'volume:ai6_database:/var/lib/ai6/database:rw',
            'volume:ai6_executions:/var/lib/ai6/executions:rw',
            'volume:ai6_storage:/opt/ai6/storage:rw',
        ],
        'scheduler' => [
            'tmpfs::/run/ai6/heartbeat/scheduler:rw',
            'tmpfs::/tmp:rw',
            'volume:ai6_database:/var/lib/ai6/database:rw',
            'volume:ai6_storage:/opt/ai6/storage:rw',
        ],
        'agent' => [
            'tmpfs::/run/ai6/heartbeat/agent:rw',
            'tmpfs::/tmp:rw',
        ],
        'checker' => [
            'tmpfs::/run/ai6/heartbeat/checker:rw',
            'tmpfs::/tmp:rw',
        ],
    ];

    public function test_services_ports_healthchecks_and_init_dependency_are_isolated(): void
    {
        $compose = $this->compose();
        $services = $compose['services'];
        self::assertIsArray($services);

        $names = array_keys($services);
        sort($names);
        self::assertSame(['agent', 'app', 'caddy', 'checker', 'init', 'scheduler', 'worker'], $names);

        $withPorts = [];
        foreach ($services as $name => $service) {
            self::assertIsArray($service);

            if (array_key_exists('ports', $service)) {
                $withPorts[] = $name;
            }

            if ($name === 'init') {
                self::assertArrayNotHasKey('healthcheck', $service);
                self::assertSame('no', $service['restart'] ?? null);

                continue;
            }

            self::assertArrayHasKey('healthcheck', $service, $name.' must be healthchecked.');
            self::assertSame('service_completed_successfully', $service['depends_on']['init']['condition'] ?? null);
        }

        self::assertSame(['caddy'], $withPorts);
        self::assertMatchesRegularExpression('/\A127\.0\.0\.1:/', $services['caddy']['ports'][0] ?? '');
        self::assertSame(
            ['CMD', 'wget', '--quiet', '--spider', 'http://127.0.0.1:8080/health'],
            $services['caddy']['healthcheck']['test'] ?? null,
        );
        self::assertSame('0:0', $services['init']['user'] ?? null);
    }

    public function test_all_ai6_services_share_one_image_and_proxy_is_digest_pinned(): void
    {
        $services = $this->services();
        $ai6Services = ['init', 'app', 'worker', 'scheduler', 'agent', 'checker'];
        $images = [];

        foreach ($ai6Services as $name) {
            $images[] = $services[$name]['image'] ?? null;
            self::assertSame(['context' => '.', 'dockerfile' => 'Dockerfile'], $services[$name]['build'] ?? null);
            self::assertTrue($services[$name]['read_only'] ?? false);
        }

        self::assertCount(1, array_unique($images, SORT_REGULAR));
        self::assertSame('ai6-runtime:php-8.5.5_sqlite-3.53.4', $images[0]);
        self::assertSame('${AI6_WORKER_HEARTBEAT_MAX_AGE:-75}', $services['worker']['environment']['AI6_HEARTBEAT_MAX_AGE'] ?? null);
        self::assertArrayNotHasKey('build', $services['caddy']);
        self::assertMatchesRegularExpression('/\Acaddy:2\.10\.2-alpine@sha256:[0-9a-f]{64}\z/D', $services['caddy']['image'] ?? '');
    }

    public function test_positive_environment_and_mount_allowlists_cover_all_services(): void
    {
        self::assertSame([], $this->allowlistErrors($this->compose()));

        $agentWithDatabase = $this->compose();
        $agentWithDatabase['services']['agent']['environment']['DB_DATABASE'] = '/var/lib/ai6/database/database.sqlite';
        self::assertNotSame([], $this->allowlistErrors($agentWithDatabase));

        $proxyWithStorage = $this->compose();
        $proxyWithStorage['services']['caddy']['volumes'][] = [
            'type' => 'volume',
            'source' => 'ai6_storage',
            'target' => '/opt/ai6/storage',
        ];
        self::assertNotSame([], $this->allowlistErrors($proxyWithStorage));

        $unknownService = $this->compose();
        $unknownService['services']['unexpected'] = [];
        self::assertNotSame([], $this->allowlistErrors($unknownService));
    }

    public function test_heartbeat_mounts_are_private_tmpfs_and_persistent_targets_are_named_volumes(): void
    {
        $compose = $this->compose();
        self::assertSame(['ai6_database', 'ai6_executions', 'ai6_storage'], $this->sortedKeys($compose['volumes'] ?? []));

        foreach (['worker', 'scheduler', 'agent', 'checker'] as $role) {
            $heartbeatMounts = array_values(array_filter(
                $compose['services'][$role]['volumes'] ?? [],
                static fn (mixed $mount): bool => is_array($mount)
                    && is_string($mount['target'] ?? null)
                    && str_starts_with($mount['target'], '/run/ai6/heartbeat/'),
            ));

            self::assertCount(1, $heartbeatMounts, $role.' must have exactly one heartbeat mount.');
            self::assertSame('tmpfs', $heartbeatMounts[0]['type'] ?? null);
            self::assertSame('/run/ai6/heartbeat/'.$role, $heartbeatMounts[0]['target'] ?? null);
            self::assertSame(
                $heartbeatMounts[0]['target'] ?? null,
                $compose['services'][$role]['environment']['AI6_HEARTBEAT_DIRECTORY'] ?? null,
                $role.' heartbeat environment must match its tmpfs target.',
            );
            self::assertSame(511, $heartbeatMounts[0]['tmpfs']['mode'] ?? null);
        }

        self::assertSame(
            RuntimeHeartbeat::WORKER_DIRECTORY,
            $compose['services']['worker']['environment']['AI6_HEARTBEAT_DIRECTORY'] ?? null,
        );

        foreach (['init', 'app', 'caddy'] as $role) {
            self::assertSame([], array_values(array_filter(
                $compose['services'][$role]['volumes'] ?? [],
                static fn (mixed $mount): bool => is_array($mount)
                    && is_string($mount['target'] ?? null)
                    && str_starts_with($mount['target'], '/run/ai6/heartbeat/'),
            )));
        }

        $foreignHeartbeat = $this->compose();
        $foreignHeartbeat['services']['agent']['volumes'][] = [
            'type' => 'tmpfs',
            'target' => '/run/ai6/heartbeat/worker',
        ];
        self::assertNotSame([], $this->allowlistErrors($foreignHeartbeat));

        $persistentHeartbeat = $this->compose();
        $persistentHeartbeat['services']['worker']['volumes'][3] = [
            'type' => 'volume',
            'source' => 'ai6_worker_heartbeat',
            'target' => '/run/ai6/heartbeat/worker',
        ];
        self::assertNotSame([], $this->allowlistErrors($persistentHeartbeat));
    }

    public function test_agent_checker_and_proxy_receive_no_ai6_data_or_application_key(): void
    {
        $services = $this->services();

        foreach (['agent', 'checker'] as $role) {
            $environment = $services[$role]['environment'] ?? [];
            self::assertIsArray($environment);
            self::assertArrayNotHasKey('APP_KEY', $environment);
            self::assertArrayNotHasKey('DB_DATABASE', $environment);

            foreach ($services[$role]['volumes'] ?? [] as $mount) {
                self::assertIsArray($mount);
                self::assertNotContains($mount['source'] ?? null, ['ai6_database', 'ai6_storage', 'ai6_executions']);
            }
        }

        self::assertArrayNotHasKey('environment', $services['caddy']);
        self::assertSame(['bind:./deploy/Caddyfile:/etc/caddy/Caddyfile:ro'], $this->mountSignatures($services['caddy']));
    }

    /** @param array<string, mixed> $compose
     * @return list<string>
     */
    private function allowlistErrors(array $compose): array
    {
        $errors = [];
        $services = $compose['services'] ?? null;

        if (! is_array($services)) {
            return ['services is missing'];
        }

        foreach ($services as $name => $service) {
            if (! is_string($name) || ! is_array($service)
                || ! array_key_exists($name, self::ENVIRONMENT_ALLOWLIST)
                || ! array_key_exists($name, self::MOUNT_ALLOWLIST)
            ) {
                $errors[] = 'missing allowlist for '.(string) $name;

                continue;
            }

            $environment = array_keys(is_array($service['environment'] ?? null) ? $service['environment'] : []);
            sort($environment);
            $expectedEnvironment = self::ENVIRONMENT_ALLOWLIST[$name];
            sort($expectedEnvironment);

            if ($environment !== $expectedEnvironment) {
                $errors[] = $name.' environment differs';
            }

            $mounts = $this->mountSignatures($service);
            $expectedMounts = self::MOUNT_ALLOWLIST[$name];
            sort($expectedMounts);

            if ($mounts !== $expectedMounts) {
                $errors[] = $name.' mounts differ';
            }
        }

        foreach (array_keys(self::ENVIRONMENT_ALLOWLIST) as $name) {
            if (! array_key_exists($name, $services)) {
                $errors[] = 'service '.$name.' is missing';
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $service
     * @return list<string>
     */
    private function mountSignatures(array $service): array
    {
        $signatures = [];

        foreach ($service['volumes'] ?? [] as $mount) {
            if (! is_array($mount)) {
                $signatures[] = 'invalid';

                continue;
            }

            $signatures[] = sprintf(
                '%s:%s:%s:%s',
                is_string($mount['type'] ?? null) ? $mount['type'] : '',
                is_string($mount['source'] ?? null) ? $mount['source'] : '',
                is_string($mount['target'] ?? null) ? $mount['target'] : '',
                ($mount['read_only'] ?? false) === true ? 'ro' : 'rw',
            );
        }

        sort($signatures);

        return $signatures;
    }

    /** @return array<string, mixed> */
    private function compose(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 4).'/docker-compose.yml');
        self::assertNotFalse($contents);
        $compose = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($compose);

        return $compose;
    }

    /** @return array<string, array<string, mixed>> */
    private function services(): array
    {
        $services = $this->compose()['services'] ?? null;
        self::assertIsArray($services);

        return $services;
    }

    /**
     * @return list<string>
     */
    private function sortedKeys(mixed $values): array
    {
        self::assertIsArray($values);
        $keys = array_keys($values);
        sort($keys);

        return $keys;
    }
}

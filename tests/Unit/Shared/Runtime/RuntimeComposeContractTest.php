<?php

namespace Tests\Unit\Shared\Runtime;

use App\AI6\Shared\Http\EnforceHttpsOrPrivateAccess;
use App\AI6\Shared\Runtime\RuntimeHeartbeat;
use App\AI6\Shared\Security\SecurityMeasure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\IpUtils;

final class RuntimeComposeContractTest extends TestCase
{
    /** @var list<string> */
    private const SECURITY_ENVIRONMENT = [
        'AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE',
        'AI6_SECURITY_LOGIN_EMAIL_CONFIRMATION',
        'AI6_SECURITY_PROFILE',
        'AI6_SECURITY_REQUIRE_AGENT_SANDBOX',
        'AI6_SECURITY_REQUIRE_CHECKER_NETWORK_ISOLATION',
        'AI6_SECURITY_REQUIRE_CRITICAL_ACTION_STEP_UP',
        'AI6_SECURITY_REQUIRE_HTTPS_OR_PRIVATE_ACCESS',
        'AI6_SECURITY_REQUIRE_LLM_PRECOMMIT_REVIEW',
        'AI6_SECURITY_REQUIRE_PRIVILEGED_PASSKEY',
    ];

    /** @var list<string> */
    private const REDACTION_ENVIRONMENT = [
        'AI6_REDACTION_ACTIVE_KEY_ID',
        'AI6_REDACTION_KEYS',
    ];

    /** @var list<string> */
    private const AUTH_ENVIRONMENT = [
        'AI6_AUTH_ENROLLMENT_TTL_SECONDS',
        'AI6_AUTH_LOGIN_CONFIRMATION_MAX_ATTEMPTS',
        'AI6_AUTH_LOGIN_CONFIRMATION_RESEND_COOLDOWN_SECONDS',
        'AI6_AUTH_LOGIN_CONFIRMATION_TTL_SECONDS',
        'AI6_AUTH_LOGIN_DECAY_SECONDS',
        'AI6_AUTH_LOGIN_MAX_ATTEMPTS',
        'AI6_AUTH_SESSION_LIFETIME_MINUTES',
        'AI6_AUTH_STRONG_AUTHENTICATION_DECAY_SECONDS',
        'AI6_AUTH_STRONG_AUTHENTICATION_MAX_ATTEMPTS',
        'AI6_AUTH_STEP_UP_WINDOW_SECONDS',
        'AI6_LOGIN_CONFIRMATION_EMAIL',
    ];

    /** @var list<string> */
    private const HTTP_ENVIRONMENT = [
        'AI6_HTTP_SESSION_SAME_SITE',
        'AI6_HTTP_TRUSTED_HOSTS',
        'AI6_HTTP_TRUSTED_PROXIES',
    ];

    /** @var list<string> */
    private const MAIL_ENVIRONMENT = [
        'MAIL_EHLO_DOMAIN',
        'MAIL_FROM_ADDRESS',
        'MAIL_FROM_NAME',
        'MAIL_HOST',
        'MAIL_MAILER',
        'MAIL_PASSWORD',
        'MAIL_PORT',
        'MAIL_SCHEME',
        'MAIL_URL',
        'MAIL_USERNAME',
    ];

    /** @var array<string, list<string>> */
    private const ENVIRONMENT_ALLOWLIST = [
        'caddy' => [],
        'init' => [
            ...self::SECURITY_ENVIRONMENT,
            'AI6_EFFECT_LOCK_DIRECTORY', 'AI6_EFFECT_LOCK_OBJECT_COUNT', 'AI6_EFFECT_LOCK_OWNER_UID',
            'AI6_MANAGED_PROJECT_ROOT',
            'AI6_RUNTIME_ROLE', 'APP_DEBUG', 'APP_ENV', 'DB_BUSY_TIMEOUT', 'DB_CONNECTION', 'DB_DATABASE',
            'DB_FOREIGN_KEYS', 'DB_JOURNAL_MODE', 'DB_SYNCHRONOUS',
        ],
        'app' => [
            ...self::AUTH_ENVIRONMENT,
            ...self::HTTP_ENVIRONMENT,
            ...self::SECURITY_ENVIRONMENT,
            ...self::REDACTION_ENVIRONMENT,
            'AI6_CONTROL_OPERATION_HEARTBEAT_SECONDS', 'AI6_CONTROL_OPERATION_LEASE_SECONDS', 'AI6_CONTROL_OPERATION_MANAGED_REF_ALLOWLIST',
            'AI6_CONTROL_OPERATION_STALE_SECONDS',
            'AI6_GIT_ALLOWED_HOSTS', 'AI6_GIT_ALLOWED_REMOTE_PATHS', 'AI6_GIT_ALLOWED_REF_PATTERNS', 'AI6_GIT_PINNED_HOST_KEYS',
            'AI6_RUNTIME_ROLE', 'APP_DEBUG', 'APP_ENV', 'APP_KEY', 'APP_NAME', 'APP_URL', 'CACHE_STORE', 'DB_BUSY_TIMEOUT',
            'DB_CONNECTION', 'DB_DATABASE', 'DB_FOREIGN_KEYS', 'DB_JOURNAL_MODE',
            'DB_SYNCHRONOUS', 'LOG_CHANNEL', 'QUEUE_CONNECTION', 'SESSION_DRIVER',
        ],
        'worker' => [
            ...self::MAIL_ENVIRONMENT,
            ...self::SECURITY_ENVIRONMENT,
            ...self::REDACTION_ENVIRONMENT,
            'AI6_CONTROL_OPERATION_HEARTBEAT_SECONDS', 'AI6_CONTROL_OPERATION_LEASE_SECONDS',
            'AI6_CONTROL_OPERATION_KNOWN_HOSTS_FILE', 'AI6_CONTROL_OPERATION_MANAGED_REF_ALLOWLIST',
            'AI6_CONTROL_OPERATION_MAX_ATTEMPTS', 'AI6_CONTROL_OPERATION_RECONCILIATION_BUDGET',
            'AI6_CONTROL_OPERATION_RECONCILER_SECONDS', 'AI6_CONTROL_OPERATION_STALE_SECONDS',
            'AI6_DEPLOY_KEY_ROOT', 'AI6_EFFECT_LOCK_DIRECTORY', 'AI6_EFFECT_LOCK_OBJECT_COUNT',
            'AI6_EFFECT_LOCK_OWNER_UID', 'AI6_MANAGED_PROJECT_ROOT', 'AI6_SSH_KEYGEN_BINARY',
            'AI6_AGENT_EXECUTION_ROOT', 'AI6_AGENT_OUTPUT_ROOT', 'AI6_CHECKER_EXECUTION_ROOT', 'AI6_CHECKER_OUTPUT_ROOT',
            'AI6_CODEX_CREDENTIAL_REVISION', 'AI6_GROK_CREDENTIAL_REVISION', 'AI6_COPILOT_CREDENTIAL_REVISION',
            'AI6_EXECUTION_DIRECTORY', 'AI6_GIT_ALLOWED_HOSTS', 'AI6_GIT_ALLOWED_REMOTE_PATHS', 'AI6_GIT_ALLOWED_REF_PATTERNS',
            'AI6_GIT_PINNED_HOST_KEYS', 'AI6_HEARTBEAT_DIRECTORY', 'AI6_HEARTBEAT_MAX_AGE', 'AI6_RUNTIME_ROLE',
            'AI6_WORKER_TIMEOUT', 'APP_DEBUG', 'APP_ENV', 'APP_KEY', 'CACHE_STORE', 'DB_BUSY_TIMEOUT',
            'DB_CONNECTION', 'DB_DATABASE', 'DB_FOREIGN_KEYS', 'DB_JOURNAL_MODE',
            'DB_QUEUE_RETRY_AFTER', 'DB_SYNCHRONOUS', 'LOG_CHANNEL', 'QUEUE_CONNECTION',
        ],
        'scheduler' => [
            ...self::SECURITY_ENVIRONMENT,
            ...self::REDACTION_ENVIRONMENT,
            'AI6_CONTROL_OPERATION_RECONCILER_SECONDS',
            'AI6_HEARTBEAT_DIRECTORY', 'AI6_HEARTBEAT_MAX_AGE', 'AI6_RUNTIME_ROLE', 'APP_DEBUG', 'APP_ENV',
            'CACHE_STORE', 'DB_BUSY_TIMEOUT', 'DB_CONNECTION', 'DB_DATABASE',
            'DB_FOREIGN_KEYS', 'DB_JOURNAL_MODE', 'DB_QUEUE_RETRY_AFTER',
            'DB_SYNCHRONOUS', 'LOG_CHANNEL', 'QUEUE_CONNECTION',
        ],
        'agent' => [...self::REDACTION_ENVIRONMENT, 'AI6_AGENT_EXECUTION_ROOT', 'AI6_AGENT_OUTPUT_ROOT', 'AI6_HEARTBEAT_DIRECTORY', 'AI6_HEARTBEAT_INTERVAL', 'AI6_HEARTBEAT_MAX_AGE', 'AI6_RUNTIME_ROLE', 'LOG_CHANNEL'],
        'checker' => [...self::REDACTION_ENVIRONMENT, 'AI6_CHECKER_EXECUTION_ROOT', 'AI6_CHECKER_OUTPUT_ROOT', 'AI6_HEARTBEAT_DIRECTORY', 'AI6_HEARTBEAT_INTERVAL', 'AI6_HEARTBEAT_MAX_AGE', 'AI6_RUNTIME_ROLE', 'LOG_CHANNEL'],
    ];

    /** @var array<string, list<string>> */
    private const MOUNT_ALLOWLIST = [
        'caddy' => ['bind:./deploy/Caddyfile:/etc/caddy/Caddyfile:ro'],
        'init' => [
            'tmpfs::/tmp:rw',
            'volume:ai6_database:/var/lib/ai6/database:rw',
            'volume:ai6_managed:/var/lib/ai6/managed:rw',
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
            'volume:ai6_agent_executions:/var/lib/ai6/agent-executions:rw',
            'volume:ai6_agent_outputs:/var/lib/ai6/agent-outputs:rw',
            'volume:ai6_checker_executions:/var/lib/ai6/checker-executions:rw',
            'volume:ai6_checker_outputs:/var/lib/ai6/checker-outputs:rw',
            'volume:ai6_executions:/var/lib/ai6/executions:rw',
            'volume:ai6_managed:/var/lib/ai6/managed:rw',
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
            'volume:ai6_agent_executions:/var/lib/ai6/agent-executions:ro',
            'volume:ai6_agent_outputs:/var/lib/ai6/agent-outputs:rw',
        ],
        'checker' => [
            'tmpfs::/run/ai6/heartbeat/checker:rw',
            'tmpfs::/tmp:rw',
            'volume:ai6_checker_executions:/var/lib/ai6/checker-executions:ro',
            'volume:ai6_checker_outputs:/var/lib/ai6/checker-outputs:rw',
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
        $caddyfile = file_get_contents(dirname(__DIR__, 4).'/deploy/Caddyfile');
        self::assertIsString($caddyfile);
        self::assertSame(1, substr_count($caddyfile, 'reverse_proxy app:8080'));
        self::assertSame(
            1,
            substr_count(
                $caddyfile,
                'header_up '.EnforceHttpsOrPrivateAccess::LOOPBACK_INGRESS_HEADER
                    .' '.EnforceHttpsOrPrivateAccess::LOOPBACK_INGRESS_VALUE,
            ),
        );
        self::assertStringNotContainsString('header_up X-Forwarded-For', $caddyfile);
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

    public function test_security_and_redaction_configuration_reaches_only_the_required_php_roles(): void
    {
        $services = $this->services();

        foreach (['init', 'app', 'worker', 'scheduler'] as $role) {
            $environment = $services[$role]['environment'] ?? [];
            self::assertIsArray($environment);
            self::assertSame($role, $environment['AI6_RUNTIME_ROLE'] ?? null);
            self::assertSame('${AI6_SECURITY_PROFILE:-strict}', $environment['AI6_SECURITY_PROFILE'] ?? null);
            self::assertSame(
                '${AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE:-false}',
                $environment['AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE'] ?? null,
            );

            foreach (SecurityMeasure::cases() as $measure) {
                self::assertSame('${'.$measure->value.':-true}', $environment[$measure->value] ?? null);
            }
        }

        foreach (['app', 'worker', 'scheduler', 'agent', 'checker'] as $role) {
            $environment = $services[$role]['environment'] ?? [];
            self::assertSame('${AI6_REDACTION_ACTIVE_KEY_ID:-app-key-v1}', $environment['AI6_REDACTION_ACTIVE_KEY_ID'] ?? null);
            self::assertSame('${AI6_REDACTION_KEYS:-}', $environment['AI6_REDACTION_KEYS'] ?? null);
        }

        foreach (['init'] as $role) {
            $environment = $services[$role]['environment'] ?? [];
            self::assertArrayNotHasKey('AI6_REDACTION_ACTIVE_KEY_ID', $environment);
            self::assertArrayNotHasKey('AI6_REDACTION_KEYS', $environment);
        }
    }

    public function test_authentication_and_mail_configuration_reaches_only_the_required_roles(): void
    {
        $services = $this->services();

        foreach (self::AUTH_ENVIRONMENT as $key) {
            self::assertArrayHasKey($key, $services['app']['environment']);

            foreach (['init', 'worker', 'scheduler', 'agent', 'checker', 'caddy'] as $role) {
                self::assertArrayNotHasKey($key, $services[$role]['environment'] ?? []);
            }
        }

        self::assertSame('${APP_KEY:-}', $services['worker']['environment']['APP_KEY'] ?? null);
        self::assertSame('${MAIL_MAILER:-smtp}', $services['worker']['environment']['MAIL_MAILER'] ?? null);

        foreach (self::MAIL_ENVIRONMENT as $key) {
            self::assertArrayHasKey($key, $services['worker']['environment']);

            foreach (['init', 'app', 'scheduler', 'agent', 'checker', 'caddy'] as $role) {
                self::assertArrayNotHasKey($key, $services[$role]['environment'] ?? []);
            }
        }
    }

    public function test_http_hardening_configuration_reaches_only_the_app_role(): void
    {
        $compose = $this->compose();
        $services = $this->services();
        $expected = [
            'AI6_HTTP_SESSION_SAME_SITE' => '${AI6_HTTP_SESSION_SAME_SITE:-lax}',
            'AI6_HTTP_TRUSTED_HOSTS' => '${AI6_HTTP_TRUSTED_HOSTS:-localhost,127.0.0.1,::1}',
            'AI6_HTTP_TRUSTED_PROXIES' => '${AI6_HTTP_TRUSTED_PROXIES:-172.30.61.2}',
        ];

        foreach ($expected as $key => $value) {
            self::assertSame($value, $services['app']['environment'][$key] ?? null);

            foreach (['init', 'worker', 'scheduler', 'agent', 'checker', 'caddy'] as $role) {
                self::assertArrayNotHasKey($key, $services[$role]['environment'] ?? []);
            }
        }

        $caddyAddress = $services['caddy']['networks']['proxy']['ipv4_address'] ?? null;
        $subnet = $compose['networks']['proxy']['ipam']['config'][0]['subnet'] ?? null;
        $dynamicRange = $compose['networks']['proxy']['ipam']['config'][0]['ip_range'] ?? null;
        self::assertSame(['proxy'], array_keys($services['caddy']['networks'] ?? []));
        self::assertSame(['default', 'proxy'], array_keys($services['app']['networks'] ?? []));
        foreach (['init', 'worker', 'scheduler', 'agent', 'checker'] as $role) {
            self::assertArrayNotHasKey('networks', $services[$role]);
        }
        self::assertSame('none', $services['checker']['network_mode'] ?? null);
        self::assertArrayNotHasKey('internal', $compose['networks']['proxy'] ?? []);
        self::assertSame('${AI6_PROXY_ADDRESS:-172.30.61.2}', $caddyAddress);
        self::assertSame('${AI6_PROXY_SUBNET:-172.30.61.0/29}', $subnet);
        self::assertSame('${AI6_PROXY_IP_RANGE:-172.30.61.4/30}', $dynamicRange);
        self::assertSame(
            '${AI6_SERVICE_SUBNET:-172.30.60.0/24}',
            $compose['networks']['default']['ipam']['config'][0]['subnet'] ?? null,
        );
        self::assertSame(
            '${AI6_SERVICE_IP_RANGE:-172.30.60.128/25}',
            $compose['networks']['default']['ipam']['config'][0]['ip_range'] ?? null,
        );
        self::assertTrue(IpUtils::checkIp('172.30.61.2', '172.30.61.0/29'));
        self::assertFalse(IpUtils::checkIp('172.30.61.2', '172.30.61.4/30'));
    }

    public function test_heartbeat_mounts_are_private_tmpfs_and_persistent_targets_are_named_volumes(): void
    {
        $compose = $this->compose();
        self::assertSame(['ai6_agent_executions', 'ai6_agent_outputs', 'ai6_checker_executions', 'ai6_checker_outputs', 'ai6_database', 'ai6_executions', 'ai6_managed', 'ai6_storage'], $this->sortedKeys($compose['volumes'] ?? []));

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

    public function test_effect_locks_exist_only_on_the_shared_managed_volume_with_unprivileged_workers(): void
    {
        $services = $this->services();
        self::assertSame('0:0', $services['init']['user'] ?? null);
        self::assertArrayNotHasKey('user', $services['worker']);
        self::assertArrayNotHasKey('privileged', $services['worker']);
        self::assertContains('volume:ai6_managed:/var/lib/ai6/managed:rw', $this->mountSignatures($services['init']));
        self::assertContains('volume:ai6_managed:/var/lib/ai6/managed:rw', $this->mountSignatures($services['worker']));
        self::assertSame(
            '/var/lib/ai6/managed/effect-locks',
            $services['init']['environment']['AI6_EFFECT_LOCK_DIRECTORY'] ?? null,
        );
        self::assertSame(
            '/var/lib/ai6/managed/effect-locks',
            $services['worker']['environment']['AI6_EFFECT_LOCK_DIRECTORY'] ?? null,
        );

        foreach ($services['worker']['volumes'] ?? [] as $mount) {
            self::assertIsArray($mount);
            if (($mount['type'] ?? null) === 'tmpfs') {
                self::assertFalse(str_starts_with('/var/lib/ai6/managed/effect-locks', (string) ($mount['target'] ?? '')));
            }
        }
        foreach (['app', 'scheduler', 'agent', 'checker', 'caddy'] as $role) {
            self::assertNotContains(
                'volume:ai6_managed:/var/lib/ai6/managed:rw',
                $this->mountSignatures($services[$role]),
                $role.' must not receive the managed effect-lock volume.',
            );
        }
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
                self::assertNotContains($mount['source'] ?? null, ['ai6_database', 'ai6_storage', 'ai6_executions', 'ai6_managed']);
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

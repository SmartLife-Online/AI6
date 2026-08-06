<?php

namespace Tests\Feature\Shared\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RuntimeComposeSmokeTest extends TestCase
{
    private int $port = 0;

    private string $project = '';

    private bool $started = false;

    private string $appKey = '';

    private string $redactionKeyring = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('AI6_RUN_COMPOSE_SMOKE') !== '1') {
            self::markTestSkipped('AI6_RUN_COMPOSE_SMOKE=1 ist nicht gesetzt; der reale Compose-Smoke bleibt sichtbar übersprungen.');
        }

        $docker = new Process(['docker', 'info', '--format', '{{json .ServerVersion}}']);
        $docker->setTimeout(20);
        self::assertSame(0, $docker->run(), 'Das Smoke-Flag ist gesetzt, aber Docker ist nicht verfügbar: '.$docker->getErrorOutput());

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertNotFalse($socket, $errorCode.': '.$errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $port = substr($address, strrpos($address, ':') + 1);
        self::assertMatchesRegularExpression('/\A[0-9]+\z/D', $port);

        $this->port = (int) $port;
        $this->project = 'ai6smoke'.bin2hex(random_bytes(5));
        $this->appKey = 'base64:'.base64_encode(random_bytes(32));
        $this->redactionKeyring = json_encode([
            'smoke-key-v1' => [
                'version' => 1,
                'key' => 'base64:'.base64_encode(random_bytes(32)),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        if ($this->started) {
            $down = $this->compose(['down', '--volumes', '--remove-orphans', '--timeout', '10'], 60);
            $down->run();
        }

        parent::tearDown();
    }

    public function test_real_stack_versions_health_scheduler_and_execution_mark(): void
    {
        $this->started = true;
        $up = $this->compose(['up', '-d', '--build'], 600);
        $up->mustRun();

        $this->waitForServiceState('init', static fn (string $state): bool => str_starts_with($state, 'exited|0|'), 120);

        foreach (['app', 'worker', 'scheduler', 'agent', 'checker', 'caddy'] as $service) {
            $this->waitForServiceState($service, static fn (string $state): bool => str_ends_with($state, '|healthy'), 180);
        }

        $this->assertManagedEffectLocksAreImmutableAcrossInit();
        $this->assertEffectLockSerializesAcrossWorkerInstances();

        foreach ([
            '/opt/ai6/AGENTS.md',
            '/opt/ai6/README.md',
            '/opt/ai6/ai',
            '/opt/ai6/deploy',
            '/opt/ai6/docs',
            '/opt/ai6/phpstan.neon',
            '/opt/ai6/phpunit.xml',
            '/opt/ai6/pint.json',
            '/opt/ai6/scripts',
            '/opt/ai6/tests',
            '/opt/ai6/ticket-prompt',
            '/opt/ai6/tickets',
            '/opt/ai6/tools',
        ] as $excludedPath) {
            $imageProbe = $this->compose(['exec', '-T', 'app', 'test', '!', '-e', $excludedPath], 30);
            $imageProbe->mustRun();
        }
        $exampleProbe = $this->compose(['exec', '-T', 'app', 'test', '-f', '/opt/ai6/.env.example'], 30);
        $exampleProbe->mustRun();

        $this->assertAgentRecreationRejectsPreviousBootHeartbeat();

        $health = false;
        $this->waitUntil(function () use (&$health): bool {
            $health = @file_get_contents('http://127.0.0.1:'.$this->port.'/health');

            return $health === '{"status":"ok"}';
        }, 30, 'Der HTTP-Health-Endpunkt wurde nach der Container-Neuerstellung nicht wieder bereit.');
        self::assertSame('{"status":"ok"}', $health);

        $first = $this->compose(['exec', '-T', 'app', 'php', 'artisan', 'ai6:runtime-selftest', 'smoke-manual'], 30);
        $first->mustRun();
        $second = $this->compose(['exec', '-T', 'app', 'php', 'artisan', 'ai6:runtime-selftest', 'smoke-manual'], 30);
        $second->mustRun();

        $runtimeProbe = $this->compose([
            'exec', '-T', 'app', 'php', '-r',
            'require "/opt/ai6/vendor/autoload.php"; $app = require "/opt/ai6/bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $pdo = $app->make("db")->connection("sqlite")->getPdo(); echo PHP_VERSION,"|",$pdo->query("select sqlite_version()")->fetchColumn(),"|",$pdo->query("pragma journal_mode")->fetchColumn(),"|",$pdo->query("pragma foreign_keys")->fetchColumn(),"|",$pdo->query("pragma busy_timeout")->fetchColumn(),"|",extension_loaded("intl") ? "intl" : "no-intl";',
        ], 30);
        $runtimeProbe->mustRun();
        self::assertMatchesRegularExpression('/\A8\.5\.\d+\|3\.53\.4\|wal\|1\|5000\|intl\z/iD', trim($runtimeProbe->getOutput()));

        $this->assertWorkerConfigurationFails(
            ['AI6_WORKER_TIMEOUT=0', 'DB_QUEUE_RETRY_AFTER=90'],
            'AI6_WORKER_TIMEOUT must be a positive base-10 integer.',
        );
        $this->assertWorkerConfigurationFails(
            ['AI6_WORKER_TIMEOUT=sixty', 'DB_QUEUE_RETRY_AFTER=90'],
            'AI6_WORKER_TIMEOUT must be a positive base-10 integer.',
        );
        $this->assertWorkerConfigurationFails(
            ['AI6_WORKER_TIMEOUT=60', 'DB_QUEUE_RETRY_AFTER=ninety'],
            'DB_QUEUE_RETRY_AFTER must be a positive base-10 integer.',
        );
        $this->assertWorkerConfigurationFails(
            ['AI6_WORKER_TIMEOUT=120', 'DB_QUEUE_RETRY_AFTER=90'],
            'DB_QUEUE_RETRY_AFTER must be greater than AI6_WORKER_TIMEOUT.',
        );
        $this->assertWorkerConfigurationFails(
            ['AI6_WORKER_TIMEOUT=60', 'DB_QUEUE_RETRY_AFTER=90', 'AI6_HEARTBEAT_MAX_AGE=0'],
            'AI6_HEARTBEAT_MAX_AGE must be a positive base-10 integer.',
        );
        $this->assertWorkerConfigurationFails(
            ['AI6_WORKER_TIMEOUT=60', 'DB_QUEUE_RETRY_AFTER=90', 'AI6_HEARTBEAT_MAX_AGE=60'],
            'AI6_HEARTBEAT_MAX_AGE must be greater than AI6_WORKER_TIMEOUT.',
        );

        $manualDigest = hash('sha256', 'smoke-manual');
        $this->waitUntil(function () use ($manualDigest): bool {
            $marks = $this->compose([
                'exec', '-T', 'worker', 'find', '/var/lib/ai6/executions', '-mindepth', '1', '-maxdepth', '1', '-type', 'f', '-printf', '%f\n',
            ], 30);

            if ($marks->run() !== 0) {
                return false;
            }

            $files = array_values(array_filter(preg_split('/\R/', trim($marks->getOutput())) ?: []));

            return in_array($manualDigest, $files, true) && count($files) === 2;
        }, 60, 'Genau ein Worker-Nachweis und ein bootgebundener Scheduler-Nachweis wurden nicht innerhalb der Frist sichtbar.');
    }

    private function assertManagedEffectLocksAreImmutableAcrossInit(): void
    {
        $directory = '/var/lib/ai6/managed/effect-locks';
        $directoryStat = $this->compose(['exec', '-T', 'worker', 'stat', '-c', '%u|%a|%F', $directory], 30);
        $directoryStat->mustRun();
        self::assertSame('0|555|directory', trim($directoryStat->getOutput()));

        $objects = $this->compose([
            'exec', '-T', 'worker', 'find', $directory, '-mindepth', '1', '-maxdepth', '1', '-type', 'f', '-printf', '%f|%U|%m\n',
        ], 30);
        $objects->mustRun();
        $lines = array_values(array_filter(preg_split('/\R/', trim($objects->getOutput())) ?: []));
        sort($lines);
        self::assertCount(64, $lines);
        foreach ($lines as $index => $line) {
            self::assertSame(sprintf('lock-%04d|0|444', $index + 1), $line);
        }

        $lock = $directory.'/lock-0001';
        $inodeBefore = $this->compose(['exec', '-T', 'worker', 'stat', '-c', '%d:%i', $lock], 30);
        $inodeBefore->mustRun();
        foreach ([
            ['exec', '-T', 'worker', 'rm', $lock],
            ['exec', '-T', 'worker', 'mv', $lock, '/tmp/replaced-lock'],
            ['exec', '-T', 'worker', 'touch', $directory.'/lock-0065'],
        ] as $forbidden) {
            self::assertNotSame(0, $this->compose($forbidden, 30)->run());
        }

        $rerun = $this->compose(['run', '--rm', '--no-deps', 'init'], 120);
        $rerun->mustRun();
        $inodeAfter = $this->compose(['exec', '-T', 'worker', 'stat', '-c', '%d:%i', $lock], 30);
        $inodeAfter->mustRun();
        self::assertSame(trim($inodeBefore->getOutput()), trim($inodeAfter->getOutput()));
    }

    private function assertEffectLockSerializesAcrossWorkerInstances(): void
    {
        $holder = $this->project.'-lock-holder';
        $contender = $this->project.'-lock-contender';
        $directory = '/var/lib/ai6/managed/.control-staging';
        $holderMarker = $directory.'/tc26-holder-ready';
        $contenderMarker = $directory.'/tc26-contender-acquired';
        $lock = '/var/lib/ai6/managed/effect-locks/lock-0001';
        $cleanupMarkers = $this->compose(['exec', '-T', 'worker', 'rm', '-f', $holderMarker, $contenderMarker], 30);
        $cleanupMarkers->mustRun();
        $inodeBefore = $this->compose(['exec', '-T', 'worker', 'stat', '-c', '%d:%i', $lock], 30);
        $inodeBefore->mustRun();

        try {
            $holderStart = $this->compose([
                'run', '-d', '--no-deps', '--name', $holder, '--entrypoint', 'php', 'worker', '-r',
                '$h=fopen("'.$lock.'","r"); if($h===false||!flock($h,LOCK_EX)){exit(41);} file_put_contents("'.$holderMarker.'","ready"); sleep(300);',
            ], 60);
            $holderStart->mustRun();
            $this->waitUntil(
                fn (): bool => $this->compose(['exec', '-T', 'worker', 'test', '-f', $holderMarker], 30)->run() === 0,
                20,
                'Die erste Workerinstanz hat den Effekt-Lock nicht erworben.',
            );

            $contenderStart = $this->compose([
                'run', '-d', '--no-deps', '--name', $contender, '--entrypoint', 'php', 'worker', '-r',
                '$h=fopen("'.$lock.'","r"); if($h===false||!flock($h,LOCK_EX)){exit(42);} file_put_contents("'.$contenderMarker.'","acquired"); sleep(300);',
            ], 60);
            $contenderStart->mustRun();
            usleep(500_000);
            $blocked = $this->compose(['exec', '-T', 'worker', 'test', '!', '-e', $contenderMarker], 30);
            $blocked->mustRun();

            foreach ([$holder, $contender] as $container) {
                $identity = new Process(['docker', 'inspect', '--format', '{{.Config.User}}', $container]);
                $identity->setTimeout(30);
                $identity->mustRun();
                self::assertSame('10001:10001', trim($identity->getOutput()));
            }

            $killHolder = new Process(['docker', 'kill', $holder]);
            $killHolder->setTimeout(30);
            $killHolder->mustRun();
            $this->waitUntil(
                fn (): bool => $this->compose(['exec', '-T', 'worker', 'test', '-f', $contenderMarker], 30)->run() === 0,
                20,
                'Die zweite Workerinstanz erwarb den Effekt-Lock nach dem abrupten Ende nicht.',
            );
            $inodeAfter = $this->compose(['exec', '-T', 'worker', 'stat', '-c', '%d:%i', $lock], 30);
            $inodeAfter->mustRun();
            self::assertSame(trim($inodeBefore->getOutput()), trim($inodeAfter->getOutput()));
        } finally {
            foreach ([$holder, $contender] as $container) {
                $remove = new Process(['docker', 'rm', '--force', $container]);
                $remove->setTimeout(30);
                $remove->run();
            }
            $this->compose(['exec', '-T', 'worker', 'rm', '-f', $holderMarker, $contenderMarker], 30)->run();
        }
    }

    /** @param list<string> $environment */
    private function assertWorkerConfigurationFails(array $environment, string $expectedError): void
    {
        $arguments = ['run', '--rm', '--no-deps'];

        foreach ($environment as $variable) {
            $arguments[] = '--env';
            $arguments[] = $variable;
        }

        $arguments[] = 'worker';
        $process = $this->compose($arguments, 30);
        self::assertNotSame(0, $process->run());
        self::assertStringContainsString($expectedError, $process->getOutput().$process->getErrorOutput());
    }

    private function assertAgentRecreationRejectsPreviousBootHeartbeat(): void
    {
        $previousContainerId = $this->serviceContainerId('agent');
        $previousBootId = $this->readServiceFile('agent', '/run/ai6/heartbeat/agent/boot-id');
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', $previousBootId);
        $overridePath = $this->agentWithoutHeartbeatProducerOverridePath();

        try {
            $recreate = $this->compose(
                ['up', '-d', '--no-deps', '--force-recreate', 'agent'],
                90,
                $overridePath,
            );
            $recreate->mustRun();
            $this->waitForServiceState('agent', static fn (string $state): bool => str_starts_with($state, 'running|'), 30);
            $this->waitUntil(function (): bool {
                $bootId = $this->compose(['exec', '-T', 'agent', 'test', '-s', '/run/ai6/heartbeat/agent/boot-id'], 30);

                return $bootId->run() === 0;
            }, 15, 'Die producerlose Agentinstanz hat keine Boot-ID erzeugt.');

            $currentContainerId = $this->serviceContainerId('agent');
            $currentBootId = $this->readServiceFile('agent', '/run/ai6/heartbeat/agent/boot-id');
            self::assertNotSame($previousContainerId, $currentContainerId);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', $currentBootId);
            self::assertNotSame($previousBootId, $currentBootId);

            $maxAge = $this->compose(['exec', '-T', 'agent', 'printenv', 'AI6_HEARTBEAT_MAX_AGE'], 30);
            $maxAge->mustRun();
            $maxAgeValue = trim($maxAge->getOutput());
            self::assertMatchesRegularExpression('/\A[1-9][0-9]*\z/D', $maxAgeValue);

            $heartbeatAbsent = $this->compose(
                ['exec', '-T', 'agent', 'test', '!', '-e', '/run/ai6/heartbeat/agent/heartbeat.json'],
                30,
            );
            $heartbeatAbsent->mustRun();
            $missingHeartbeat = $this->compose(
                ['exec', '-T', 'agent', '/opt/ai6/docker/healthcheck.sh', 'agent'],
                30,
            );
            self::assertNotSame(0, $missingHeartbeat->run());

            $recordedAt = time();
            $previousHeartbeat = json_encode([
                'role' => 'agent',
                'boot_id' => $previousBootId,
                'recorded_at' => $recordedAt,
            ], JSON_THROW_ON_ERROR)."\n";
            $writeHeartbeat = $this->compose(
                ['exec', '-T', 'agent', 'tee', '/run/ai6/heartbeat/agent/heartbeat.json'],
                30,
            );
            $writeHeartbeat->setInput($previousHeartbeat);
            $writeHeartbeat->mustRun();

            $previousBootHealth = $this->compose(
                ['exec', '-T', 'agent', '/opt/ai6/docker/healthcheck.sh', 'agent'],
                30,
            );
            self::assertNotSame(0, $previousBootHealth->run());
            self::assertLessThan((int) $maxAgeValue, time() - $recordedAt);
            $this->waitForServiceState('agent', static fn (string $state): bool => str_ends_with($state, '|unhealthy'), 90);
        } finally {
            $restoreProducer = $this->compose(['up', '-d', '--no-deps', '--force-recreate', 'agent'], 90);
            $restoreProducer->mustRun();
            $this->waitForServiceState('agent', static fn (string $state): bool => str_ends_with($state, '|healthy'), 60);
        }
    }

    private function agentWithoutHeartbeatProducerOverridePath(): string
    {
        $path = dirname(__DIR__, 4).'/tests/Feature/Shared/Runtime/Fixtures/agent-without-heartbeat.compose.json';
        self::assertFileExists($path);

        return $path;
    }

    private function serviceContainerId(string $service): string
    {
        $process = $this->compose(['ps', '--all', '--quiet', $service], 30);
        $process->mustRun();
        $containerId = trim($process->getOutput());
        self::assertNotSame('', $containerId);

        return $containerId;
    }

    private function readServiceFile(string $service, string $path): string
    {
        $process = $this->compose(['exec', '-T', $service, 'cat', $path], 30);
        $process->mustRun();

        return trim($process->getOutput());
    }

    /** @param list<string> $arguments */
    private function compose(array $arguments, int $timeout, ?string $overridePath = null): Process
    {
        $command = [
            'docker', 'compose',
            '--file', dirname(__DIR__, 4).'/docker-compose.yml',
        ];
        if ($overridePath !== null) {
            $command[] = '--file';
            $command[] = $overridePath;
        }
        $command[] = '--project-name';
        $command[] = $this->project;
        $command = array_merge($command, $arguments);
        $process = new Process($command, dirname(__DIR__, 4), [
            'AI6_HTTP_PORT' => (string) $this->port,
            'AI6_REDACTION_ACTIVE_KEY_ID' => 'smoke-key-v1',
            'AI6_REDACTION_KEYS' => $this->redactionKeyring,
            'APP_KEY' => $this->appKey,
        ]);
        $process->setTimeout($timeout);

        return $process;
    }

    /** @param callable(string): bool $predicate */
    private function waitForServiceState(string $service, callable $predicate, int $timeout): void
    {
        $this->waitUntil(function () use ($service, $predicate): bool {
            $idProcess = $this->compose(['ps', '--all', '--quiet', $service], 30);

            if ($idProcess->run() !== 0 || trim($idProcess->getOutput()) === '') {
                return false;
            }

            $inspect = new Process([
                'docker', 'inspect', '--format',
                '{{.State.Status}}|{{.State.ExitCode}}|{{if .State.Health}}{{.State.Health.Status}}{{end}}',
                trim($idProcess->getOutput()),
            ]);
            $inspect->setTimeout(30);

            return $inspect->run() === 0 && $predicate(trim($inspect->getOutput()));
        }, $timeout, 'Dienst '.$service.' erreichte den erwarteten Zustand nicht.');
    }

    /** @param callable(): bool $predicate */
    private function waitUntil(callable $predicate, int $timeout, string $message): void
    {
        $deadline = microtime(true) + $timeout;

        do {
            if ($predicate()) {
                return;
            }

            usleep(500_000);
        } while (microtime(true) < $deadline);

        self::fail($message);
    }
}

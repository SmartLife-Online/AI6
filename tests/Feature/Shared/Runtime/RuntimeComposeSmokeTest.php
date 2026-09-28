<?php

namespace Tests\Feature\Shared\Runtime;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Feature\Checks\BuildsCheckFixture;
use Tests\Feature\Tickets\TicketUiTestCase;
use Tests\Fixtures\Runtime\ExecutionRoleProtectedPaths;

final class RuntimeComposeSmokeTest extends TicketUiTestCase
{
    use BuildsCheckFixture;

    private int $port = 0;

    private string $project = '';

    private bool $started = false;

    private string $appKey = '';

    private string $redactionKeyring = '';

    /** @var array<string, string> */
    private array $networkEnvironment = [];

    private ?string $checkerSmokeDatabase = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('AI6_RUN_COMPOSE_SMOKE') !== '1') {
            self::markTestSkipped('AI6_RUN_COMPOSE_SMOKE=1 ist nicht gesetzt; der reale Compose-Smoke bleibt sichtbar übersprungen.');
        }

        $docker = new Process(['docker', 'info', '--format', '{{json .ServerVersion}}']);
        $docker->setTimeout(20);
        self::assertSame(0, $docker->run(), 'Das Smoke-Flag ist gesetzt, aber Docker ist nicht verfügbar: '.$docker->getErrorOutput());

        $this->networkEnvironment = $this->isolatedNetworkEnvironment();

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
        if (is_string($this->checkerSmokeDatabase) && is_file($this->checkerSmokeDatabase)) {
            unlink($this->checkerSmokeDatabase);
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

        $this->assertUnconfinedRolesFailClosed();
        $this->assertAgentPresenceFailureIsContained();
        $this->assertMailboxAndIsolationBoundaries();

        $this->assertManagedEffectLocksAreImmutableAcrossInit();
        $this->assertEffectLockSerializesAcrossWorkerInstances();

        foreach ([
            '/opt/ai6/AGENTS.md',
            '/opt/ai6/README.md',
            '/opt/ai6/ai',
            '/opt/ai6/deploy',
            '/opt/ai6/phpstan.neon',
            '/opt/ai6/phpunit.xml',
            '/opt/ai6/pint.json',
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

        $docsProbe = $this->compose([
            'exec', '-T', 'app', 'find', '/opt/ai6/docs', '-mindepth', '1', '-maxdepth', '1', '-type', 'f', '-printf', '%f\n',
        ], 30);
        $docsProbe->mustRun();
        $docs = array_values(array_filter(preg_split('/\R/', trim($docsProbe->getOutput())) ?: []));
        sort($docs);
        self::assertSame(['AI6_IMPLEMENTATION_PLAN.md', 'AI6_TICKET_MANIFEST.yaml'], $docs);

        $scriptsProbe = $this->compose([
            'exec', '-T', 'app', 'find', '/opt/ai6/scripts', '-mindepth', '1', '-maxdepth', '1', '-type', 'f', '-printf', '%f\n',
        ], 30);
        $scriptsProbe->mustRun();
        $scripts = array_values(array_filter(preg_split('/\R/', trim($scriptsProbe->getOutput())) ?: []));
        sort($scripts);
        self::assertSame(['generate-ticket-manifest.php'], $scripts);

        $doctorProbe = $this->compose(['exec', '-T', 'worker', 'php', 'artisan', 'ai6:doctor'], 30);
        $doctorProbe->run();
        self::assertStringContainsString('Ticketmanifest: OK', $doctorProbe->getOutput().$doctorProbe->getErrorOutput());

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

    private function assertUnconfinedRolesFailClosed(): void
    {
        $image = $this->compose(['images', '-q', 'agent'], 30);
        $image->mustRun();
        $imageId = trim($image->getOutput());
        self::assertMatchesRegularExpression('/\A(?:sha256:)?[a-f0-9]{12,64}\z/D', $imageId);
        // No host mounts, credentials, network or capabilities: only a disposable
        // instance of the test image and fresh tmpfs. Read the real kernel label.
        $code = <<<'PHP'
require '/opt/ai6/vendor/autoload.php';
$app = require '/opt/ai6/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$probe = new App\AI6\Shared\Process\NativeProcessRuntimeProbe;
$label = file_get_contents('/proc/self/attr/current');
if ($label !== "unconfined\n" && $label !== 'unconfined') { throw new RuntimeException('Unexpected negative-test label'); }
foreach (App\AI6\Shared\Process\ExecutionRole::cases() as $role) {
    if ($probe->apparmorConfined($role)) { throw new RuntimeException('Unconfined role accepted'); }
}
mkdir('/tmp/presence', 0755);
mkdir('/run/ai6/heartbeat/agent', 0700, true);
$boot = str_repeat('a', 32);
file_put_contents('/run/ai6/heartbeat/agent/boot-id', $boot);
putenv('AI6_HEARTBEAT_DIRECTORY=/run/ai6/heartbeat/agent');
putenv('AI6_HEARTBEAT_INTERVAL=1');
config(['ai6.runtime_role' => 'agent', 'ai6.provider_onboarding.presence_root' => '/tmp/presence']);
$exit = Illuminate\Support\Facades\Artisan::call('ai6:execution-mailbox', ['role' => 'agent', '--once' => true]);
if ($exit !== 1 || !str_contains(Illuminate\Support\Facades\Artisan::output(), 'The agent AppArmor confinement is unavailable.')) {
    throw new RuntimeException('Unconfined agent mailbox did not fail closed');
}
foreach (['start', 'pulse'] as $operation) {
    try {
        $app->make(App\AI6\Agents\ProviderCapabilityPublisher::class)->{$operation}($boot);
        throw new RuntimeException('Unconfined agent published presence');
    } catch (App\AI6\Shared\Process\ProcessStartRejectedException $expected) {
        if ($expected->getMessage() !== 'The agent AppArmor confinement is unavailable.') { throw $expected; }
    }
}
if (glob('/tmp/presence/*') !== [] || file_exists('/run/ai6/heartbeat/agent/heartbeat.json')) {
    throw new RuntimeException('Unconfined agent wrote presence or heartbeat');
}
config(['ai6.runtime_role' => 'checker']);
$promises = $probe->checkerRuntimePromises();
if (array_keys(array_filter($promises, static fn ($value) => !$value)) !== ['apparmor_confined']) {
    throw new RuntimeException('Negative fixture must fail only the real AppArmor promise: '.json_encode($promises));
}
$app->make(App\AI6\Checks\CheckerRuntimeAttestation::class)->publish($boot);
$doctor = (new App\AI6\Shared\Doctor\CheckerRuntimeDoctorCheck)->run();
if ($doctor->passed || $doctor->details !== ['Fehler' => 'checker_attestation_apparmor_confined']) {
    throw new RuntimeException('Doctor accepted the unconfined checker');
}
mkdir('/var/lib/ai6/checker-outputs/result');
mkdir('/var/lib/ai6/checker-outputs/artifact');
$marker = '/var/lib/ai6/checker-workspace/program-started';
$result = $app->make(App\AI6\Shared\Process\ControlProcessRunner::class)->run(new App\AI6\Shared\Process\ProcessRequest(
    [PHP_BINARY, '-r', 'file_put_contents($argv[1], "started");', $marker],
    '/var/lib/ai6/checker-workspace', [], [],
    new App\AI6\Shared\Redaction\RedactionContext('checker', null, 'apparmor-negative'),
    policy: App\AI6\Shared\Process\ProcessPolicyName::CHECKER,
    resultDirectory: '/var/lib/ai6/checker-outputs/result',
    artifactDirectory: '/var/lib/ai6/checker-outputs/artifact',
));
if ($result->outcome !== App\AI6\Shared\Process\ProcessOutcome::START_REJECTED
    || !str_contains($result->errorOutput, 'apparmor_confined') || file_exists($marker)) {
    throw new RuntimeException('Unconfined checker was not rejected before program start');
}
echo 'unconfined: agent-presence-blocked, agent-heartbeat-blocked, checker-start-blocked, doctor-red';
PHP;
        $negative = new Process([
            'docker', 'run', '--rm', '--network', 'none', '--read-only', '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges:true', '--security-opt', 'apparmor=unconfined',
            '--security-opt', 'systempaths=unconfined', '--user', '10002:10001',
            '--tmpfs', '/tmp:rw,nosuid,nodev,noexec,mode=1777',
            '--tmpfs', '/run:rw,nosuid,nodev,noexec,mode=1777',
            '--tmpfs', '/var/lib/ai6/checker-executions:ro,nosuid,nodev,noexec,mode=755',
            '--tmpfs', '/var/lib/ai6/checker-outputs:rw,nosuid,nodev,noexec,mode=777',
            '--tmpfs', '/var/lib/ai6/checker-workspace:rw,nosuid,nodev,noexec,mode=777',
            '-e', 'APP_ENV=testing', '-e', 'APP_KEY='.$this->appKey,
            '-e', 'AI6_RUNTIME_ROLE=agent', '--entrypoint', 'php', $imageId, '-r', $code,
        ]);
        $negative->setTimeout(30);
        $negative->mustRun();
        self::assertSame('unconfined: agent-presence-blocked, agent-heartbeat-blocked, checker-start-blocked, doctor-red', $negative->getOutput());
    }

    private function assertAgentPresenceFailureIsContained(): void
    {
        $image = $this->compose(['images', '-q', 'agent'], 30);
        $image->mustRun();
        $code = <<<'PHP'
require '/opt/ai6/vendor/autoload.php';
$app = require '/opt/ai6/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!(new App\AI6\Shared\Process\NativeProcessRuntimeProbe)->apparmorConfined(App\AI6\Shared\Process\ExecutionRole::AGENT)) {
    throw new RuntimeException('The startup failure fixture must be confined');
}
mkdir('/tmp/presence', 0700);
mkdir('/tmp/mailbox', 0700);
mkdir('/run/ai6/heartbeat/agent', 0700, true);
file_put_contents('/run/ai6/heartbeat/agent/boot-id', str_repeat('a', 32));
file_put_contents('/tmp/canary', 'unchanged');
symlink('/tmp/canary', '/tmp/presence/boot-id');
putenv('AI6_HEARTBEAT_DIRECTORY=/run/ai6/heartbeat/agent');
putenv('AI6_HEARTBEAT_INTERVAL=1');
config(['ai6.provider_onboarding.presence_root' => '/tmp/presence', 'ai6.execution_mailboxes.agent_root' => '/tmp/mailbox']);
config(['logging.default' => 'single', 'logging.channels.single.path' => '/tmp/start-failure.log']);
$exit = Illuminate\Support\Facades\Artisan::call('ai6:execution-mailbox', ['role' => 'agent', '--once' => true]);
$output = Illuminate\Support\Facades\Artisan::output();
if ($exit !== 1 || !str_contains($output, 'Die Agent-Präsenz konnte nicht sicher initialisiert werden.')
    || str_contains($output, 'CredentialProjectionException') || str_contains($output, '/tmp/')
    || file_get_contents('/tmp/canary') !== 'unchanged' || !is_link('/tmp/presence/boot-id')
    || glob('/tmp/presence/*') !== ['/tmp/presence/boot-id']
    || glob('/run/ai6/heartbeat/agent/*') !== ['/run/ai6/heartbeat/agent/boot-id']) {
    throw new RuntimeException('Presence startup failure was not contained');
}
echo 'presence-start-failure: exit-1, value-free-error, no-presence-or-heartbeat';
PHP;
        $probe = new Process([
            'docker', 'run', '--rm', '--network', 'none', '--read-only', '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges:true', '--security-opt', 'apparmor=ai6-agent-v1',
            '--security-opt', 'systempaths=unconfined', '--user', '10002:10001',
            '--tmpfs', '/tmp:rw,nosuid,nodev,noexec,mode=1777',
            '--tmpfs', '/run:rw,nosuid,nodev,noexec,mode=1777',
            '-e', 'APP_ENV=testing', '-e', 'APP_KEY='.$this->appKey,
            '-e', 'AI6_RUNTIME_ROLE=agent', '--entrypoint', 'php', trim($image->getOutput()), '-r', $code,
        ]);
        $probe->setTimeout(30);
        $probe->mustRun();
        self::assertSame('presence-start-failure: exit-1, value-free-error, no-presence-or-heartbeat', $probe->getOutput());
    }

    /** @param array<string, mixed> $home */
    private function assertPrivateProviderProjections(array $home): void
    {
        // Exercise the actual scope producers, with synthetic credentials only.
        // Reflection reaches the login filesystem boundary without an OAuth call.
        $code = <<<'PHP'
require '/opt/ai6/vendor/autoload.php';
$app = require '/opt/ai6/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$home = new App\AI6\Agents\ExecutionHome(...json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR));
$store = $app->make(App\AI6\Agents\ProviderCredentialStore::class);
$private = App\AI6\Agents\ProviderOnboarding::path('private_root');
$canary = $private.'/smoke-sibling';
file_put_contents($canary, 'supervisor-only');
$auth = '{"OPENAI_API_KEY":"synthetic-smoke-no-provider-access"}';
$run = function (string $cwd, array $readOnly, array $writable, ?string $authFile = null) use ($app, $canary, $auth): void {
    $payload = <<<'INNER'
$spec = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
if (trim(file_get_contents('/proc/self/attr/current')) !== 'ai6-agent-v1 (enforce)') { throw new RuntimeException('Lost profile'); }
foreach ([$spec['canary'], '/var/lib/ai6/provider-store', '/var/lib/ai6/provider-reports', '/run/ai6/heartbeat/agent', '/opt/ai6'] as $foreign) {
    if (file_exists($foreign)) { throw new RuntimeException('Supervisor state exposed'); }
}
foreach ($spec['readOnly'] as $path) {
    // chmod fails on a read-only mount even when DAC alone would permit it.
    if (!file_exists($path) || @chmod($path, 0700) || @file_put_contents(is_dir($path) ? $path.'/forbidden' : $path, 'x') !== false) {
        throw new RuntimeException('Projection is not read-only');
    }
}
foreach ($spec['writable'] as $path) {
    if (file_put_contents($path.'/allowed', 'x') !== 1) { throw new RuntimeException('Output is not writable'); }
}
if ($spec['authFile'] !== null && hash('sha256', file_get_contents($spec['authFile'])) !== $spec['authHash']) {
    throw new RuntimeException('Synthetic auth projection changed');
}
echo 'private-scope-ok';
INNER;
    $spec = json_encode(compact('readOnly', 'writable', 'authFile', 'canary') + ['authHash' => hash('sha256', $auth)], JSON_THROW_ON_ERROR);
    $result = $app->make(App\AI6\Shared\Process\ControlProcessRunner::class)->run(new App\AI6\Shared\Process\ProcessRequest(
        ['/usr/local/bin/php', '-r', $payload, $spec], $cwd, [], [],
        new App\AI6\Shared\Redaction\RedactionContext('provider', null, 'private-projection-smoke'),
    ));
    if (!$result->succeeded() || $result->output !== 'private-scope-ok') {
        throw new RuntimeException('Private scope failed: '.$result->outcome->value.' '.$result->errorOutput);
    }
};
try {
    $store->replace('codex_cli', $auth);
    $store->withProjection($home, 'codex_cli', $store->generation('codex_cli'), function ($projected) use ($run): void {
        if (!preg_match('~/projection-[a-f0-9]{32}$~D', $projected->authDirectory)) { throw new RuntimeException('Unexpected projection source'); }
        $run($projected->workspace, [$projected->root, $projected->authDirectory], [$projected->outputRoot], $projected->authDirectory.'/auth.json');
    });
    echo 'projection-ro-ok|';
    $temporary = new ReflectionMethod(App\AI6\Agents\ProviderLogin::class, 'temporary');
    $login = $app->make(App\AI6\Agents\ProviderLogin::class);
    $temporary->invoke($login, function ($root) use ($run): void { $run($root, [], [$root]); });
    $temporary->invoke($login, function ($root) use ($run): void {
        if (!preg_match('~/login-[a-f0-9]{32}$~D', $root)) { throw new RuntimeException('Unexpected login source'); }
        $run($root, [$root.'/auth.json'], [$root], $root.'/auth.json');
    }, $auth);
    echo 'login-rw-auth-ro-ok|';
    $app->make(App\AI6\Agents\ExecutionHomeManager::class)->withProbeHome('codex_cli', 'codex-cli-v1', function ($probe) use ($run): void {
        if (!preg_match('~/probe-[a-f0-9]{32}/inputs/doctor-new-[a-f0-9]{16}$~D', $probe->root)
            || !preg_match('~/probe-[a-f0-9]{32}/outputs/doctor-new-[a-f0-9]{16}$~D', $probe->outputRoot)) {
            throw new RuntimeException('Unexpected probe source');
        }
        $run($probe->workspace, [$probe->root], [$probe->outputRoot]);
    });
    echo 'probe-input-ro-output-rw-ok';
} finally {
    $store->replace('codex_cli', null);
    unlink($canary);
}
if (glob($private.'/projection-*') !== [] || glob($private.'/login-*') !== [] || glob($private.'/probe-*') !== []) {
    throw new RuntimeException('Private projection cleanup failed');
}
PHP;
        $probe = $this->compose(['exec', '-T', 'agent', 'php', '-r', $code, json_encode($home, JSON_THROW_ON_ERROR)], 30);
        $probe->mustRun();
        self::assertSame('projection-ro-ok|login-rw-auth-ro-ok|probe-input-ro-output-rw-ok', $probe->getOutput());
    }

    private function assertMailboxAndIsolationBoundaries(): void
    {
        foreach (['agent' => '10002', 'checker' => '10003'] as $role => $uid) {
            $profile = $this->compose(['exec', '-T', $role, 'cat', '/proc/self/attr/current'], 30);
            $profile->mustRun();
            self::assertSame('ai6-'.$role.'-v1 (enforce)', trim($profile->getOutput()));
            $confinement = $this->compose(['exec', '-T', $role, 'php', '-r',
                'require "/opt/ai6/vendor/autoload.php"; $probe = new App\AI6\Shared\Process\NativeProcessRuntimeProbe; echo json_encode([$probe->apparmorConfined(App\AI6\Shared\Process\ExecutionRole::AGENT), $probe->apparmorConfined(App\AI6\Shared\Process\ExecutionRole::CHECKER)]);',
            ], 30);
            $confinement->mustRun();
            self::assertSame($role === 'agent' ? '[true,false]' : '[false,true]', $confinement->getOutput());
            $fixture = '/var/lib/ai6/'.$role.'-outputs/path-protection-new';
            $this->seedPathProtectionFixture($fixture);
            $paths = $this->compose(['exec', '-T', $role, 'php', '-r', $this->pathProtectionProbe(), $fixture], 30);
            $paths->mustRun();
            self::assertSame('path-protection-ok', $paths->getOutput());
            $identity = $this->compose(['exec', '-T', $role, 'sh', '-c', 'printf "%s|%s" "$(id -u)" "$(id -g)"'], 30);
            $identity->mustRun();
            self::assertSame($uid.'|10001', trim($identity->getOutput()));

            $heartbeat = $this->compose(['exec', '-T', $role, 'cat', '/run/ai6/heartbeat/'.$role.'/heartbeat.json'], 30);
            $heartbeat->mustRun();
            $document = json_decode($heartbeat->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            self::assertSame($role, $document['role'] ?? null);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', $document['boot_id'] ?? '');
            self::assertIsInt($document['mailbox_pending'] ?? null);

            $input = '/var/lib/ai6/'.$role.'-executions';
            $output = '/var/lib/ai6/'.$role.'-outputs';
            $readOnly = $this->compose(['exec', '-T', $role, 'php', '-r', '@file_put_contents($argv[1]."/forbidden","x"); exit(file_exists($argv[1]."/forbidden") ? 1 : 0);', $input], 30);
            $readOnly->mustRun();
            $writable = $this->compose(['exec', '-T', $role, 'php', '-r', 'exit(file_put_contents($argv[1]."/allowed","x") === 1 ? 0 : 1);', $output], 30);
            $writable->mustRun();
        }

        $network = $this->compose(['exec', '-T', 'checker', 'sh', '-c', 'find /sys/class/net -mindepth 1 -maxdepth 1 -printf "%f\n" | sort'], 30);
        $network->mustRun();
        self::assertSame('lo', trim($network->getOutput()));

        $namespaceProbeCode = <<<'PHP'
$roots = ['/var/lib/ai6/checker-executions', '/var/lib/ai6/checker-outputs', '/run/ai6/heartbeat/checker'];
$paths = [
    '/var/lib/ai6/checker-executions/requests',
    '/var/lib/ai6/checker-outputs/consumed',
    '/var/lib/ai6/checker-outputs/results',
    '/var/lib/ai6/checker-outputs/heartbeats',
];
foreach ($roots as $root) {
    if (is_readable($root) || is_writable($root) || @scandir($root) !== false) {
        fwrite(STDERR, 'accessible-root:'.$root."\n");
        exit(10);
    }
}
foreach ($paths as $path) {
    if (file_exists($path) || is_readable($path) || is_writable($path)) {
        fwrite(STDERR, 'visible-path:'.$path."\n");
        exit(11);
    }
}
$interfaces = array_values(array_diff(scandir('/sys/class/net'), ['.', '..', 'lo']));
if ($interfaces !== []) {
    fwrite(STDERR, 'network:'.implode(',', $interfaces)."\n");
    exit(12);
}
if (! is_writable(getcwd())) {
    fwrite(STDERR, "workspace-not-writable\n");
    exit(13);
}
$status = file_get_contents('/proc/self/status');
foreach (['CapInh', 'CapPrm', 'CapEff', 'CapBnd', 'CapAmb'] as $capability) {
    if (preg_match('/^'.$capability.':\s+0+$/m', $status) !== 1) { throw new RuntimeException('Remaining checker capability: '.$capability); }
}
$attempt = proc_open(['/usr/bin/unshare', '--user', '--map-root-user', '--mount', '/usr/bin/umount', $roots[0]], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($attempt)) { throw new RuntimeException('Nested namespace probe did not start'); }
foreach ($pipes as $pipe) { stream_get_contents($pipe); fclose($pipe); }
if (proc_close($attempt) === 0) { throw new RuntimeException('Nested namespace exposed the hidden mailbox'); }
fwrite(STDOUT, 'checker-wrapper-ok');
PHP;
        $namespaceProbe = $this->compose([
            'exec', '-T', '--workdir', '/var/lib/ai6/checker-workspace', 'checker',
            '/usr/bin/unshare', '--user', '--map-root-user', '--mount', '--pid', '--fork', '--mount-proc',
            '/opt/ai6/app/AI6/Shared/Process/checker-process-wrapper.sh',
            '/var/lib/ai6/checker-workspace',
            '/var/lib/ai6/checker-executions',
            '/var/lib/ai6/checker-outputs',
            '/run/ai6/heartbeat/checker',
            '--', '/usr/local/bin/php', '-r', $namespaceProbeCode,
        ], 30);
        $namespaceProbe->mustRun();
        self::assertSame('checker-wrapper-ok', trim($namespaceProbe->getOutput()));

        $protectedNamespace = $this->compose([
            'exec', '-T', 'checker', '/usr/bin/unshare', '--user', '--map-root-user', '--mount', '--pid', '--fork', '--mount-proc',
            '/usr/local/bin/php', '-r', $this->pathProtectionProbe(), '/var/lib/ai6/checker-outputs/path-protection-new',
        ], 30);
        $protectedNamespace->mustRun();
        self::assertSame('path-protection-ok', $protectedNamespace->getOutput());
        $this->assertForbiddenNamespaceMounts();

        $this->assertCheckerPromisesRejectBeforeProgram();
        $this->assertRealCheckerExecutionRoundTrip();

        $prepareCode = <<<'PHP'
require '/opt/ai6/vendor/autoload.php';
$app = require '/opt/ai6/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$export = '/tmp/ai6-execution-home-smoke';
if (!is_dir($export) && !mkdir($export, 0700, true)) { throw new RuntimeException('export'); }
file_put_contents($export.'/source.php', '<?php');
$instructions = $app->make(App\AI6\Agents\InstructionProfileRegistry::class)->get('fake');
$runtime = $app->make(App\AI6\Agents\ProviderRuntimeProfileRegistry::class)->get('fake-v1');
$entry = new App\AI6\Agents\InstructionSnapshotEntry('agents_md', 'repository', 10, 'AGENTS.md', str_repeat('a', 40), "bound\n", []);
$snapshot = new App\AI6\Agents\InstructionSnapshot('fake', [$entry], str_repeat('b', 64));
$home = $app->make(App\AI6\Agents\ExecutionHomeManager::class)->create(
    '/var/lib/ai6/agent-executions',
    '/var/lib/ai6/agent-outputs',
    'smoke-slot',
    null,
    $export,
    $instructions,
    $snapshot,
    $runtime,
    new App\AI6\Agents\CredentialProjection('fake', 'test-v1', []),
);
$app->make(App\AI6\Shared\Process\ExecutionMailboxFactory::class)
    ->forRole(App\AI6\Shared\Process\ExecutionRole::AGENT)
    ->write(App\AI6\Shared\Process\MailboxMessageType::REQUEST, 'smoke-slot', 'smoke-request', 'request');
echo json_encode(get_object_vars($home), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
PHP;
        $prepare = $this->compose(['exec', '-T', 'worker', 'php', '-r', $prepareCode], 30);
        $prepare->mustRun();
        $home = json_decode($prepare->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($home);
        foreach (['root', 'outputRoot', 'workspace', 'instructionOverlay', 'resultDirectory', 'artifactDirectory'] as $key) {
            self::assertIsString($home[$key] ?? null);
        }

        $this->seedPathProtectionFixture($home['outputRoot'].'/path-protection');
        $agentProbeCode = <<<'PHP'
require '/opt/ai6/vendor/autoload.php';
$app = require '/opt/ai6/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$home = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$request = new App\AI6\Shared\Process\ProcessRequest(
    ['/usr/local/bin/php', '-r', $argv[2], $home['outputRoot'].'/path-protection', $home['workspace'], $home['outputRoot']],
    $home['workspace'], [], [], new App\AI6\Shared\Redaction\RedactionContext('project', 'run', 'namespace-smoke'),
);
$scope = new App\AI6\Shared\Process\AgentProcessScope([$home['root']], [$home['outputRoot']]);
$process = new Symfony\Component\Process\Process($scope->command($request), $home['workspace']);
$process->setTimeout(20);
$process->mustRun();
echo $process->getOutput();
PHP;
        $agentPayload = $this->pathProtectionProbe().<<<'PHP'
foreach (['/opt/ai6', '/var/lib/ai6/provider-store', '/var/lib/ai6/provider-reports', '/run/ai6/provider-private', '/run/ai6/heartbeat/agent', '/var/lib/ai6/agent-executions/requests', '/var/lib/ai6/agent-outputs/path-protection-new'] as $foreign) {
    if (file_exists($foreign)) { throw new RuntimeException('Foreign projection visible: '.$foreign); }
}
if (!is_file($argv[2].'/source.php') || @file_put_contents($argv[2].'/forbidden', 'x') !== false
    || @file_put_contents('/forbidden', 'x') !== false || file_put_contents($argv[3].'/allowed', 'x') !== 1) {
    throw new RuntimeException('The sealed input/output boundary failed.');
}
$status = file_get_contents('/proc/self/status');
foreach (['CapInh', 'CapPrm', 'CapEff', 'CapBnd', 'CapAmb'] as $capability) {
    if (preg_match('/^'.$capability.':\s+0+$/m', $status) !== 1) { throw new RuntimeException('Remaining capability: '.$capability); }
}
echo '|agent-scope-ok';
PHP;
        $agentScope = $this->compose(['exec', '-T', 'agent', 'php', '-r', $agentProbeCode, json_encode($home, JSON_THROW_ON_ERROR), $agentPayload], 30);
        $agentScope->mustRun();
        self::assertSame('path-protection-ok|agent-scope-ok', $agentScope->getOutput());
        $this->assertPrivateProviderProjections($home);

        $this->waitUntil(function (): bool {
            $heartbeat = $this->compose(['exec', '-T', 'agent', 'cat', '/run/ai6/heartbeat/agent/heartbeat.json'], 30);
            if ($heartbeat->run() !== 0) {
                return false;
            }
            $document = json_decode($heartbeat->getOutput(), true);

            return is_array($document) && ($document['mailbox_pending'] ?? null) === 1;
        }, 20, 'Die Ausführungsrolle konnte das Worker-Envelope im 0750-Requestverzeichnis nicht sehen.');

        $verify = $this->compose([
            'exec', '-T', 'agent', 'php', '-r',
            'require "/opt/ai6/vendor/autoload.php"; $app=require "/opt/ai6/bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $request=new App\AI6\Shared\Process\ProcessRequest(["/bin/true"],$argv[1],[],[],new App\AI6\Shared\Redaction\RedactionContext("project","run","smoke"),policy:App\AI6\Shared\Process\ProcessPolicyName::AGENT,resultDirectory:$argv[2],artifactDirectory:$argv[3]); (new App\AI6\Shared\Process\ProcessIsolationVerifier)->assertIsolated($request,App\AI6\Shared\Process\ProcessPolicyRegistry::fromConfiguredValues()->get(App\AI6\Shared\Process\ProcessPolicyName::AGENT)); $mailbox=$app->make(App\AI6\Shared\Process\ExecutionMailboxFactory::class)->forRole(App\AI6\Shared\Process\ExecutionRole::AGENT); $mailbox->write(App\AI6\Shared\Process\MailboxMessageType::RESULT,"smoke-slot","smoke-result","executor-result"); echo "verified";',
            $home['workspace'], $home['resultDirectory'], $home['artifactDirectory'],
        ], 30);
        $verify->mustRun();
        self::assertSame('verified', trim($verify->getOutput()));

        $consumeCode = <<<'PHP'
require '/opt/ai6/vendor/autoload.php';
$app = require '/opt/ai6/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$mailbox = $app->make(App\AI6\Shared\Process\ExecutionMailboxFactory::class)->forRole(App\AI6\Shared\Process\ExecutionRole::AGENT);
$message = $mailbox->read(App\AI6\Shared\Process\MailboxMessageType::RESULT, 'smoke-slot', 'smoke-result', new App\AI6\Shared\Redaction\RedactionContext('project', 'run', 'smoke-result'));
$home = new App\AI6\Agents\ExecutionHome(...json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR));
$app->make(App\AI6\Agents\ExecutionHomeManager::class)->destroy($home);
echo $message->content;
PHP;
        $consume = $this->compose(['exec', '-T', 'worker', 'php', '-r', $consumeCode, json_encode($home, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)], 30);
        $consume->mustRun();
        self::assertSame('executor-result', trim($consume->getOutput()));

        foreach ([$home['root'], $home['outputRoot']] as $removedRoot) {
            $removed = $this->compose(['exec', '-T', 'worker', 'test', '!', '-e', $removedRoot], 30);
            $removed->mustRun();
        }
    }

    private function assertForbiddenNamespaceMounts(): void
    {
        foreach ([
            ['-t', 'tmpfs', '-o', 'nosuid,nodev,noexec', 'tmpfs', '/etc'],
            ['--bind', '/proc', '/tmp'],
            ['--bind', '/var/lib/ai6/checker-outputs', '/tmp'],
            ['-o', 'remount,rw', '/proc/sys'],
        ] as $arguments) {
            $probe = $this->compose([
                'exec', '-T', 'checker', '/usr/bin/unshare', '--user', '--map-root-user', '--mount', '--pid', '--fork', '--mount-proc',
                '/usr/bin/mount', ...$arguments,
            ], 30);
            self::assertSame(32, $probe->run(), 'Expected mount failure: '.$probe->getErrorOutput());
        }
        foreach ([
            ['/', '/host'],
            ['/var/lib/ai6/provider-store', '/credentials'],
            ['/run/ai6/provider-private', '/private'],
            ['/run/ai6/provider-private', '/run/ai6/provider-private'],
            ['/var/lib/ai6/agent-executions', '/mailbox'],
            ['/proc', '/var/lib/ai6/agent-outputs/path-protection-new'],
        ] as [$source, $target]) {
            $probe = $this->compose([
                'exec', '-T', 'agent', '/usr/bin/bwrap', '--die-with-parent', '--unshare-user', '--unshare-pid', '--unshare-ipc', '--unshare-uts',
                '--cap-drop', 'ALL', '--new-session', '--ro-bind', '/usr', '/usr', '--bind', $source, $target, '--', '/usr/bin/true',
            ], 30);
            self::assertNotSame(0, $probe->run(), 'A forbidden agent projection succeeded.');
            self::assertStringContainsString('permission denied', strtolower($probe->getErrorOutput()));
        }
    }

    private function seedPathProtectionFixture(string $root): void
    {
        $seed = <<<'PHP'
$root = $argv[1];
$paths = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
foreach (['', '/oldroot', '/newroot', '/tmp/oldroot'] as $alias) {
    foreach ($paths as $path) {
        foreach (['files'.$alias.$path, 'trees'.$alias.$path.'/probe'] as $relative) {
            $file = $root.'/'.$relative;
            if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0777, true)) { throw new RuntimeException('Fixture directory'); }
            file_put_contents($file, 'synthetic-path-canary');
            chmod($file, 0666);
        }
    }


}
chmod($root, 0777);
file_put_contents($root.'/control', 'synthetic-path-canary');
chmod($root.'/control', 0666);
PHP;
        $prepare = $this->compose(['exec', '-T', 'worker', 'php', '-r', $seed, $root, json_encode([...ExecutionRoleProtectedPaths::MASKED, ...ExecutionRoleProtectedPaths::READONLY], JSON_THROW_ON_ERROR)], 30);
        $prepare->mustRun();
        // This positive oracle proves every synthetic file exists and is
        // readable/writable before the confined role tests the same bytes.
        $baseline = $this->compose(['exec', '-T', 'worker', 'php', '-r', $this->pathProtectionProbe(), $root, 'baseline'], 30);
        $baseline->mustRun();
        self::assertSame('path-protection-ok', $baseline->getOutput());
    }

    private function pathProtectionProbe(): string
    {
        return '$masked = '.var_export(ExecutionRoleProtectedPaths::MASKED, true).'; $readonly = '.var_export(ExecutionRoleProtectedPaths::READONLY, true).';'.<<<'PHP'
$root = $argv[1];
$baseline = ($argv[2] ?? '') === 'baseline';
$control = @fopen($root.'/control', 'r+');
if ($control === false) { throw new RuntimeException('Positive control unavailable'); }
fclose($control);
if (!link($root.'/control', $root.'/control-link') || !rename($root.'/control-link', $root.'/control-renamed')) {
    throw new RuntimeException('Link/rename positive control unavailable');
}
unlink($root.'/control-renamed');
foreach (['', '/oldroot', '/newroot', '/tmp/oldroot'] as $alias) {
    foreach (array_merge($masked, $readonly) as $path) {
        $maskedPath = in_array($path, $masked, true);
        foreach (['files'.$alias.$path, 'trees'.$alias.$path.'/probe'] as $relative) {
            $file = $root.'/'.$relative;
            foreach (['r', 'r+'] as $mode) {
                $handle = @fopen($file, $mode);
                $allowed = $baseline || (!$maskedPath && $mode === 'r');
                if (($handle !== false) !== $allowed) { throw new RuntimeException('Path boundary: '.$mode.' '.$relative); }
                if (is_resource($handle)) { fclose($handle); }
            }
            if (!$baseline && (@link($file, $root.'/escaped-link') || @rename($file, $root.'/escaped-rename'))) {
                throw new RuntimeException('Path escaped by link/rename: '.$relative);
            }
        }
    }
}
if (!$baseline) {
    foreach ($masked as $path) {
        foreach ([$path, '/proc/self/root'.$path] as $alias) {
            if (is_dir($alias) ? @scandir($alias) !== false : @fopen($alias, 'r') !== false) {
                throw new RuntimeException('Real masked path readable: '.$alias);
            }
        }
    }
    foreach (['/proc/sys/kernel/hostname', '/proc/sysrq-trigger', '/proc/irq/default_smp_affinity'] as $path) {
        if (@fopen($path, 'r+') !== false) { throw new RuntimeException('Real readonly path writable: '.$path); }
    }
}
echo 'path-protection-ok';
PHP;
    }

    private function assertRealCheckerExecutionRoundTrip(): void
    {
        ['run' => $fixtureRun, 'worktree' => $fixtureWorktree] = $this->checkableRun('AI6-045-COMPOSE-SMOKE');
        $this->checkerSmokeDatabase = sys_get_temp_dir().'/ai6-checker-smoke-'.bin2hex(random_bytes(8)).'.sqlite';
        $quotedDatabase = DB::connection('sqlite')->getPdo()->quote($this->checkerSmokeDatabase);
        self::assertIsString($quotedDatabase);
        DB::connection('sqlite')->getPdo()->exec('VACUUM INTO '.$quotedDatabase);

        $containerDatabase = '/tmp/ai6-checker-smoke.sqlite';
        $containerWorktree = '/tmp/ai6-checker-smoke-worktree';
        $this->copyFileIntoService('worker', $this->checkerSmokeDatabase, $containerDatabase);
        $this->copyTreeIntoService('worker', $fixtureWorktree, $containerWorktree);

        $stageCode = <<<'PHP'
require '/opt/ai6/vendor/autoload.php';
$app = require '/opt/ai6/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$executionId = 'smoke-check-'.bin2hex(random_bytes(6));
$runId = $argv[1];
config(['database.connections.sqlite.database' => $argv[2]]);
$app->make('db')->purge('sqlite');
$run = App\AI6\Runs\Models\Run::query()->findOrFail($runId);
$run->setAttribute('worktree_path', $argv[3]);
$deadline = time() + 60;
$record = $app->make(App\AI6\Checks\CheckRunner::class)->dispatchOrCollect(
    $run,
    App\AI6\Checks\CheckPhase::BEFORE_REVIEW,
    'git-diff-check',
    $executionId,
    $deadline,
);
if ($record !== null) { throw new RuntimeException('The initial checker poll returned a result.'); }
$deliveryId = App\AI6\Checks\CheckerExecutionProcessor::deliveryId($executionId);
echo json_encode([
    'execution_id' => $executionId, 'run_id' => $runId, 'delivery_id' => $deliveryId,
    'database' => $argv[2], 'worktree' => $argv[3], 'deadline' => $deadline,
], JSON_THROW_ON_ERROR);
PHP;
        $stage = $this->compose([
            'exec', '-T', 'worker', 'php', '-r', $stageCode,
            $fixtureRun->id, $containerDatabase, $containerWorktree,
        ], 30);
        $stage->mustRun();
        $binding = json_decode($stage->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($binding);
        self::assertIsString($binding['execution_id'] ?? null);
        self::assertIsString($binding['run_id'] ?? null);
        self::assertIsString($binding['delivery_id'] ?? null);

        $resultPath = '/var/lib/ai6/checker-outputs/results/'.$binding['delivery_id'].'.json';
        $this->waitUntil(function () use ($resultPath): bool {
            return $this->compose(['exec', '-T', 'worker', 'test', '-f', $resultPath], 30)->run() === 0;
        }, 30, 'Der reale Checker hat innerhalb der Frist kein Ergebnis publiziert.');

        foreach ([
            'claims' => '/var/lib/ai6/checker-outputs/claims',
            'heartbeats' => '/var/lib/ai6/checker-outputs/heartbeats',
            'executions' => '/var/lib/ai6/checker-outputs/executions',
            'execution' => '/var/lib/ai6/checker-outputs/executions/'.$binding['execution_id'],
            'results' => '/var/lib/ai6/checker-outputs/results',
        ] as $name => $path) {
            $stat = $this->compose(['exec', '-T', 'worker', 'stat', '-c', '%u|%g|%a', $path], 30);
            $stat->mustRun();
            self::assertSame($name === 'execution' ? '10003|10001|770' : '10003|10001|730', trim($stat->getOutput()), $name);
        }

        $consumeCode = <<<'PHP'
require '/opt/ai6/vendor/autoload.php';
$app = require '/opt/ai6/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$database = $argv[4];
config(['database.connections.sqlite.database' => $database]);
$app->make('db')->purge('sqlite');
$run = App\AI6\Runs\Models\Run::query()->findOrFail($argv[2]);
$run->setAttribute('worktree_path', $argv[5]);
$record = $app->make(App\AI6\Checks\CheckRunner::class)->dispatchOrCollect(
    $run,
    App\AI6\Checks\CheckPhase::BEFORE_REVIEW,
    'git-diff-check',
    $argv[1],
    (int) $argv[6],
);
if (! $record instanceof App\AI6\Checks\Models\CheckResultRecord) {
    throw new RuntimeException('The bound checker result was not persisted.');
}
echo json_encode([
    'record_count' => App\AI6\Checks\Models\CheckResultRecord::query()->where('run_id', $argv[2])->count(),
    'execution_id' => $record->getAttribute('checker_execution_id'),
    'run_id' => $record->run_id,
    'checker_boot_id' => $record->getAttribute('checker_boot_id'),
    'deadline_at' => $record->getAttribute('checker_deadline_at'),
    'phase' => $record->phase->value,
    'state' => $record->state->value,
    'profile' => $record->profile,
    'source_tree_sha256' => $record->tree_sha,
    'result_tree_sha256' => $record->result_tree_sha,
], JSON_THROW_ON_ERROR);
PHP;
        $consume = $this->compose([
            'exec', '-T', 'worker', 'php', '-r', $consumeCode,
            $binding['execution_id'], $binding['run_id'], $binding['delivery_id'],
            $binding['database'], $binding['worktree'], (string) $binding['deadline'],
        ], 30);
        if ($consume->run() !== 0) {
            $diagnostic = $this->compose([
                'exec', '-T', 'worker', 'find',
                '/var/lib/ai6/checker-executions/staged/'.$binding['execution_id'],
                '/var/lib/ai6/checker-outputs/executions/'.$binding['execution_id'],
                '-printf', '%p|%U|%G|%m|%y\n',
            ], 30);
            $diagnostic->run();
            self::fail($consume->getErrorOutput()."\nCleanup state:\n".$diagnostic->getOutput().$diagnostic->getErrorOutput());
        }
        $result = json_decode($consume->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame($binding['execution_id'], $result['execution_id'] ?? null);
        self::assertSame($binding['run_id'], $result['run_id'] ?? null);
        self::assertSame(1, $result['record_count'] ?? null);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', $result['checker_boot_id'] ?? '');
        self::assertSame($binding['deadline'], $result['deadline_at'] ?? null);
        self::assertSame('before_review', $result['phase'] ?? null);
        self::assertSame('succeeded', $result['state'] ?? null);
        self::assertSame('git-diff-check', $result['profile'] ?? null);
        self::assertSame($result['source_tree_sha256'] ?? null, $result['result_tree_sha256'] ?? null);

        foreach ([
            '/var/lib/ai6/checker-executions/staged/'.$binding['execution_id'],
            '/var/lib/ai6/checker-outputs/claims/'.$binding['execution_id'].'.json',
            '/var/lib/ai6/checker-outputs/heartbeats/'.$binding['execution_id'].'.json',
            '/var/lib/ai6/checker-outputs/executions/'.$binding['execution_id'],
            '/var/lib/ai6/checker-outputs/results/'.$binding['delivery_id'].'.json',
            '/var/lib/ai6/checker-outputs/consumed/'.$binding['delivery_id'].'.json',
        ] as $removed) {
            $absent = $this->compose(['exec', '-T', 'worker', 'test', '!', '-e', $removed], 30);
            $absent->mustRun();
        }
    }

    private function assertCheckerPromisesRejectBeforeProgram(): void
    {
        $probeCode = <<<'PHP'
require '/opt/ai6/vendor/autoload.php';
$app = require '/opt/ai6/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$suffix = getmypid().'-'.bin2hex(random_bytes(4));
$workspace = '/tmp/ai6-checker-promise-test-'.$suffix;
$output = '/var/lib/ai6/checker-outputs/promise-test-'.$suffix;
$originalWorkspaceRoot = config('ai6.checks.runtime.workspace_root');
$originalWorkingRoots = config('ai6.process.policies.checker.working_roots');
$cleanup = static function (string $root): void {
    if (! is_dir($root)) {
        if (is_file($root) || is_link($root)) { @unlink($root); }

        return;
    }

    try {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
    } catch (Throwable) {
        // Keep the original probe failure visible; the isolated path is test-only.
    }

    @rmdir($root);
};
$names = ['input_read_only', 'output_separate', 'workspace_private', 'container_read_only', 'network_isolated', 'apparmor_confined', 'namespace_tooling'];
try {
    foreach ([$workspace, $output.'/result', $output.'/artifact'] as $directory) {
        if (! mkdir($directory, 0770, true)) { throw new RuntimeException('promise-test-directory'); }
    }
    config([
        'ai6.checks.runtime.workspace_root' => $workspace,
        'ai6.process.policies.checker.working_roots' => [$workspace],
    ]);
    $app->forgetInstance(App\AI6\Shared\Process\ProcessPolicyRegistry::class);

    foreach ($names as $violated) {
        $states = array_fill_keys($names, true);
        $states[$violated] = false;
        $runtime = new class($states) implements App\AI6\Shared\Process\ProcessRuntimeProbe {
            public function __construct(private array $states) {}
            public function apparmorConfined(App\AI6\Shared\Process\ExecutionRole $role): bool { return $this->states['apparmor_confined']; }
            public function checkerRuntimePromises(): array { return $this->states; }
            public function mountOptions(string $path): array { return ['rw', 'nosuid', 'nodev', 'noexec']; }
        };
        $app->instance(
            App\AI6\Shared\Process\ProcessIsolationBoundary::class,
            new App\AI6\Shared\Process\ProcessIsolationVerifier($runtime),
        );
        $app->forgetInstance(App\AI6\Shared\Process\ControlProcessRunner::class);
        $marker = $workspace.'/'.$violated;
        $result = $app->make(App\AI6\Shared\Process\ControlProcessRunner::class)->run(new App\AI6\Shared\Process\ProcessRequest(
            [PHP_BINARY, '-r', 'file_put_contents($argv[1], "started");', $marker],
            $workspace,
            [],
            [],
            new App\AI6\Shared\Redaction\RedactionContext('checker', null, 'promise-test'),
            policy: App\AI6\Shared\Process\ProcessPolicyName::CHECKER,
            resultDirectory: $output.'/result',
            artifactDirectory: $output.'/artifact',
        ));
        if ($result->outcome !== App\AI6\Shared\Process\ProcessOutcome::START_REJECTED
            || ! str_contains($result->errorOutput, $violated) || file_exists($marker)) {
            throw new RuntimeException('checker-promise-not-rejected:'.$violated);
        }
    }
} finally {
    config([
        'ai6.checks.runtime.workspace_root' => $originalWorkspaceRoot,
        'ai6.process.policies.checker.working_roots' => $originalWorkingRoots,
    ]);
    $app->forgetInstance(App\AI6\Shared\Process\ProcessPolicyRegistry::class);
    $app->forgetInstance(App\AI6\Shared\Process\ControlProcessRunner::class);
    $cleanup($output);
    $cleanup($workspace);
}
if (file_exists($output) || file_exists($workspace)) {
    throw new RuntimeException('promise-test-cleanup');
}
echo 'checker-promises-rejected';
PHP;
        $probe = $this->compose(['exec', '-T', 'checker', 'php', '-r', $probeCode], 30);
        $probe->mustRun();
        self::assertSame('checker-promises-rejected', trim($probe->getOutput()));
    }

    private function copyFileIntoService(string $service, string $source, string $target): void
    {
        $bytes = file_get_contents($source);
        self::assertIsString($bytes);
        $copy = $this->compose([
            'exec', '-T', $service, 'sh', '-c', 'umask 0077; dd of="$1" status=none', 'sh', $target,
        ], 30);
        $copy->setInput($bytes);
        $copy->mustRun();
    }

    private function copyTreeIntoService(string $service, string $source, string $target): void
    {
        $createRoot = $this->compose(['exec', '-T', $service, 'mkdir', '-p', $target], 30);
        $createRoot->mustRun();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $relative = substr(str_replace('\\', '/', $entry->getPathname()), strlen(str_replace('\\', '/', $source)) + 1);
            if ($relative === '.git' || str_starts_with($relative, '.git/')) {
                continue;
            }
            $destination = $target.'/'.$relative;
            if ($entry->isDir()) {
                $create = $this->compose(['exec', '-T', $service, 'mkdir', '-p', $destination], 30);
                $create->mustRun();
            } else {
                $this->copyFileIntoService($service, $entry->getPathname(), $destination);
            }
        }
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

        $incomplete = $this->compose([
            'run', '--rm', '--no-deps', '--entrypoint', 'sh', 'init', '-c',
            'chmod 0600 -- "$1" && : > "$1" && chmod 0400 -- "$1"', 'sh', $lock,
        ], 30);
        $incomplete->mustRun();
        $incompleteStat = $this->compose(['exec', '-T', 'worker', 'stat', '-c', '%d:%i|%u|%g|%a|%s', $lock], 30);
        $incompleteStat->mustRun();
        self::assertStringEndsWith('|0|0|400|0', trim($incompleteStat->getOutput()));

        $recoveryRerun = $this->compose(['run', '--rm', '--no-deps', 'init'], 120);
        $recoveryRerun->mustRun();
        $recoveredStat = $this->compose(['exec', '-T', 'worker', 'stat', '-c', '%d:%i|%u|%g|%a|%s', $lock], 30);
        $recoveredStat->mustRun();
        $incompleteParts = explode('|', trim($incompleteStat->getOutput()));
        $recoveredParts = explode('|', trim($recoveredStat->getOutput()));
        self::assertSame($incompleteParts[0], $recoveredParts[0]);
        self::assertSame(['0', '0', '444', '0'], array_slice($recoveredParts, 1));
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
            ...$this->networkEnvironment,
        ]);
        $process->setTimeout($timeout);

        return $process;
    }

    /** @return array<string, string> */
    private function isolatedNetworkEnvironment(): array
    {
        $list = new Process(['docker', 'network', 'ls', '--quiet']);
        $list->setTimeout(30);
        $list->mustRun();
        $occupied = [];

        foreach (array_filter(preg_split('/\R/', trim($list->getOutput())) ?: []) as $networkId) {
            $inspect = new Process([
                'docker', 'network', 'inspect', '--format',
                '{{range .IPAM.Config}}{{println .Subnet}}{{end}}', $networkId,
            ]);
            $inspect->setTimeout(30);
            $inspect->mustRun();

            foreach (array_filter(preg_split('/\R/', trim($inspect->getOutput())) ?: []) as $subnet) {
                $range = $this->ipv4CidrRange($subnet);
                if ($range !== null) {
                    $occupied[] = $range;
                }
            }
        }

        for ($secondOctet = 240; $secondOctet <= 254; $secondOctet++) {
            for ($thirdOctet = 0; $thirdOctet <= 254; $thirdOctet += 2) {
                $serviceSubnet = sprintf('10.%d.%d.0/24', $secondOctet, $thirdOctet);
                $proxySubnet = sprintf('10.%d.%d.0/29', $secondOctet, $thirdOctet + 1);
                $candidates = [$this->ipv4CidrRange($serviceSubnet), $this->ipv4CidrRange($proxySubnet)];
                self::assertNotContains(null, $candidates);

                $collides = false;
                foreach ($candidates as $candidate) {
                    foreach ($occupied as $existing) {
                        if ($candidate[0] <= $existing[1] && $existing[0] <= $candidate[1]) {
                            $collides = true;
                            break 2;
                        }
                    }
                }

                if (! $collides) {
                    $proxyPrefix = sprintf('10.%d.%d', $secondOctet, $thirdOctet + 1);

                    return [
                        'AI6_SERVICE_SUBNET' => $serviceSubnet,
                        'AI6_SERVICE_IP_RANGE' => sprintf('10.%d.%d.128/25', $secondOctet, $thirdOctet),
                        'AI6_PROXY_SUBNET' => $proxySubnet,
                        'AI6_PROXY_IP_RANGE' => $proxyPrefix.'.4/30',
                        'AI6_PROXY_ADDRESS' => $proxyPrefix.'.2',
                        'AI6_HTTP_TRUSTED_PROXIES' => $proxyPrefix.'.2',
                    ];
                }
            }
        }

        self::fail('Kein kollisionsfreies privates IPv4-Netz für den isolierten Compose-Smoke gefunden.');
    }

    /** @return array{int, int}|null */
    private function ipv4CidrRange(string $cidr): ?array
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2 || filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ! preg_match('/\A(?:[0-9]|[12][0-9]|3[0-2])\z/D', $parts[1])) {
            return null;
        }

        $packed = inet_pton($parts[0]);
        if ($packed === false) {
            return null;
        }

        $unpacked = unpack('Naddress', $packed);
        if (! is_array($unpacked)) {
            return null;
        }

        $prefix = (int) $parts[1];
        $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        $network = $unpacked['address'] & $mask;

        return [$network, $network | (0xFFFFFFFF ^ $mask)];
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

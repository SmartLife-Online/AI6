<?php

namespace Tests\Feature\Git;

use App\AI6\Auth\Models\User;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationRecoveryProcessor;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Git\DeployKeyProvisioner;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\ProjectEffectLockName;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\ProjectRole;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\ProcessConfiguration;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Feature\Auth\AuthFeatureTestCase;

abstract class ControlOperationTestCase extends AuthFeatureTestCase
{
    private ?string $realWorkerRoot = null;

    private string|false|null $previousHeartbeatDirectory = null;

    protected function registeredProject(User $administrator): Project
    {
        $project = Project::query()->create([
            'name' => 'Control-Projekt',
            'remote' => 'git@git.example.test:acme/control.git',
            'control_branch' => 'refs/heads/main',
            'project_identifier' => str_repeat('a', 32),
            'host_key_fingerprint' => 'SHA256:'.rtrim(base64_encode(random_bytes(32)), '='),
            'provisioning_status' => ProjectProvisioningStatus::NOT_PROVISIONED,
        ]);
        $this->addMembership($administrator, $project, ProjectRole::ADMIN);

        return $project;
    }

    protected function configureRealWorkerRuntime(Project $project): string
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real deploy-key worker path requires the POSIX process and effect-lock runtime.');
        }

        $lockDirectory = getenv('AI6_EFFECT_LOCK_SECURITY_FIXTURE_DIRECTORY');
        $heartbeatDirectory = '/run/ai6/heartbeat/worker';
        if (! is_string($lockDirectory)
            || ! is_dir($lockDirectory)
            || ! is_file($lockDirectory.'/lock-0001')
            || ! is_file($heartbeatDirectory.'/boot-id')
            || ! is_file('/usr/bin/dash')
            || ! is_file('/usr/bin/ssh-keygen')) {
            self::markTestSkipped('The real worker proof requires the prepared effect-lock fixture and worker heartbeat identity.');
        }

        $lockStat = stat($lockDirectory);
        if (! is_array($lockStat)) {
            self::markTestSkipped('The effect-lock fixture identity is unavailable.');
        }

        $root = sys_get_temp_dir().'/ai6-worker-'.bin2hex(random_bytes(8));
        if (! mkdir($root, 0700, true) && ! is_dir($root)) {
            self::fail('The real worker test root could not be created.');
        }
        $this->realWorkerRoot = $root;
        $this->previousHeartbeatDirectory = getenv('AI6_HEARTBEAT_DIRECTORY');
        putenv('AI6_HEARTBEAT_DIRECTORY='.$heartbeatDirectory);

        $process = new ProcessConfiguration(
            30,
            1048576,
            100,
            5,
            base_path('app/AI6/Shared/Process/control-process-wrapper.sh'),
            '/usr/bin/dash',
            is_file('/usr/bin/setsid') ? '/usr/bin/setsid' : null,
            is_file('/usr/bin/kill') ? '/usr/bin/kill' : null,
            $lockDirectory,
            64,
            500,
            (int) $lockStat['uid'],
        );
        $operation = new ControlOperationConfiguration(
            $root,
            $root.'/deploy-keys',
            '/usr/bin/ssh-keygen',
            base_path('app/AI6/Git/generate-deploy-key.sh'),
            10,
            1,
            1,
            3,
        );
        $mapping = new ProjectEffectLockName($process);
        for ($index = 1; $index <= 10000; $index++) {
            $identifier = hash('md5', 'ai6-worker-project-'.$index);
            if ($mapping->forProject($identifier) === 'lock-0001') {
                $project->forceFill(['project_identifier' => $identifier])->save();
                break;
            }
        }
        self::assertSame('lock-0001', $mapping->forProject((string) $project->refresh()->project_identifier));

        $effectLock = new EffectLock($process);
        $lease = new ProjectOperationLease($operation);
        $this->app->instance(ProcessConfiguration::class, $process);
        $this->app->instance(ControlOperationConfiguration::class, $operation);
        $this->app->instance(EffectLock::class, $effectLock);
        $this->app->instance(ProjectEffectLockName::class, $mapping);
        $this->app->instance(ProjectOperationLease::class, $lease);
        $this->app->instance(ManagedProjectPath::class, new ManagedProjectPath($operation));
        $this->app->instance(
            ControlProcessRunner::class,
            new ControlProcessRunner($process, $this->app->make(Redactor::class), $effectLock),
        );
        config(['ai6.runtime_role' => 'worker']);
        foreach ([
            DeployKeyProvisioner::class,
            ControlOperationRecoveryProcessor::class,
            ControlOperationExecutor::class,
            ControlOperationRuntimeIdentity::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }

        return $root;
    }

    protected function generateTestKeyPair(string $directory, string $comment): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            self::fail('The deploy-key fixture directory could not be created.');
        }
        $process = new Process([
            '/usr/bin/ssh-keygen',
            '-q',
            '-t',
            'ed25519',
            '-N',
            '',
            '-C',
            $comment,
            '-f',
            $directory.'/id_ed25519',
        ]);
        $process->setTimeout(30);
        $process->mustRun();
        chmod($directory.'/id_ed25519', 0600);
        chmod($directory.'/id_ed25519.pub', 0644);
    }

    protected function tearDown(): void
    {
        if ($this->realWorkerRoot !== null) {
            (new Filesystem)->deleteDirectory($this->realWorkerRoot);
        }
        if ($this->previousHeartbeatDirectory !== null) {
            if ($this->previousHeartbeatDirectory === false) {
                putenv('AI6_HEARTBEAT_DIRECTORY');
            } else {
                putenv('AI6_HEARTBEAT_DIRECTORY='.$this->previousHeartbeatDirectory);
            }
        }

        parent::tearDown();
    }
}

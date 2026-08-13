<?php

namespace Tests\Feature\Git;

use App\AI6\Auth\Models\User;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationRecoveryProcessor;
use App\AI6\Git\GitConfiguration;
use App\AI6\Git\GitRemotePolicy;
use App\AI6\Git\HardenedGitEnvironment;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\KnownHostsVerifier;
use App\AI6\Git\ManagedCloneSynchronizer;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Git\TicketReadModelRefresher;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Shared\Process\ControlProcessRunner;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

trait BuildsManagedControlRuntimeFixture
{
    /**
     * @return array{
     *     administrator: User,
     *     project: Project,
     *     root: string,
     *     remote: string,
     *     source: string,
     *     first_oid: string,
     *     paths: ManagedProjectPath,
     *     runner: HardenedGitRunner,
     *     lease: ProjectOperationLease
     * }
     */
    protected function managedFixture(): array
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $root = $this->configureRealWorkerRuntime($project);
        $remote = $root.'/remote.git';
        $source = $root.'/source';
        self::assertTrue(mkdir($source, 0700));
        (new Process(['git', 'init', '--object-format=sha256', '--initial-branch=main'], $source))->mustRun();
        $this->managedFixtureGit(['config', 'user.name', 'AI6 Test'], $source);
        $this->managedFixtureGit(['config', 'user.email', 'ai6@example.invalid'], $source);
        self::assertTrue(mkdir($source.'/tickets', 0700));
        self::assertNotFalse(file_put_contents($source.'/ticket.md', "first\n"));
        self::assertNotFalse(file_put_contents($source.'/tickets/M169.md', "\xC3\x28"));
        self::assertNotFalse(file_put_contents($source.'/tickets/AI6-099.md', "read model\n"));
        $this->managedFixtureGit(['add', 'ticket.md', 'tickets/M169.md', 'tickets/AI6-099.md'], $source);
        $this->managedFixtureGit(['commit', '-m', 'first'], $source);
        $firstOid = trim($this->managedFixtureGit(['rev-parse', 'HEAD'], $source));
        self::assertTrue(mkdir($remote, 0700));
        $this->managedFixtureGit(['init', '--bare', '--object-format=sha256'], $remote);
        $this->managedFixtureGit(['push', $remote, 'refs/heads/main:refs/heads/main'], $source);

        $keyBytes = random_bytes(48);
        $fingerprint = 'SHA256:'.rtrim(base64_encode(hash('sha256', $keyBytes, true)), '=');
        $knownHosts = $root.'/known_hosts';
        self::assertNotFalse(file_put_contents($knownHosts, 'git.fixture.test ssh-ed25519 '.base64_encode($keyBytes)."\n"));
        self::assertTrue(chmod($knownHosts, 0444));
        $privateKey = $root.'/private-key';
        self::assertNotFalse(file_put_contents($privateKey, "test-only-key\n"));
        self::assertTrue(chmod($privateKey, 0400));
        $sshWrapper = $root.'/ssh-wrapper';
        $quotedRemote = escapeshellarg((string) realpath($remote));
        self::assertNotFalse(file_put_contents($sshWrapper, str_replace('__REMOTE__', $quotedRemote, <<<'SH'
#!/bin/sh
set -eu
[ "$#" -eq 2 ]
[ "$1" = "git@git.fixture.test" ]
case "$2" in
    "git-upload-pack 'acme/control.git'") exec git-upload-pack __REMOTE__ ;;
    "git-receive-pack 'acme/control.git'") exec git-receive-pack __REMOTE__ ;;
    *) exit 64 ;;
esac
SH)));
        self::assertTrue(chmod($sshWrapper, 0555));

        $gitHome = $root.'/git-home';
        $xdg = $gitHome.'/xdg';
        $hooks = $root.'/git-hooks';
        self::assertTrue(mkdir($xdg, 0700, true));
        self::assertTrue(chmod($gitHome, 0700));
        self::assertTrue(mkdir($hooks, 0555));
        $globalConfig = $gitHome.'/gitconfig';
        self::assertNotFalse(file_put_contents($globalConfig, "[credential]\n\thelper =\n"));
        self::assertTrue(chmod($globalConfig, 0444));
        $gitBinary = (new ExecutableFinder)->find('git');
        $sshBinary = (new ExecutableFinder)->find('ssh');
        $executablePath = getenv('PATH');
        self::assertIsString($gitBinary);
        self::assertIsString($sshBinary);
        self::assertIsString($executablePath);
        $gitConfiguration = new GitConfiguration(
            (string) realpath($gitBinary),
            (string) realpath($sshBinary),
            $executablePath,
            (string) realpath($sshWrapper),
            (string) realpath($gitHome),
            (string) realpath($xdg),
            (string) realpath($globalConfig),
            (string) realpath($hooks),
            ['git.fixture.test'],
            ['acme/control.git'],
            ['refs/heads/*'],
            ['git.fixture.test' => [$fingerprint]],
        );
        $operationConfiguration = new ControlOperationConfiguration(
            $root,
            $root.'/deploy-keys',
            '/usr/bin/ssh-keygen',
            base_path('app/AI6/Git/generate-deploy-key.sh'),
            10,
            1,
            1,
            3,
            (string) realpath($knownHosts),
            ['refs/heads/main', 'refs/heads/next', 'refs/heads/missing'],
            300,
            8,
        );
        $lease = new ProjectOperationLease($operationConfiguration);
        $paths = new ManagedProjectPath($operationConfiguration);
        $policy = new GitRemotePolicy($gitConfiguration, new KnownHostsVerifier);
        $runner = new HardenedGitRunner(
            $this->app->make(ControlProcessRunner::class),
            $policy,
            new HardenedGitEnvironment($gitConfiguration),
        );
        $this->app->instance(ControlOperationConfiguration::class, $operationConfiguration);
        $this->app->instance(ProjectOperationLease::class, $lease);
        $this->app->instance(ManagedProjectPath::class, $paths);
        $this->app->instance(GitConfiguration::class, $gitConfiguration);
        $this->app->instance(GitRemotePolicy::class, $policy);
        $this->app->instance(HardenedGitRunner::class, $runner);
        foreach ([
            ManagedCloneSynchronizer::class,
            TicketReadModelRefresher::class,
            ControlOperationRecoveryProcessor::class,
            ControlOperationExecutor::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }

        $project->forceFill([
            'remote' => 'git@git.fixture.test:acme/control.git',
            'host_key_fingerprint' => $fingerprint,
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => (string) realpath($privateKey),
            'public_deploy_key' => "ssh-ed25519 fixture\n",
        ])->save();

        return [
            'administrator' => $administrator,
            'project' => $project,
            'root' => $root,
            'remote' => $remote,
            'source' => $source,
            'first_oid' => $firstOid,
            'paths' => $paths,
            'runner' => $runner,
            'lease' => $lease,
        ];
    }

    /** @param list<string> $arguments */
    private function managedFixtureGit(array $arguments, string $directory): string
    {
        $process = new Process(['git', ...$arguments], $directory, [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
        ]);
        $process->mustRun();

        return $process->getOutput();
    }
}

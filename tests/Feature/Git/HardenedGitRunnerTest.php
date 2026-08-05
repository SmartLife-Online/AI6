<?php

namespace Tests\Feature\Git;

use App\AI6\Git\GitConfiguration;
use App\AI6\Git\GitRemotePolicy;
use App\AI6\Git\HardenedGitEnvironment;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\KnownHostsVerifier;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\ProcessConfiguration;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionPolicy;
use App\AI6\Shared\Redaction\RedactionRuleSet;
use App\AI6\Shared\Redaction\Redactor;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class HardenedGitRunnerTest extends TestCase
{
    private ?string $fixture = null;

    public function test_checkout_does_not_run_hooks_filters_textconv_pager_fsmonitor_credentials_or_submodules(): void
    {
        $root = $this->createFixtureRoot();
        $repository = $root.'/repository';
        mkdir($repository);
        $this->git(['init', '--initial-branch=main'], $repository);
        $this->git(['config', 'user.name', 'AI6 Test'], $repository);
        $this->git(['config', 'user.email', 'ai6@example.invalid'], $repository);

        file_put_contents($repository.'/.gitattributes', "payload.txt filter=evil diff=evil\n");
        file_put_contents($repository.'/payload.txt', "payload\n");
        $this->git(['add', '.gitattributes', 'payload.txt'], $repository);
        $this->git(['commit', '-m', 'fixture'], $repository);
        $head = trim($this->git(['rev-parse', 'HEAD'], $repository));

        file_put_contents($repository.'/.gitmodules', "[submodule \"nested\"]\n\tpath = nested\n\turl = ssh://git@unreachable.invalid/acme/nested.git\n");
        $this->git(['add', '.gitmodules'], $repository);
        $this->git(['update-index', '--add', '--cacheinfo', '160000,'.$head.',nested'], $repository);
        $this->git(['commit', '-m', 'submodule fixture'], $repository);

        $marker = $root.'/helper-was-run';
        $evilHooks = $root.'/evil-hooks';
        mkdir($evilHooks);
        foreach (['pre-commit', 'post-checkout', 'post-merge'] as $hook) {
            file_put_contents($evilHooks.'/'.$hook, "#!/bin/sh\nprintf helper > \"$marker\"\n");
            @chmod($evilHooks.'/'.$hook, 0755);
        }
        $this->git(['config', 'core.hooksPath', $evilHooks], $repository);
        $this->git(['config', 'core.fsmonitor', 'definitely-not-an-ai6-helper'], $repository);
        $this->git(['config', 'filter.evil.smudge', 'definitely-not-an-ai6-helper'], $repository);
        $this->git(['config', 'filter.evil.clean', 'definitely-not-an-ai6-helper'], $repository);
        $this->git(['config', 'filter.evil.required', 'true'], $repository);
        $this->git(['config', 'diff.evil.textconv', 'definitely-not-an-ai6-helper'], $repository);
        $this->git(['config', 'pager.status', 'definitely-not-an-ai6-helper'], $repository);
        $this->git(['config', 'credential.helper', 'definitely-not-an-ai6-helper'], $repository);

        unlink($repository.'/payload.txt');
        $inheritedConfig = $root.'/inherited-gitconfig';
        file_put_contents($inheritedConfig, "[core]\n\thooksPath = ".$evilHooks."\n[credential]\n\thelper = definitely-not-an-ai6-helper\n");
        putenv('GIT_CONFIG_SYSTEM='.$inheritedConfig);
        putenv('GIT_CONFIG_GLOBAL='.$inheritedConfig);
        try {
            $result = $this->runner($root)->checkout(
                $repository,
                'refs/heads/main',
                new RedactionContext('project-1', null, 'git-checkout-test'),
            );
        } finally {
            putenv('GIT_CONFIG_SYSTEM');
            putenv('GIT_CONFIG_GLOBAL');
        }

        self::assertTrue($result->succeeded(), $result->errorOutput);
        self::assertFileExists($repository.'/payload.txt');
        self::assertFileDoesNotExist($marker);
        self::assertSame([], is_dir($repository.'/nested') ? array_values(array_diff(scandir($repository.'/nested') ?: [], ['.', '..'])) : []);

        $signingHelper = $root.'/signing-helper';
        file_put_contents($signingHelper, "#!/bin/sh\nprintf signing > \"$marker\"\n");
        chmod($signingHelper, 0755);
        $this->git(['config', 'gpg.program', $signingHelper], $repository);
        $rejectedRuntime = $root.'/rejected-runtime';
        mkdir($rejectedRuntime);
        $rejected = $this->runner($rejectedRuntime)->checkout(
            $repository,
            'refs/heads/main',
            new RedactionContext('project-1', null, 'git-signing-rejection-test'),
        );
        self::assertFalse($rejected->succeeded());
        self::assertFileDoesNotExist($marker);

        $this->git(['config', '--unset', 'gpg.program'], $repository);
        $this->git(['config', 'core.askPass', $signingHelper], $repository);
        $askPassRuntime = $root.'/askpass-runtime';
        mkdir($askPassRuntime);
        $askPassRejected = $this->runner($askPassRuntime)->checkout(
            $repository,
            'refs/heads/main',
            new RedactionContext('project-1', null, 'git-askpass-rejection-test'),
        );
        self::assertFalse($askPassRejected->succeeded());
        self::assertFileDoesNotExist($marker);
    }

    public function test_worktree_configuration_is_rejected_before_repository_helpers_can_run(): void
    {
        $root = $this->createFixtureRoot();
        $repository = $root.'/repository';
        mkdir($repository);
        $this->git(['init', '--initial-branch=main'], $repository);
        $this->git(['config', 'user.name', 'AI6 Test'], $repository);
        $this->git(['config', 'user.email', 'ai6@example.invalid'], $repository);
        file_put_contents($repository.'/payload.txt', "payload\n");
        $this->git(['add', 'payload.txt'], $repository);
        $this->git(['commit', '-m', 'fixture'], $repository);

        $marker = $root.'/worktree-helper-was-run';
        $helper = $root.'/worktree-helper';
        file_put_contents($helper, "#!/bin/sh\nprintf helper > \"$marker\"\n");
        chmod($helper, 0755);
        $this->git(['config', 'extensions.worktreeConfig', 'true'], $repository);
        $this->git(['config', '--worktree', 'core.sshCommand', $helper], $repository);

        $result = $this->runner($root.'/runtime')->checkout(
            $repository,
            'refs/heads/main',
            new RedactionContext('project-1', null, 'git-worktree-config-test'),
        );

        self::assertFalse($result->succeeded());
        self::assertSame('Repository Git configuration was rejected.', $result->errorOutput);
        self::assertFileDoesNotExist($marker);
    }

    public function test_failed_git_process_output_is_centrally_redacted(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The executable Git failure fixture requires the Linux runtime.');
        }

        $root = $this->createFixtureRoot();
        $repository = $root.'/repository';
        mkdir($repository);
        $fakeGit = $root.'/git-failure';
        file_put_contents($fakeGit, <<<'SH'
#!/usr/bin/dash
printf 'secret=super-secret\n' >&2
exit 2
SH);
        chmod($fakeGit, 0555);
        $runtime = $root.'/runtime';
        mkdir($runtime);

        $result = $this->runner($runtime, gitBinary: $fakeGit)->checkout(
            $repository,
            'refs/heads/main',
            new RedactionContext('project-1', null, 'git-redaction-test'),
        );

        self::assertFalse($result->succeeded());
        self::assertStringContainsString('[REDACTED:SECRET]', $result->errorOutput);
        self::assertStringNotContainsString('super-secret', $result->errorOutput);
    }

    public function test_clone_fetch_and_checkout_use_only_the_pinned_ssh_transport_without_repository_helpers(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The fixed SSH transport integration requires the Linux runtime.');
        }

        $root = $this->createFixtureRoot();
        $source = $root.'/source';
        mkdir($source);
        $this->git(['init', '--initial-branch=main'], $source);
        $this->git(['config', 'user.name', 'AI6 Test'], $source);
        $this->git(['config', 'user.email', 'ai6@example.invalid'], $source);
        file_put_contents($source.'/.gitattributes', "payload.txt filter=evil diff=evil\n");
        file_put_contents($source.'/payload.txt', "payload\n");
        file_put_contents($source.'/.gitmodules', "[submodule \"nested\"]\n\tpath = nested\n\turl = ssh://git@unreachable.invalid/acme/nested.git\n");
        $this->git(['add', '.gitattributes', '.gitmodules', 'payload.txt'], $source);
        $this->git(['commit', '-m', 'fixture'], $source);
        $head = trim($this->git(['rev-parse', 'HEAD'], $source));
        $this->git(['update-index', '--add', '--cacheinfo', '160000,'.$head.',nested'], $source);
        $this->git(['commit', '-m', 'submodule fixture'], $source);

        $marker = $root.'/helper-was-run';
        $runner = $this->runner($root, $source);
        $context = new RedactionContext('project-1', null, 'git-transport-test');
        $remote = 'git@git.fixture.test:acme/project.git';
        putenv('GIT_SSH_COMMAND=definitely-not-an-ai6-helper');
        putenv('SSH_AUTH_SOCK='.$root.'/untrusted-agent.sock');
        try {
            $clone = $runner->clone(
                $remote,
                'refs/heads/main',
                'managed',
                $root,
                $this->canonical($root.'/private-key'),
                $this->canonical($root.'/known-hosts'),
                $context,
            );
        } finally {
            putenv('GIT_SSH_COMMAND');
            putenv('SSH_AUTH_SOCK');
        }
        self::assertTrue($clone->succeeded(), $clone->errorOutput);
        $managed = $root.'/managed';
        self::assertFileDoesNotExist($managed.'/payload.txt');
        self::assertDirectoryDoesNotExist($managed.'/nested');
        $localHeadBeforeFetch = trim($this->git(['rev-parse', 'refs/heads/main'], $managed));

        file_put_contents($source.'/fetched-only.txt', "fetched\n");
        $this->git(['add', 'fetched-only.txt'], $source);
        $this->git(['commit', '-m', 'post-clone fixture'], $source);
        $fetchedHead = trim($this->git(['rev-parse', 'HEAD'], $source));
        self::assertNotSame($localHeadBeforeFetch, $fetchedHead);

        $hooks = $managed.'/.git/hooks';
        foreach (['pre-commit', 'post-checkout', 'post-merge'] as $hook) {
            file_put_contents($hooks.'/'.$hook, "#!/bin/sh\nprintf helper > \"$marker\"\n");
            chmod($hooks.'/'.$hook, 0755);
        }
        foreach ([
            ['core.fsmonitor', 'definitely-not-an-ai6-helper'],
            ['filter.evil.smudge', 'definitely-not-an-ai6-helper'],
            ['filter.evil.clean', 'definitely-not-an-ai6-helper'],
            ['filter.evil.required', 'true'],
            ['diff.evil.textconv', 'definitely-not-an-ai6-helper'],
            ['pager.status', 'definitely-not-an-ai6-helper'],
            ['credential.helper', 'definitely-not-an-ai6-helper'],
        ] as [$key, $value]) {
            $this->git(['config', $key, $value], $managed);
        }

        $fetch = $runner->fetch(
            $remote,
            'refs/heads/main',
            $managed,
            $this->canonical($root.'/private-key'),
            $this->canonical($root.'/known-hosts'),
            $context,
        );
        self::assertTrue($fetch->succeeded(), $fetch->errorOutput);
        self::assertSame($fetchedHead, trim($this->git(['rev-parse', 'FETCH_HEAD'], $managed)));
        self::assertSame($localHeadBeforeFetch, trim($this->git(['rev-parse', 'refs/heads/main'], $managed)));
        $checkout = $runner->checkout($managed, 'refs/heads/main', $context);
        self::assertTrue($checkout->succeeded(), $checkout->errorOutput);
        self::assertFileExists($managed.'/payload.txt');
        self::assertFileDoesNotExist($managed.'/fetched-only.txt');
        self::assertFileDoesNotExist($marker);
        self::assertSame([], is_dir($managed.'/nested') ? array_values(array_diff(scandir($managed.'/nested') ?: [], ['.', '..'])) : []);
    }

    #[After]
    public function removeFixture(): void
    {
        if ($this->fixture !== null && is_dir($this->fixture)) {
            $this->removeTree($this->fixture);
        }
        $this->fixture = null;
    }

    private function runner(string $root, ?string $remoteRepository = null, ?string $gitBinary = null): HardenedGitRunner
    {
        if (! is_dir($root)) {
            mkdir($root);
        }
        $home = $root.'/git-home';
        $xdg = $home.'/xdg';
        $hooks = $root.'/sealed-hooks';
        mkdir($xdg, 0700, true);
        chmod($home, 0700);
        mkdir($hooks, 0555);
        $globalConfig = $home.'/gitconfig';
        file_put_contents($globalConfig, "[credential]\n\thelper =\n");
        chmod($globalConfig, 0444);
        $allowedHosts = [];
        $allowedPaths = [];
        $fingerprints = [];
        $sshWrapper = $root.'/ssh-wrapper';
        if ($remoteRepository === null) {
            file_put_contents($sshWrapper, "wrapper\n");
            chmod($sshWrapper, 0555);
            if (DIRECTORY_SEPARATOR === '\\') {
                $sshWrapper = PHP_BINARY;
            }
        } else {
            $key = random_bytes(48);
            $fingerprint = 'SHA256:'.rtrim(base64_encode(hash('sha256', $key, true)), '=');
            file_put_contents($root.'/known-hosts', 'git.fixture.test ssh-ed25519 '.base64_encode($key)."\n");
            file_put_contents($root.'/private-key', "test-only-key\n");
            chmod($root.'/private-key', 0600);
            $quotedRepository = escapeshellarg($this->canonical($remoteRepository));
            $wrapper = strtr(<<<'SH'
#!/bin/sh
set -eu
[ "$#" -eq 2 ]
[ "$1" = "git@git.fixture.test" ]
[ "$2" = "git-upload-pack 'acme/project.git'" ]
exec git-upload-pack __REMOTE_REPOSITORY__
SH, ['__REMOTE_REPOSITORY__' => $quotedRepository]);
            file_put_contents($sshWrapper, $wrapper);
            chmod($sshWrapper, 0555);
            $allowedHosts = ['git.fixture.test'];
            $allowedPaths = ['acme/project.git'];
            $fingerprints = ['git.fixture.test' => [$fingerprint]];
        }

        $gitBinary ??= (new ExecutableFinder)->find('git');
        self::assertIsString($gitBinary);
        $sshBinary = (new ExecutableFinder)->find('ssh');
        self::assertIsString($sshBinary);
        $executablePath = getenv('PATH');
        self::assertIsString($executablePath);
        $git = new GitConfiguration(
            $this->canonical($gitBinary),
            $this->canonical($sshBinary),
            $executablePath,
            $this->canonical($sshWrapper),
            $this->canonical($home),
            $this->canonical($xdg),
            $this->canonical($globalConfig),
            $this->canonical($hooks),
            $allowedHosts,
            $allowedPaths,
            ['refs/heads/*'],
            $fingerprints,
        );
        $processConfiguration = new ProcessConfiguration(
            15,
            1048576,
            500,
            5,
            dirname(__DIR__, 3).'/app/AI6/Shared/Process/control-process-wrapper.sh',
            '/bin/sh',
            DIRECTORY_SEPARATOR === '/' ? '/usr/bin/setsid' : null,
            DIRECTORY_SEPARATOR === '/' ? '/usr/bin/kill' : null,
            $root.'/missing-locks',
            1,
            100,
            0,
        );
        $redactor = $this->redactor();
        $control = new ControlProcessRunner($processConfiguration, $redactor, new EffectLock($processConfiguration));
        $policy = new GitRemotePolicy($git, new KnownHostsVerifier);

        return new HardenedGitRunner($control, $policy, new HardenedGitEnvironment($git));
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, string $directory): string
    {
        $process = new Process(['git', ...$arguments], $directory, [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
        ]);
        $process->mustRun();

        return $process->getOutput();
    }

    private function redactor(): Redactor
    {
        $ring = new RedactionKeyring('test-v1', [
            'test-v1' => ['version' => 1, 'key' => str_repeat('g', 32)],
        ]);

        return new Redactor(
            new RedactionPolicy(RedactionRuleSet::defaults()),
            new RedactionFingerprintGenerator($ring),
        );
    }

    private function createFixtureRoot(): string
    {
        $path = sys_get_temp_dir().'/ai6-hardened-git-'.bin2hex(random_bytes(6));
        mkdir($path, 0700);
        $this->fixture = strtr($path, '\\', '/');

        return $this->fixture;
    }

    private function canonical(string $path): string
    {
        $real = realpath($path);
        self::assertIsString($real);

        return $real;
    }

    private function removeTree(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                @chmod($item->getPathname(), 0700);
                rmdir($item->getPathname());
            } else {
                @chmod($item->getPathname(), 0600);
                unlink($item->getPathname());
            }
        }
        @chmod($path, 0700);
        rmdir($path);
    }
}

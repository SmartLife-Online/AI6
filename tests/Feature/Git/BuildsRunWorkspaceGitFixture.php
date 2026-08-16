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
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionPolicy;
use App\AI6\Shared\Redaction\RedactionRuleSet;
use App\AI6\Shared\Redaction\Redactor;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A local, remote-free SHA-256 fixture for the run workspace seam.
 *
 * The runner is the real hardened runner with the real process boundary, so every proof below
 * exercises the same execution seam the worker uses.
 */
trait BuildsRunWorkspaceGitFixture
{
    private ?string $runWorkspaceFixture = null;

    protected function runWorkspaceRoot(): string
    {
        if ($this->runWorkspaceFixture === null) {
            $path = sys_get_temp_dir().'/ai6-run-workspace-'.bin2hex(random_bytes(6));
            self::assertTrue(mkdir($path, 0700));
            $this->runWorkspaceFixture = strtr((string) realpath($path), '\\', '/');
        }

        return $this->runWorkspaceFixture;
    }

    protected function removeRunWorkspaceFixture(): void
    {
        if ($this->runWorkspaceFixture !== null && is_dir($this->runWorkspaceFixture)) {
            $this->removeRunWorkspaceTree($this->runWorkspaceFixture);
        }
        $this->runWorkspaceFixture = null;
    }

    protected function runWorkspaceRunner(string $root): HardenedGitRunner
    {
        $home = $root.'/git-home';
        $xdg = $home.'/xdg';
        $hooks = $root.'/sealed-hooks';
        self::assertTrue(mkdir($xdg, 0700, true));
        self::assertTrue(chmod($home, 0700));
        self::assertTrue(mkdir($hooks, 0555));
        $globalConfig = $home.'/gitconfig';
        self::assertNotFalse(file_put_contents($globalConfig, "[credential]\n\thelper =\n"));
        self::assertTrue(chmod($globalConfig, 0444));
        $sshWrapper = $root.'/ssh-wrapper';
        self::assertNotFalse(file_put_contents($sshWrapper, "wrapper\n"));
        self::assertTrue(chmod($sshWrapper, 0555));
        if (DIRECTORY_SEPARATOR === '\\') {
            $sshWrapper = PHP_BINARY;
        }

        $gitBinary = (new ExecutableFinder)->find('git');
        $sshBinary = (new ExecutableFinder)->find('ssh');
        $executablePath = getenv('PATH');
        self::assertIsString($gitBinary);
        self::assertIsString($sshBinary);
        self::assertIsString($executablePath);

        $git = new GitConfiguration(
            (string) realpath($gitBinary),
            (string) realpath($sshBinary),
            $executablePath,
            (string) realpath($sshWrapper),
            (string) realpath($home),
            (string) realpath($xdg),
            (string) realpath($globalConfig),
            (string) realpath($hooks),
            [],
            [],
            ['refs/heads/*'],
            [],
        );
        $process = new ProcessConfiguration(
            30,
            1048576,
            500,
            5,
            base_path('app/AI6/Shared/Process/control-process-wrapper.sh'),
            '/bin/sh',
            DIRECTORY_SEPARATOR === '/' ? '/usr/bin/setsid' : null,
            DIRECTORY_SEPARATOR === '/' ? '/usr/bin/kill' : null,
            $root.'/missing-locks',
            1,
            100,
            0,
        );
        $ring = new RedactionKeyring('test-v1', ['test-v1' => ['version' => 1, 'key' => str_repeat('g', 32)]]);
        $redactor = new Redactor(
            new RedactionPolicy(RedactionRuleSet::defaults()),
            new RedactionFingerprintGenerator($ring),
        );

        return new HardenedGitRunner(
            new ControlProcessRunner($process, $redactor, new EffectLock($process)),
            new GitRemotePolicy($git, new KnownHostsVerifier),
            new HardenedGitEnvironment($git),
        );
    }

    /**
     * Build a SHA-256 repository with one commit and return its path and commit OID.
     *
     * @return array{0: string, 1: string}
     */
    protected function runWorkspaceRepository(string $root, string $name = 'repository'): array
    {
        $repository = $root.'/'.$name;
        self::assertTrue(mkdir($repository, 0700));
        $this->runWorkspaceGit(['init', '--object-format=sha256', '--initial-branch=main'], $repository);
        $this->runWorkspaceGit(['config', 'user.name', 'AI6 Test'], $repository);
        $this->runWorkspaceGit(['config', 'user.email', 'ai6@example.invalid'], $repository);
        self::assertNotFalse(file_put_contents($repository.'/a.txt', "first\n"));
        self::assertTrue(mkdir($repository.'/sub', 0700));
        self::assertNotFalse(file_put_contents($repository.'/sub/b.txt', "nested\n"));
        $this->runWorkspaceGit(['add', '-A'], $repository);
        $this->runWorkspaceGit(['commit', '-m', 'first'], $repository);

        return [$repository, trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository))];
    }

    /** @param list<string> $arguments */
    protected function runWorkspaceGit(array $arguments, string $directory): string
    {
        $process = new Process(['git', ...$arguments], $directory, [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_AUTHOR_NAME' => 'AI6 Test',
            'GIT_AUTHOR_EMAIL' => 'ai6@example.invalid',
            'GIT_COMMITTER_NAME' => 'AI6 Test',
            'GIT_COMMITTER_EMAIL' => 'ai6@example.invalid',
        ]);
        $process->mustRun();

        return $process->getOutput();
    }

    private function removeRunWorkspaceTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }
            $child = $path.'/'.$entry;
            @chmod($child, is_dir($child) && ! is_link($child) ? 0700 : 0600);
            if (is_dir($child) && ! is_link($child)) {
                $this->removeRunWorkspaceTree($child);
            } else {
                @unlink($child);
            }
        }
        @chmod($path, 0700);
        @rmdir($path);
    }
}

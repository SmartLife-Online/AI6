<?php

namespace Tests\Unit\Git;

use App\AI6\Git\GitConfiguration;
use App\AI6\Git\HardenedGitEnvironment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GitHardeningContractTest extends TestCase
{
    public function test_the_environment_is_isolated_and_external_helpers_are_disabled(): void
    {
        $root = $this->fixtureDirectory();
        $home = $root.'/home';
        $xdg = $home.'/xdg';
        $hooks = $root.'/hooks';
        mkdir($xdg, 0700, true);
        chmod($home, 0700);
        mkdir($hooks, 0555);
        $global = $root.'/gitconfig';
        $wrapper = $root.'/ssh-wrapper';
        $binary = $root.'/git';
        $sshBinary = $root.'/ssh';
        file_put_contents($global, "[credential]\n\thelper =\n");
        file_put_contents($wrapper, "wrapper\n");
        file_put_contents($binary, "binary\n");
        file_put_contents($sshBinary, "binary\n");
        chmod($global, 0444);
        chmod($wrapper, 0555);
        chmod($binary, 0555);
        chmod($sshBinary, 0555);
        $runtimeWrapper = DIRECTORY_SEPARATOR === '/' ? $wrapper : PHP_BINARY;
        $runtimeGitBinary = DIRECTORY_SEPARATOR === '/' ? $binary : PHP_BINARY;
        $runtimeSshBinary = DIRECTORY_SEPARATOR === '/' ? $sshBinary : PHP_BINARY;

        try {
            $environment = new HardenedGitEnvironment(new GitConfiguration(
                realpath($runtimeGitBinary) ?: $runtimeGitBinary,
                realpath($runtimeSshBinary) ?: $runtimeSshBinary,
                '/trusted/bin',
                realpath($runtimeWrapper) ?: $runtimeWrapper,
                realpath($home) ?: $home,
                realpath($xdg) ?: $xdg,
                realpath($global) ?: $global,
                realpath($hooks) ?: $hooks,
                [],
                [],
                ['refs/heads/*'],
                [],
            ));
            $variables = $environment->variables();
            $command = $environment->commandPrefix();

            self::assertSame('1', $variables['GIT_CONFIG_NOSYSTEM']);
            self::assertSame('/trusted/bin', $variables['PATH']);
            self::assertSame(realpath($runtimeSshBinary), $variables['AI6_GIT_SSH_BINARY']);
            self::assertSame('', $variables['GIT_PAGER']);
            self::assertSame('', $variables['GIT_EXTERNAL_DIFF']);
            self::assertArrayNotHasKey('GIT_SSH_COMMAND', $variables);
            self::assertArrayNotHasKey('SSH_AUTH_SOCK', $variables);
            self::assertContains('core.fsmonitor=false', $command);
            self::assertContains('credential.helper=', $command);
            self::assertContains('protocol.allow=never', $command);
            self::assertContains('protocol.ssh.allow=always', $command);
            self::assertContains('submodule.recurse=false', $command);

            if (DIRECTORY_SEPARATOR === '/') {
                $foreignWrapper = new HardenedGitEnvironment(new GitConfiguration(
                    realpath($binary) ?: $binary,
                    realpath($sshBinary) ?: $sshBinary,
                    '/trusted/bin',
                    '/usr/bin/dash',
                    realpath($home) ?: $home,
                    realpath($xdg) ?: $xdg,
                    realpath($global) ?: $global,
                    realpath($hooks) ?: $hooks,
                    [],
                    [],
                    ['refs/heads/*'],
                    [],
                ));
                self::assertSame('/usr/bin/dash', $foreignWrapper->variables()['GIT_SSH']);

                chmod($wrapper, 0444);
                try {
                    $environment->variables();
                    self::fail('A non-executable Git SSH wrapper must be rejected before Git starts.');
                } catch (\InvalidArgumentException $exception) {
                    self::assertStringContainsString('not executable', $exception->getMessage());
                } finally {
                    chmod($wrapper, 0555);
                }

                foreach ([
                    $binary => 'Git binary',
                    $sshBinary => 'Git SSH binary',
                ] as $runtimeBinary => $name) {
                    chmod($runtimeBinary, 0755);
                    try {
                        $environment->variables();
                        self::fail($name.' must be rejected when it is writable by the runtime identity.');
                    } catch (\InvalidArgumentException $exception) {
                        self::assertStringContainsString($name, $exception->getMessage());
                        self::assertStringContainsString('runtime identity', $exception->getMessage());
                    } finally {
                        chmod($runtimeBinary, 0555);
                    }
                }
            }
        } finally {
            chmod($hooks, 0700);
            chmod($global, 0600);
            chmod($wrapper, 0600);
            chmod($binary, 0600);
            chmod($sshBinary, 0600);
            unlink($global);
            unlink($wrapper);
            unlink($binary);
            unlink($sshBinary);
            rmdir($hooks);
            rmdir($xdg);
            rmdir($home);
            rmdir($root);
        }
    }

    public function test_the_ssh_wrapper_has_a_strict_non_forwarding_contract(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/bin/ai6-git-ssh.sh');
        self::assertIsString($contents);

        foreach ([
            'ssh_binary=${AI6_GIT_SSH_BINARY:-/usr/bin/ssh}',
            'exec "$ssh_binary"',
            '-F /dev/null',
            'StrictHostKeyChecking=yes',
            'UserKnownHostsFile=$AI6_GIT_KNOWN_HOSTS',
            'IdentitiesOnly=yes',
            'IdentityAgent=none',
            'ForwardAgent=no',
            'ClearAllForwardings=yes',
            'ProxyCommand=none',
            'GlobalKnownHostsFile=/dev/null',
            'unset GIT_SSH_COMMAND GIT_SSH SSH_AUTH_SOCK',
        ] as $required) {
            self::assertStringContainsString($required, $contents);
        }
    }

    public function test_the_real_ssh_wrapper_uses_only_the_allowlisted_binary_and_strips_inherited_helpers(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! is_file('/usr/bin/dash')) {
            self::markTestSkipped('The real SSH wrapper proof requires the Linux runtime.');
        }

        $root = $this->fixtureDirectory();
        $key = $root.'/key';
        $knownHosts = $root.'/known-hosts';
        $ssh = $root.'/ssh';
        file_put_contents($key, "test-only-key\n");
        file_put_contents($knownHosts, "git.example.test ssh-ed25519 test-only-key\n");
        file_put_contents($ssh, <<<'SH'
#!/bin/sh
set -eu
printf 'stub-ssh\n'
printf 'GIT_SSH_COMMAND=%s\n' "${GIT_SSH_COMMAND-unset}"
printf 'GIT_SSH=%s\n' "${GIT_SSH-unset}"
printf 'SSH_AUTH_SOCK=%s\n' "${SSH_AUTH_SOCK-unset}"
printf '%s\n' "$@"
SH);
        chmod($key, 0600);
        chmod($knownHosts, 0444);
        chmod($ssh, 0555);

        try {
            $wrapper = dirname(__DIR__, 3).'/bin/ai6-git-ssh.sh';
            $process = new Process(
                ['/usr/bin/dash', $wrapper, 'git@git.example.test', "git-upload-pack 'acme/project.git'"],
                $root,
                [
                    'AI6_GIT_SSH_BINARY' => realpath($ssh) ?: $ssh,
                    'AI6_GIT_SSH_KEY' => realpath($key) ?: $key,
                    'AI6_GIT_KNOWN_HOSTS' => realpath($knownHosts) ?: $knownHosts,
                    'GIT_SSH_COMMAND' => 'untrusted-command',
                    'GIT_SSH' => 'untrusted-wrapper',
                    'SSH_AUTH_SOCK' => 'untrusted-agent',
                ],
            );
            $process->mustRun();
            $output = $process->getOutput();

            self::assertStringStartsWith("stub-ssh\n", $output);
            self::assertStringContainsString("GIT_SSH_COMMAND=unset\n", $output);
            self::assertStringContainsString("GIT_SSH=unset\n", $output);
            self::assertStringContainsString("SSH_AUTH_SOCK=unset\n", $output);
            self::assertStringContainsString("StrictHostKeyChecking=yes\n", $output);
            self::assertStringContainsString('UserKnownHostsFile='.(realpath($knownHosts) ?: $knownHosts)."\n", $output);
            self::assertStringNotContainsString('untrusted-', $output);
        } finally {
            chmod($knownHosts, 0600);
            chmod($ssh, 0600);
            unlink($key);
            unlink($knownHosts);
            unlink($ssh);
            rmdir($root);
        }
    }

    public function test_git_module_uses_the_central_redactor_without_defining_redaction_rules(): void
    {
        $root = dirname(__DIR__, 3).'/app/AI6/Git';
        foreach (glob($root.'/*.php') ?: [] as $path) {
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            foreach (['RedactionRule', 'REDACTED:', 'secret-assignment', 'uri-credential'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $contents, basename($path));
            }
            self::assertStringNotContainsString('new Process(', $contents, basename($path));
            self::assertStringNotContainsString('proc_open(', $contents, basename($path));
        }
    }

    private function fixtureDirectory(): string
    {
        $path = sys_get_temp_dir().'/ai6-git-environment-'.bin2hex(random_bytes(6));
        mkdir($path, 0700);

        return strtr($path, '\\', '/');
    }
}
